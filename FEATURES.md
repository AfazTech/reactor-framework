# Bot development features

These APIs are available on the development branch. A production application
must install a framework revision containing them. They use the existing Amp
runtime; asynchronous I/O suspends a Fiber rather than blocking the event loop.

## 1. Conversations / flows

Register definitions in your application service provider's `boot()` method:

```php
use Reactor\Conversation\ConversationManager;
use Reactor\Conversation\Flow;

$flows = $container->get(ConversationManager::class);
$flows->define(
    (new Flow('signup', ttl: 900))
        ->ask('name', 'Your name?', fn (string $v) => mb_strlen($v) >= 2)
        ->ask('age', 'Your age?', fn (string $v) => ctype_digit($v), error: 'Use digits.')
);
```

Inject `ConversationManager` into a handler, start a flow, and send its first prompt:

```php
$result = $flows->start($userId, 'signup');
$client->sendMessage($chatId, $result->prompt)->await();
```

The skeleton's `ConversationMiddleware` processes subsequent text, `/back` and
`/cancel`. Active flows intercept text before the matched handler executes.
Validators return `true` or a `Future<bool>`; validation failures keep the current
step. Completion dispatches an event:

```php
use Reactor\Events;
use Reactor\Core\EventDispatcher;

$container->get(EventDispatcher::class)->on(
    Events::CONVERSATION_COMPLETED,
    function (int $userId, string $flow, array $answers) use ($client) {
        $client->sendMessage($userId, 'Saved: ' . $answers['name'])->await();
    }
);
```

State is stored in the configured `CacheInterface`; definitions and validation
closures must be registered on each boot. The default file cache survives a
process restart. A flow's absolute TTL starts at `start()`. Choose a persistent
cache backend for multi-process deployments. Flow state is separate from the
legacy user `step` / `temp` API; both remain available. Completion is not an
exactly-once transaction with your business database: make final effects
idempotent where needed.

Standalone applications can call `accept()`, `back()`, `cancel()` and `active()`
directly and handle `ConversationResult` themselves instead of installing the
skeleton middleware.

## 2. Ordered updates

The update pipeline automatically serializes updates for the same Telegram user.
If no user is available, it uses the chat where possible. Different users can
progress concurrently. Nested processing on the same Fiber is reentrant, and
locks are released after errors. Idle keys are removed from memory.

This guarantee is **within one process**. Route each user consistently to one
worker/replica if running multiple polling processes. A local mutex does not
provide distributed ordering.

The container also shares concurrent singleton construction and isolates its
resolution stacks and scoped instances per Fiber. `forgetScopedInstances()`
resets the current Fiber's explicit container scope; update-local repository
caching uses its own nested `UpdateScope` frames.

## 3. Incoming rate limits

```php
use Reactor\Attributes\Text;
use Reactor\Attributes\Throttle;

#[Text(name: '/report', isCommand: true)]
#[Throttle(limit: 5, seconds: 10, key: 'user')]
class ReportHandler extends \App\Handlers\BaseHandler
{
    protected function handle(array $params = []): void
    {
        $this->reply('Report requested');
    }
}
```

`key` is `user`, `chat`, or `handler` (one shared handler bucket). Limits are per
handler. Rejected updates emit `Events::UPDATE_THROTTLED` with the update and
retry-after seconds. Callback queries are acknowledged; rejected message updates
do not send an automatic reply, preventing a reply flood. Register a listener if
you want custom feedback. This limits incoming handler invocations; it does not
replace Neili's Telegram API rate-limit handling.

The default backend is bounded process-local memory. For shared limits:

```dotenv
THROTTLE_DRIVER=redis
THROTTLE_REDIS_URI=redis://127.0.0.1:6379
```

The Redis backend uses one Lua operation for the counter and expiry. Memory
limits reset when the process restarts. At capacity the memory backend fails
closed instead of evicting an active bucket and allowing a bypass.

## 4. Short, user-bound callback payloads

```php
use Reactor\Callbacks\CallbackStore;
use Neili\KeyboardBuilder;

$callbacks = $container->get(CallbackStore::class);
$token = $callbacks->issue('order.view', ['id' => 42], userId: $userId, ttl: 300);
$keyboard = (new KeyboardBuilder())->inlineButtonRow([
    ['text' => 'View order', 'callback_data' => $token],
])->build();
$client->sendMessage($chatId, 'Your order', $keyboard)->await();
```

```php
#[\Reactor\Attributes\Callback('order.view')]
class ViewOrderHandler extends \App\Handlers\BaseHandler
{
    protected function handle(array $params = []): void
    {
        $orderId = $this->callbackPayload()['id'];
        // Still authorize access to this order before showing it.
        $this->send('Order: ' . $orderId);
    }
}
```

