<?php

namespace Reactor\Conversation;

final class ConversationResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $flow = null,
        public readonly ?string $prompt = null,
        public readonly array $answers = [],
        public readonly ?string $error = null,
    ) {}
}
