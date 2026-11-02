<?php

namespace Reactor\Conversation;

use Neili\Client;
use Reactor\Contracts\MiddlewareInterface;
use Reactor\Core\EventDispatcher;
use Reactor\Core\Traits\FromIdExtractor;
use Reactor\Events;

class ConversationMiddleware implements MiddlewareInterface
{
    use FromIdExtractor;
    public function __construct(private ConversationManager $conversations, private Client $client, private EventDispatcher $events) {}
    public function handle(array $update): bool
    {
        $user = $this->fromId($update); $chat = $this->extractChatId($update);
        $text = $update['message']['text'] ?? null;
        if ($user === null || $chat === null || !is_string($text) || !$this->conversations->active($user)) { return false; }
        $result = match (trim($text)) {
            '/cancel' => $this->conversations->cancel($user),
            '/back' => $this->conversations->back($user),
            default => $this->conversations->accept($user, $text),
        };
        if ($result->status === 'completed') {
            $this->events->dispatch(Events::CONVERSATION_COMPLETED, $user, $result->flow, $result->answers);
        } elseif ($result->status === 'cancelled') {
            $this->client->sendMessage($chat, 'Conversation cancelled.')->await();
        } elseif ($result->prompt !== null) {
            $this->client->sendMessage($chat, ($result->error !== null ? $result->error . "\n" : '') . $result->prompt)->await();
        }
        return true;
    }
}
