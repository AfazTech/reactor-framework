<?php

namespace Reactor\Core\Traits;

trait FromIdExtractor
{
    protected function fromId(array $update): ?int
    {
        return $update['message']['from']['id']
            ?? $update['edited_message']['from']['id']
            ?? $update['callback_query']['from']['id']
            ?? $update['inline_query']['from']['id']
            ?? $update['chosen_inline_result']['from']['id']
            ?? $update['shipping_query']['from']['id']
            ?? $update['pre_checkout_query']['from']['id']
            ?? $update['poll_answer']['user']['id']
            ?? $update['chat_member']['from']['id']
            ?? $update['my_chat_member']['from']['id']
            ?? $update['chat_join_request']['from']['id']
            ?? $update['chat_boost']['from']['id']
            ?? $update['removed_chat_boost']['from']['id']
            ?? $update['message_reaction']['user']['id'] ?? null;
    }

    protected function extractFromIdIfExists(array $update): ?int
    {
        $locations = [
            'message.from.id',
            'callback_query.from.id',
            'inline_query.from.id',
            'chosen_inline_result.from.id',
            'shipping_query.from.id',
            'pre_checkout_query.from.id',
            'poll_answer.user.id',
            'chat_member.from.id',
            'my_chat_member.from.id',
            'chat_join_request.from.id',
            'chat_boost.from.id',
            'removed_chat_boost.from.id',
        ];

        foreach ($locations as $path) {
            $parts = explode('.', $path);
            $value = $update;
            foreach ($parts as $part) {
                if (!isset($value[$part])) {
                    continue 2;
                }
                $value = $value[$part];
            }
            if (is_int($value) || is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    protected function extractChatId(array $update): ?int
    {
        return $update['message']['chat']['id']
            ?? $update['edited_message']['chat']['id']
            ?? $update['callback_query']['message']['chat']['id']
            ?? $update['inline_query']['chat']['id'] ?? null;
    }

    protected function extractMessageId(array $update): ?int
    {
        return $update['message']['message_id']
            ?? $update['edited_message']['message_id']
            ?? $update['callback_query']['message']['message_id']
            ?? $update['inline_query']['message_id'] ?? null;
    }
}
