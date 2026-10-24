<?php
namespace Reactor\Core\Traits;

/**
 * Detects whether a Telegram update carries a reply.
 *
 * Message-shaped updates (message, edited_message, business_message,
 * edited_business_message) are considered replies when their payload
 * has a reply_to_message field. A callback query is also considered a
 * reply when the message containing the button was itself a reply.
 */
trait ReplyDetector
{
    /**
     * Determine whether the update is a reply.
     */
    protected function isReplyUpdate(array $update): bool
    {
        return isset($update['message']['reply_to_message'])
            || isset($update['edited_message']['reply_to_message'])
            || isset($update['business_message']['reply_to_message'])
            || isset($update['edited_business_message']['reply_to_message'])
            || isset($update['callback_query']['message']['reply_to_message']);
    }
}
