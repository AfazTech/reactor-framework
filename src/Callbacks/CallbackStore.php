<?php

namespace Reactor\Callbacks;

use Reactor\Contracts\CacheInterface;

/** Opaque 128-bit references fit Telegram's 64-byte limit; payload stays server-side. */
final class CallbackStore
{
    public function __construct(private CacheInterface $store, private string $namespace = 'default') {}
    public function issue(string $action, array $data = [], ?int $userId = null, int $ttl = 900): string
    {
        if ($action === '' || $ttl < 1 || ($userId !== null && $userId <= 0)) {
            throw new \InvalidArgumentException('Action, positive TTL and valid user ID are required.');
        }
        // Ensure payloads contain serializable data, not arbitrary application objects.
        $encoded = json_encode($data, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 65536) { throw new \LengthException('Callback payload exceeds 64 KiB.'); }
        $data = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        $token = 'rcb:' . bin2hex(random_bytes(16));
        if (!$this->store->set($this->namespace . ':' . $token, ['action' => $action, 'data' => $data, 'user' => $userId, 'expires' => time() + $ttl], $ttl)->await()) {
            throw new \RuntimeException('Could not store callback payload.');
        }
        return $token;
    }
    public function resolve(string $token, ?int $userId): ?array
    {
        if (!preg_match('/^rcb:[0-9a-f]{32}$/D', $token)) { return null; }
        $payload = $this->store->get($this->namespace . ':' . $token)->await();
        if (!is_array($payload) || !is_string($payload['action'] ?? null) || !is_array($payload['data'] ?? null)
            || !array_key_exists('user', $payload) || !is_int($payload['expires'] ?? null) || $payload['expires'] <= time()) { return null; }
        if ($payload['user'] !== null && $payload['user'] !== $userId) { return null; }
        return ['action' => $payload['action'], 'data' => $payload['data']];
    }
    public function revoke(string $token): void { $this->store->delete($this->namespace . ':' . $token)->await(); }
}