Tokens are 36-byte opaque random references, under Telegram's 64-byte limit.
Payloads stay server-side. The pipeline resolves them before routing, rejects
expired/wrong-user tokens, and exposes the original token under
`$update['reactor']['callback_token']`. `revoke($token)` invalidates a button.
Tokens are reusable until expiry/revocation; they are not single-use nonces.
Leaving `userId` null deliberately allows any user. Both callback and flow state
are namespaced per bot; `features.state_namespace` can explicitly override it.
Use a shared persistent cache when callbacks must work across replicas.

## 5. Bot testing without Telegram credentials

```php
use Reactor\Testing\BotTestHarness;
use Reactor\Testing\UpdateFactory;

// $app is a test App configured with a dummy token and temporary database.
$bot = new BotTestHarness($app);
$bot->receive(UpdateFactory::message(42, '/start'));
$bot->assertHandledBy(\App\Handlers\StartHandler::class);
$bot->telegram->assertSent('sendMessage', fn (array $p) => $p['chat_id'] === 42);
$bot->assertStep(42, null);
```

`FakeTelegram` injects an in-memory JSON HTTP transport into the real Neili client.
It never opens a network connection and never records token-bearing URLs. Use
`respond($method, $result)` or `fail($method, $description, $code)` to simulate API
results. Requests are drained after each harness update. These helpers require
PHPUnit and support JSON API requests; they are not multipart upload emulators.

## 6. Explicit queued listeners

```php
use Reactor\Attributes\QueuedListener;
use Reactor\Contracts\QueuedEventListenerInterface;
use Reactor\Events;

#[QueuedListener(Events::USER_REGISTERED, queue: 'notifications')]
class SendWelcome implements QueuedEventListenerInterface
{
    public function __construct(private \Neili\Client $client) {}

    public function handle(string $event, array $arguments): void
    {
        $this->client->sendMessage($arguments[0], 'Welcome!')->await();
    }
}

$container->get(\Reactor\Core\EventDispatcher::class)->subscribe(SendWelcome::class);
```

```bash
php reactor queue:work --queue=notifications
```

Local listeners remain synchronous control-flow listeners; they must await their own async operations. Returning a Future does not defer propagation. A local listener
returning `false` stops propagation and prevents the queued listeners from being
enqueued. Queued listeners run only in the queue worker, and cannot stop the
original update. Their arguments must be JSON-compatible scalars/arrays/null
and fit within 64 KiB; services, exceptions and arbitrary objects are rejected.
Worker retry/timeout/failed-job policies apply. Delivery is at least once;
listeners with side effects should be idempotent.

## 7. Diagnostics and metrics

```bash
php reactor doctor
php reactor doctor --json
php reactor doctor --telegram  # explicit, read-only getMe API check
php reactor metrics
```

The skeleton boots `doctor` and `metrics` without normal bot/database startup so
diagnostics can still run with a broken database configuration. Doctor checks
PHP/extensions, storage, async SQL, the configured queue table, and cache.
Failed checks produce a nonzero exit status. Error details that could include
credentials or token-bearing URLs are not printed.

Polling samples loop lag and exports a snapshot to
`storage/framework/metrics.json` once per second using async file operations.
The snapshot includes PID, timestamp, update outcomes/duration, in-flight updates,
query counts/failures/duration, memory and loop lag. Update duration includes time
waiting for same-user ordering. `metrics` reads that polling snapshot, not the
new CLI process's empty counters, and reports its age. Inspect the timestamp/PID
before treating a snapshot as current. These aggregates do not use user IDs as
metric labels.

Programmatic access:

```php
$snapshot = $container->get(\Reactor\Diagnostics\RuntimeMetrics::class)->snapshot();
```

The skeleton instruments `AsyncDatabaseManagerInterface` through
`MeasuredDatabase`. Direct Eloquent operations are not counted by that wrapper.
The optional metrics exporter must not be used as a dependency for core bot
processing; storage/export errors do not terminate polling.

## Validation

```bash
# Framework unit/regression suite
php vendor/bin/phpunit

# Real local Redis integration (explicit opt-in, separate from unit tests)
REACTOR_TEST_REDIS_URI=unix:///tmp/redis.sock php vendor/bin/phpunit tests/Integration/RedisThrottleTest.php

# Skeleton, while developing both repositories before publication
REACTOR_FRAMEWORK_PATH=/workspace/reactor-framework composer test
```

The skeleton also supplies `tests/live-smoke.php`. It is not part of automatic
PHPUnit runs: it sends, edits and deletes one test message in an explicitly
provided chat. Supply a test bot token/chat ID using existing secure environment
bindings `REACTOR_LIVE_BOT_TOKEN` / `REACTOR_LIVE_CHAT_ID`, or JSON on stdin with
`token` and `chat_id`. Do not commit credentials. Its test-only HTTP transport
uses curl through the runner's inherited proxy/CA trust, with TLS verification
left enabled. Passing fake-transport tests is not proof of live Telegram access.
