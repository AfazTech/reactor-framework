<?php

namespace Reactor\Testing;

final class UpdateFactory
{
    private static int $sequence = 0;
    public static function message(int $userId, string $text, ?int $chatId = null): array
    {
        $id = ++self::$sequence;
        return ['update_id' => $id, 'message' => ['message_id' => $id, 'date' => time(),
            'from' => ['id' => $userId, 'is_bot' => false, 'first_name' => 'Test'],
            'chat' => ['id' => $chatId ?? $userId, 'type' => $chatId !== null && $chatId < 0 ? 'group' : 'private'], 'text' => $text]];
    }
    public static function callback(int $userId, string $data, ?int $chatId = null): array
    {
        $message = self::message($userId, '', $chatId);
        return ['update_id' => $message['update_id'], 'callback_query' => [
            'id' => 'callback-' . $message['update_id'], 'from' => $message['message']['from'],
            'message' => $message['message'], 'chat_instance' => 'test', 'data' => $data]];
    }
}
