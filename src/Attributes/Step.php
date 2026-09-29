<?php
namespace Reactor\Attributes;

/**
 * Attribute to mark a class as a step handler.
 *
 * Step handlers are used for conversational flows (multi‑step dialogs).
 * They are triggered when the user's current step matches the given name.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class Step
{
    /**
     * @param string      $name       Unique step identifier.
     * @param string|null $nextStep   Next step to set after handling.
     * @param bool        $autoClear  Whether to clear the step automatically.
     */
    public function __construct(
        public string $name,
        public ?string $nextStep = null,
        public bool $autoClear = true
    ) {}
}
