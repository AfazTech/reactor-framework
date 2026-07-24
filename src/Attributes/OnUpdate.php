<?php
namespace Reactor\Attributes;

/**
 * Attribute to specify which update types a handler or middleware can process.
 *
 * This restricts execution to specific Telegram update types (e.g., message,
 * callback_query). Use 'any' to allow all types.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class OnUpdate
{
    /**
     * @var array List of allowed update type strings.
     */
    public array $types;

    /**
     * @param string|array $types Comma‑separated list or array of types.
     */
    public function __construct(string|array $types = 'any')
    {
        if (is_string($types)) {
            $types = $types === 'any' ? ['any'] : array_map('trim', explode(',', $types));
        }
        $this->types = $types;
    }

    /**
     * Check whether a given update type is allowed.
     *
     * @param string $type The update type.
     * @return bool
     */
    public function allows(string $type): bool
    {
        return in_array('any', $this->types) || in_array($type, $this->types);
    }
}
