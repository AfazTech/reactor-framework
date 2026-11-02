<?php

namespace Reactor\Testing;

use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Neili\Client;
use Neili\Settings;
use PHPUnit\Framework\Assert;
use function Amp\ByteStream\buffer;

/** JSON Bot API test transport. Never opens a network connection or records token-bearing URLs. */
final class FakeTelegram
{
    private array $requests = [];
    private array $responses = [];
    public function client(): Client
    {
        $client = new Client((new Settings())->setAccessToken('0:fake'));
        $client->setHttpClient($this);
        return $client;
    }
    public function respond(string $method, array|bool $result): void { $this->responses[$method] = ['ok' => true, 'result' => $result]; }
    public function fail(string $method, string $description, int $code = 400): void
    {
        $this->responses[$method] = ['ok' => false, 'error_code' => $code, 'description' => $description];
    }
    public function request(Request $request): Response
    {
        $method = basename($request->getUri()->getPath());
        $body = buffer($request->getBody()->getContent());
        $params = $body === '' ? [] : json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $this->requests[] = ['method' => $method, 'params' => $params];
        $response = $this->responses[$method] ?? ['ok' => true, 'result' => match ($method) {
            'getMe' => ['id' => 1, 'is_bot' => true, 'first_name' => 'Fake', 'username' => 'fake_bot'],
            'getWebhookInfo' => ['url' => ''], 'getUpdates' => [],
            'sendMessage', 'editMessageText' => ['message_id' => count($this->requests)],
            default => true,
        }];
        return new Response('1.1', $response['ok'] ? 200 : $response['error_code'], null,
            ['content-type' => 'application/json'], json_encode($response, JSON_THROW_ON_ERROR), $request);
    }
    public function requests(): array { return $this->requests; }
    public function assertSent(string $method, ?callable $where = null, int $times = 1): void
    {
        $matching = array_filter($this->requests, fn ($r) => $r['method'] === $method && ($where === null || $where($r['params'])));
        Assert::assertCount($times, $matching, 'Unexpected ' . $method . ' request count');
    }
    public function assertNothingSent(): void { Assert::assertSame([], $this->requests); }
}
