<?php

namespace Reactor\Conversation;

final class Flow
{
    private array $steps = [];
    public function __construct(public readonly string $name, public readonly int $ttl = 1800)
    {
        if ($name === '' || $ttl < 1) { throw new \InvalidArgumentException('Flow name and positive TTL are required.'); }
    }
    public function ask(string $field, string $prompt, ?callable $validate = null, string $error = 'Invalid value. Please try again.'): self
    {
        foreach ($this->steps as $step) {
            if ($step['field'] === $field) { throw new \InvalidArgumentException('Duplicate flow field: ' . $field); }
        }
        if ($field === '' || $prompt === '') { throw new \InvalidArgumentException('Field and prompt cannot be empty.'); }
        $this->steps[] = compact('field', 'prompt', 'validate', 'error');
        return $this;
    }
    public function steps(): array { return $this->steps; }
}
