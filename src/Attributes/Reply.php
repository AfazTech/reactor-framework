<?php
namespace Reactor\Attributes;

/**
 * Attribute to restrict a handler or middleware to reply updates.
 *
 * A "reply update" is an update whose message (or the message a
 * callback button belongs to) is a reply to another message. Supported
 * carriers: message, edited_message, business_message,
 * edited_business_message, and callback_query.message.
 *
 * When applied to a handler, the handler only matches an update when
 * the reply requirement is satisfied. When applied to a middleware,
 * the middleware only executes for matching updates.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class Reply
{
    /**
     * @param bool $only When true (default), the handler or middleware
     *                   only runs for reply updates. When false, the
     *                   behavior is inverted: only non-reply updates.
     */
    public function __construct(
        public bool $only = true
    ) {}
}
