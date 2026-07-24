<?php
namespace Reactor\Core\Schedule;

/**
 * Parses and evaluates a cron expression.
 */
class CronExpression
{
    private array $fields;

    public function __construct(string $expression)
    {
        $parts = preg_split('/\s+/', trim($expression));
        if (count($parts) !== 5) {
            throw new \InvalidArgumentException("Cron expression must have 5 fields: {$expression}");
        }
        $this->fields = $parts;
    }

    /**
     * Determine if the cron is due at the given time.
     *
     * @param \DateTimeImmutable $time
     * @return bool
     */
    public function isDue(\DateTimeImmutable $time): bool
    {
        return $this->matchesField($this->fields[0], (int) $time->format('i'), 0, 59)
            && $this->matchesField($this->fields[1], (int) $time->format('G'), 0, 23)
            && $this->matchesField($this->fields[2], (int) $time->format('j'), 1, 31)
            && $this->matchesField($this->fields[3], (int) $time->format('n'), 1, 12)
            && $this->matchesField($this->fields[4], (int) $time->format('w'), 0, 6);
    }

    private function matchesField(string $field, int $value, int $min, int $max): bool
    {
        foreach (explode(',', $field) as $part) {
            if ($this->matchesPart($part, $value, $min, $max)) {
                return true;
            }
        }
        return false;
    }

    private function matchesPart(string $part, int $value, int $min, int $max): bool
    {
        $step = 1;
        if (str_contains($part, '/')) {
            [$part, $stepStr] = explode('/', $part, 2);
            $step = (int) $stepStr;
            if ($step <= 0) {
                $step = 1;
            }
        }

        if ($part === '*') {
            $rangeStart = $min;
            $rangeEnd = $max;
        } elseif (str_contains($part, '-')) {
            [$rangeStart, $rangeEnd] = array_map('intval', explode('-', $part, 2));
        } else {
            $rangeStart = $rangeEnd = (int) $part;
        }

        if ($value < $rangeStart || $value > $rangeEnd) {
            return false;
        }

        return (($value - $rangeStart) % $step) === 0;
    }
}
