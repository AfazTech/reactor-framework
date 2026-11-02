<?php

namespace Reactor\Core;

use Amp\Sync\LocalMutex;
use Reactor\Core\Traits\FromIdExtractor;

/** Preserves ordering for one user/chat in this process, without serializing others. */
final class UpdateSequencer
{
    use FromIdExtractor;
    private array $entries = [];
    private object $main;

    public function __construct() { $this->main = new \stdClass(); }

    public function run(array $update, callable $callback): mixed
    {
        $user = $this->fromId($update);
        $chat = $this->extractChatId($update)
            ?? $update['channel_post']['chat']['id'] ?? $update['edited_channel_post']['chat']['id']
            ?? $update['business_message']['chat']['id'] ?? $update['edited_business_message']['chat']['id']
            ?? $update['message_reaction']['chat']['id'] ?? $update['message_reaction_count']['chat']['id'] ?? null;
        $key = $user !== null ? 'user:' . $user : ($chat !== null ? 'chat:' . $chat : null);
        if ($key === null) { return $callback(); }
        $fiber = \Fiber::getCurrent() ?? $this->main;
        if (($this->entries[$key]['owner'] ?? null) === $fiber) { return $callback(); }
        $this->entries[$key] ??= ['mutex' => new LocalMutex(), 'waiting' => 0, 'owner' => null];
        $this->entries[$key]['waiting']++;
        $lock = null;
        try {
            $lock = $this->entries[$key]['mutex']->acquire();
            $this->entries[$key]['owner'] = $fiber;
            return $callback();
        } finally {
            if ($lock !== null) {
                $this->entries[$key]['owner'] = null;
                $lock->release();
            }
            if (--$this->entries[$key]['waiting'] === 0) { unset($this->entries[$key]); }
        }
    }
}
