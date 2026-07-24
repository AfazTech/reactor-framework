<?php

namespace Reactor\Core\Routing;

use Reactor\Core\Container;
use Reactor\Core\HandlerExecution;
use Reactor\Contracts\LoggerInterface;
use Reactor\Contracts\LanguageInterface;
use Reactor\Contracts\UserProviderInterface;
use Reactor\Core\Traits\FromIdExtractor;
use Reactor\Constants\UpdateTypes;

class HandlerMatcher
{
    use FromIdExtractor;

    private HandlerRegistry $registry;
    private Container $container;
    private LoggerInterface $logger;

    public function __construct(HandlerRegistry $registry, Container $container, LoggerInterface $logger)
    {
        $this->registry = $registry;
        $this->container = $container;
        $this->logger = $logger;
    }

    public function findHandler(array $update): HandlerExecution
    {
        $updateType = $this->getUpdateType($update);
        $this->logger->debug('Finding handler', ['type' => $updateType]);

        $text = trim($update['message']['text'] ?? '');
        $callbackData = $update['callback_query']['data'] ?? null;
        $hasCallback = $callbackData !== null;

        $this->logger->debug('Finding handler', [
            'type'          => $updateType,
            'has_callback'  => $hasCallback,
        ]);

        // 1. Callback data exact or regex match.
        if ($callbackData !== null) {
            foreach ($this->registry->getCallbacks() as $pattern => $config) {
                if ($this->matchesCallbackData($callbackData, $pattern) &&
                    $this->allowsUpdateType($config['allowedUpdates'], $updateType)) {
                    $this->logger->debug('Callback matched', ['pattern' => $pattern, 'class' => $config['class']]);
                    return $this->buildExecution($config['class'], $update, [], $config['metadata']);
                }
            }

            foreach ($this->registry->getPatterns() as $patternConfig) {
                if ($patternConfig['type'] !== 'callback') {
                    continue;
                }
                if (!$this->allowsUpdateType($patternConfig['handler']['allowedUpdates'], $updateType)) {
                    continue;
                }
                if (preg_match($patternConfig['pattern'], $callbackData, $matches)) {
                    $params = array_slice($matches, 1);
                    $this->logger->debug('Callback regex matched', [
                        'pattern' => $patternConfig['pattern'],
                        'class'   => $patternConfig['handler']['class'],
                    ]);
                    return $this->buildExecution(
                        $patternConfig['handler']['class'],
                        $update,
                        $params,
                        $patternConfig['handler']['metadata']
                    );
                }
            }

            $this->logger->debug('No callback handler matched', ['data' => $callbackData]);
        }

        // 2. Commands.
        if ($text !== '' && str_starts_with($text, '/')) {
            $commandName = explode(' ', $text)[0];
            $commands = $this->registry->getCommands();
            if (isset($commands[$commandName]) &&
                $this->allowsUpdateType($commands[$commandName]['allowedUpdates'], $updateType)) {
                $this->logger->debug('Command matched', [
                    'command'  => $commandName,
                    'class'    => $commands[$commandName]['class'],
                    'priority' => $commands[$commandName]['priority'],
                ]);
                return $this->buildExecution(
                    $commands[$commandName]['class'],
                    $update,
                    [],
                    $commands[$commandName]['metadata']
                );
            }
        }

        // 3. Text (exact match from translations) and text regex.
        if ($text !== '') {
            $language = $this->container->get(LanguageInterface::class);
            $userProvider = $this->container->get(UserProviderInterface::class);
            $fromId = $this->extractFromIdIfExists($update);
            $lang = $fromId ? $userProvider->getLanguage($fromId) : $language->getDefaultLanguage();

            $sortedTexts = $this->registry->getTexts();
            uasort($sortedTexts, function ($a, $b) {
                return $b['priority'] <=> $a['priority'];
            });

            foreach ($sortedTexts as $textKey => $config) {
                if (!$this->allowsUpdateType($config['allowedUpdates'], $updateType)) {
                    continue;
                }
                $translatedText = $language->get($textKey, $lang);
                if ($text === $translatedText) {
                    $this->logger->debug('Text/Button matched', [
                        'textKey'  => $textKey,
                        'class'    => $config['class'],
                        'priority' => $config['priority'],
                    ]);
                    return $this->buildExecution($config['class'], $update, [], $config['metadata']);
                }
            }

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
                    return $this->buildExecution(
                        $patternConfig['handler']['class'],
                        $update,
                        $params,
                        $patternConfig['handler']['metadata']
                    );
                }
            }
        }

        // 4. Steps.
        $fromId = $this->extractFromIdIfExists($update);
        if ($fromId !== null) {
            $userProvider = $this->container->get(UserProviderInterface::class);
            $currentStep = $userProvider->getStep($fromId);
            $steps = $this->registry->getSteps();
            if ($currentStep !== null && isset($steps[$currentStep]) &&
                $this->allowsUpdateType($steps[$currentStep]['allowedUpdates'], $updateType)) {
                $stepConfig = $steps[$currentStep];
                $this->logger->debug('Step matched', [
                    'step'  => $currentStep,
                    'class' => $stepConfig['class'],
                ]);
                return $this->buildExecution($stepConfig['class'], $update, [], $stepConfig['metadata']);
            }
        }

        // 5. Fallback.
        $fallback = $this->registry->getFallbackHandler();
        if ($fallback !== null) {
            $this->logger->debug('Using fallback handler', ['class' => $fallback['class']]);
            return $this->buildExecution($fallback['class'], $update, [], $fallback['metadata']);
        }

        $this->logger->debug('No handler found', ['type' => $updateType]);
        return new HandlerExecution();
    }

    private function buildExecution(string $handlerClass, array $update, array $params, array $metadata): HandlerExecution
    {
        return new HandlerExecution(
            handlerClass: $handlerClass,
            params: $params,
            metadata: $metadata,
            update: $update
        );
    }

    private function matchesCallbackData(string $data, string $pattern): bool
    {
        if ($data === $pattern) {
            return true;
        }
        if (str_starts_with($data, $pattern)) {
            $nextChar = substr($data, strlen($pattern), 1);
            if ($nextChar === '' || $nextChar === ':' || $nextChar === '_' || $nextChar === '|') {
                return true;
            }
        }
        return false;
    }

    private function allowsUpdateType(array $allowed, string $type): bool
    {
        return in_array('any', $allowed, true) || in_array($type, $allowed, true);
    }

    private function getUpdateType(array $update): string
    {
        foreach (UpdateTypes::getAll() as $type) {
            if (isset($update[$type])) {
                return $type;
            }
        }
        return 'unknown';
    }
}
