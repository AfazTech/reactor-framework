<?php

namespace Reactor\Core\Routing\Matchers;

use Reactor\Core\HandlerExecution;
use Reactor\Core\Routing\HandlerRegistry;
use Reactor\Contracts\LoggerInterface;
use App\Contracts\Repository\UserRepositoryInterface;
use Reactor\Core\Traits\FromIdExtractor;

/**
 * Matcher for step handlers (conversational flows).
 */
class StepMatcher
{
    use FromIdExtractor;

    private HandlerRegistry $registry;
    private LoggerInterface $logger;
    private UserRepositoryInterface $userRepository;

    public function __construct(
        HandlerRegistry $registry,
        LoggerInterface $logger,
        UserRepositoryInterface $userRepository
    ) {
        $this->registry = $registry;
        $this->logger = $logger;
        $this->userRepository = $userRepository;
    }

    public function match(array $update, string $updateType): ?HandlerExecution
    {
        $fromId = $this->extractFromIdIfExists($update);
        if ($fromId === null) {
            return null;
        }

        $currentStep = $this->userRepository->getStep($fromId);
        if ($currentStep === null) {
            return null;
        }

        $steps = $this->registry->getSteps();
        if (!isset($steps[$currentStep])) {
            return null;
        }

        $stepConfig = $steps[$currentStep];
        if (!$this->allowsUpdateType($stepConfig['allowedUpdates'], $updateType)) {
            return null;
        }

        $this->logger->debug('Step matched', [
            'step' => $currentStep,
            'class' => $stepConfig['class'],
        ]);

        return new HandlerExecution(
            handlerClass: $stepConfig['class'],
            params: [],
            metadata: $stepConfig['metadata'],
            update: $update
        );
    }

    private function allowsUpdateType(array $allowed, string $type): bool
    {
        return in_array('any', $allowed, true) || in_array($type, $allowed, true);
    }
}
