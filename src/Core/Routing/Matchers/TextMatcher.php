<?php

namespace Reactor\Core\Routing\Matchers;

use Reactor\Core\HandlerExecution;
use Reactor\Core\Routing\HandlerRegistry;
use Reactor\Contracts\LoggerInterface;
use Reactor\Contracts\LanguageInterface;
use App\Contracts\Repository\UserRepositoryInterface;
use Reactor\Core\Traits\FromIdExtractor;

/**
 * Matcher for plain text messages and text regex patterns.
 */
class TextMatcher
{
    use FromIdExtractor;

    private HandlerRegistry $registry;
    private LoggerInterface $logger;
    private LanguageInterface $language;
    private UserRepositoryInterface $userRepository;

    public function __construct(
        HandlerRegistry $registry,
        LoggerInterface $logger,
        LanguageInterface $language,
        UserRepositoryInterface $userRepository
    ) {
        $this->registry = $registry;
        $this->logger = $logger;
        $this->language = $language;
        $this->userRepository = $userRepository;
    }

    public function match(array $update, string $updateType): ?HandlerExecution
    {
        $text = trim($update['message']['text'] ?? '');
        if ($text === '') {
            return null;
        }

        // 1. Exact text matches (from translations)
        $fromId = $this->extractFromIdIfExists($update);
        $lang = $fromId ? $this->userRepository->getLanguage($fromId) : $this->language->getDefaultLanguage();

        $texts = $this->registry->getTexts();
        uasort($texts, function ($a, $b) {
            return $b['priority'] <=> $a['priority'];
        });

        foreach ($texts as $textKey => $config) {
            if (!$this->allowsUpdateType($config['allowedUpdates'], $updateType)) {
                continue;
            }
            $translatedText = $this->language->get($textKey, $lang);
            if ($text === $translatedText) {
                $this->logger->debug('Text/Button matched', [
                    'textKey' => $textKey,
                    'class'   => $config['class'],
                    'priority' => $config['priority'],
                ]);
                return new HandlerExecution(
                    handlerClass: $config['class'],
                    params: [],
                    metadata: $config['metadata'],
                    update: $update
                );
            }
        }

        // 2. Text regex patterns
        foreach ($this->registry->getPatterns() as $patternConfig) {
            if ($patternConfig['type'] !== 'text') {
                continue;
            }
            if (!$this->allowsUpdateType($patternConfig['handler']['allowedUpdates'], $updateType)) {
                continue;
            }
            if (preg_match($patternConfig['pattern'], $text, $matches)) {
                $params = array_slice($matches, 1);
                $this->logger->debug('Text regex matched', [
                    'pattern' => $patternConfig['pattern'],
                    'class'   => $patternConfig['handler']['class'],
                ]);
                return new HandlerExecution(
                    handlerClass: $patternConfig['handler']['class'],
                    params: $params,
                    metadata: $patternConfig['handler']['metadata'],
                    update: $update
                );
            }
        }

        return null;
    }

    private function allowsUpdateType(array $allowed, string $type): bool
    {
        return in_array('any', $allowed, true) || in_array($type, $allowed, true);
    }
}
