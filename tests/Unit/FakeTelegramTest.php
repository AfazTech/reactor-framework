<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reactor\Testing\FakeTelegram;

class FakeTelegramTest extends TestCase
{
    public function testRealClientRequestsAreCapturedWithoutNetworking(): void
    {
        $fake = new FakeTelegram(); $client = $fake->client(); $fake->assertNothingSent();
        self::assertSame(1, $client->sendMessage(123, 'hello')->await()['result']['message_id']);
        $client->answerCallbackQuery('callback')->await();
        $fake->assertSent('sendMessage', fn ($p) => $p['chat_id'] === 123 && $p['text'] === 'hello');
        $fake->assertSent('answerCallbackQuery');
        self::assertStringNotContainsString('fake', json_encode($fake->requests()));
    }
    public function testApiFailuresUseTheRealNeiliExceptionPath(): void
    {
        $fake = new FakeTelegram(); $fake->fail('sendMessage', 'Bad Request: test', 400);
        $this->expectException(\Neili\Exceptions\PermanentException::class);
        $fake->client()->sendMessage(123, 'failure')->await();
    }
}
