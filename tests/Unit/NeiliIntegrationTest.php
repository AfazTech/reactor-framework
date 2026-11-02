<?php

namespace Tests\Unit;

use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Neili\Client;
use Neili\Poller;
use PHPUnit\Framework\TestCase;
use Reactor\Contracts\LoggerInterface;
use Reactor\Core\Bootstrappers\TelegramBootstrapper;
use Reactor\Core\Config;
use Reactor\Core\Container;

class NeiliIntegrationTest extends TestCase
{
    public function testBootstrapAndMessageDeliveryAreCompatibleWithNeili4(): void
    {
        $config = new Config();
        $config->override('bot.token', '123456:test-only');
        $container = new Container();
        $container->instance(Config::class, $config);
        $container->instance(LoggerInterface::class, $this->createMock(LoggerInterface::class));
        (new TelegramBootstrapper())->bootstrap($container);
        self::assertInstanceOf(Poller::class, $container->get(Poller::class));
        $client = $container->get(Client::class);
        $transport = new class {
            public array $methods = [];
            public function request(Request $request): Response
            {
                $method = basename($request->getUri()->getPath());
                $this->methods[] = $method;
                $result = $method === 'getWebhookInfo' ? ['url' => ''] : ['message_id' => 1];
                return new Response('1.1', 200, 'OK', ['content-type' => 'application/json'],
                    json_encode(['ok' => true, 'result' => $result]), $request);
            }
        };
        $client->setHttpClient($transport);
        self::assertSame('', $client->getWebhookInfo()->await()['result']['url']);
        self::assertSame(1, $client->sendMessage(123, 'test message')->await()['result']['message_id']);
        $client->flush();
        self::assertSame(['getWebhookInfo', 'sendMessage'], $transport->methods);
    }
}
