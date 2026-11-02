<?php

namespace Reactor\Conversation;

use Amp\Future;
use Reactor\Contracts\CacheInterface;

/** Persisted state contains data only; flow definitions/validators are registered at boot. */
final class ConversationManager
{
    private array $flows = [];
    public function __construct(private CacheInterface $store, private string $namespace = 'default') {}
    public function define(Flow $flow): void
    {
        if ($flow->steps() === []) { throw new \InvalidArgumentException('A flow needs at least one step.'); }
        $this->flows[$flow->name] = clone $flow;
    }
    public function start(int $userId, string $flow): ConversationResult
    {
        $definition = $this->flows[$flow] ?? throw new \InvalidArgumentException('Unknown flow: ' . $flow);
        $state = ['flow' => $flow, 'index' => 0, 'answers' => [], 'expires' => time() + $definition->ttl];
        $this->save($userId, $state, $definition);
        return new ConversationResult('waiting', $flow, $definition->steps()[0]['prompt']);
    }
    public function active(int $userId): bool { return $this->state($userId) !== null; }
    public function cancel(int $userId): ConversationResult
    {
        $state = $this->state($userId);
        $this->store->delete($this->key($userId))->await();
        return new ConversationResult('cancelled', $state['flow'] ?? null);
    }
    public function back(int $userId): ConversationResult
    {
        $state = $this->state($userId);
        if ($state === null) { return new ConversationResult('inactive'); }
        $flow = $this->flows[$state['flow']];
        $state['index'] = max(0, $state['index'] - 1);
        $steps = $flow->steps();
        foreach (array_slice($steps, $state['index']) as $step) { unset($state['answers'][$step['field']]); }
        $this->save($userId, $state, $flow);
        return new ConversationResult('waiting', $flow->name, $steps[$state['index']]['prompt'], $state['answers']);
    }
    public function accept(int $userId, string $input): ConversationResult
    {
        $state = $this->state($userId);
        if ($state === null) { return new ConversationResult('inactive'); }
        $flow = $this->flows[$state['flow']]; $steps = $flow->steps();
        $step = $steps[$state['index']];
        $valid = $step['validate'] === null ? true : ($step['validate'])($input, $state['answers']);
        if ($valid instanceof Future) { $valid = $valid->await(); }
        if ($valid !== true) {
            return new ConversationResult('invalid', $flow->name, $step['prompt'], $state['answers'], $step['error']);
        }
        $state['answers'][$step['field']] = $input;
        $state['index']++;
        if ($state['index'] >= count($steps)) {
            $this->store->delete($this->key($userId))->await();
            return new ConversationResult('completed', $flow->name, null, $state['answers']);
        }
        $this->save($userId, $state, $flow);
        return new ConversationResult('waiting', $flow->name, $steps[$state['index']]['prompt'], $state['answers']);
    }
    private function state(int $userId): ?array
    {
        $state = $this->store->get($this->key($userId))->await();
        if (!is_array($state)) { return null; }
        $flow = $this->flows[$state['flow'] ?? ''] ?? null;
        if ($flow === null || ($state['expires'] ?? 0) <= time()
            || !isset($flow->steps()[$state['index'] ?? -1])) {
            $this->store->delete($this->key($userId))->await();
            return null;
        }
        return $state;
    }
    private function save(int $userId, array $state, Flow $flow): void
    {
        $ttl = max(1, $state['expires'] - time());
        if (!$this->store->set($this->key($userId), $state, $ttl)->await()) {
            throw new \RuntimeException('Could not persist conversation state.');
        }
    }
    private function key(int $userId): string { return 'reactor:conversation:' . $this->namespace . ':' . $userId; }
}
