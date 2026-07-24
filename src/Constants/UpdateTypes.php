<?php

namespace Reactor\Constants;

/**
 * Central registry of all Telegram update types.
 */
class UpdateTypes
{
    /**
     * Get the complete list of known update type keys.
     *
     * @return array<int, string>
     */
    public static function getAll(): array
    {
        return [
            'message',
            'edited_message',
            'channel_post',
            'edited_channel_post',
            'business_connection',
            'business_message',
            'edited_business_message',
            'deleted_business_messages',
            'message_reaction',
            'message_reaction_count',
            'inline_query',
            'chosen_inline_result',
            'callback_query',
            'shipping_query',
            'pre_checkout_query',
            'purchased_paid_media',
            'poll',
            'poll_answer',
            'my_chat_member',
            'chat_member',
            'chat_join_request',
            'chat_boost',
            'removed_chat_boost',
            'managed_bot',
            'guest_message',
        ];
    }
}
