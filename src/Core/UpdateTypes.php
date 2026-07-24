<?php

namespace Reactor\Core;

/**
 * Central registry of all known Telegram update types.
 *
 * This class provides constants for all update types to avoid duplication
 * across different parts of the framework.
 */
final class UpdateTypes
{
    public const MESSAGE = 'message';
    public const EDITED_MESSAGE = 'edited_message';
    public const CHANNEL_POST = 'channel_post';
    public const EDITED_CHANNEL_POST = 'edited_channel_post';
    public const BUSINESS_CONNECTION = 'business_connection';
    public const BUSINESS_MESSAGE = 'business_message';
    public const EDITED_BUSINESS_MESSAGE = 'edited_business_message';
    public const DELETED_BUSINESS_MESSAGES = 'deleted_business_messages';
    public const MESSAGE_REACTION = 'message_reaction';
    public const MESSAGE_REACTION_COUNT = 'message_reaction_count';
    public const INLINE_QUERY = 'inline_query';
    public const CHOSEN_INLINE_RESULT = 'chosen_inline_result';
    public const CALLBACK_QUERY = 'callback_query';
    public const SHIPPING_QUERY = 'shipping_query';
    public const PRE_CHECKOUT_QUERY = 'pre_checkout_query';
    public const PURCHASED_PAID_MEDIA = 'purchased_paid_media';
    public const POLL = 'poll';
    public const POLL_ANSWER = 'poll_answer';
    public const MY_CHAT_MEMBER = 'my_chat_member';
    public const CHAT_MEMBER = 'chat_member';
    public const CHAT_JOIN_REQUEST = 'chat_join_request';
    public const CHAT_BOOST = 'chat_boost';
    public const REMOVED_CHAT_BOOST = 'removed_chat_boost';
    public const MANAGED_BOT = 'managed_bot';
    public const GUEST_MESSAGE = 'guest_message';

    /**
     * Returns an array of all known update type constants.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::MESSAGE,
            self::EDITED_MESSAGE,
            self::CHANNEL_POST,
            self::EDITED_CHANNEL_POST,
            self::BUSINESS_CONNECTION,
            self::BUSINESS_MESSAGE,
            self::EDITED_BUSINESS_MESSAGE,
            self::DELETED_BUSINESS_MESSAGES,
            self::MESSAGE_REACTION,
            self::MESSAGE_REACTION_COUNT,
            self::INLINE_QUERY,
            self::CHOSEN_INLINE_RESULT,
            self::CALLBACK_QUERY,
            self::SHIPPING_QUERY,
            self::PRE_CHECKOUT_QUERY,
            self::PURCHASED_PAID_MEDIA,
            self::POLL,
            self::POLL_ANSWER,
            self::MY_CHAT_MEMBER,
            self::CHAT_MEMBER,
            self::CHAT_JOIN_REQUEST,
            self::CHAT_BOOST,
            self::REMOVED_CHAT_BOOST,
            self::MANAGED_BOT,
            self::GUEST_MESSAGE,
        ];
    }
}
