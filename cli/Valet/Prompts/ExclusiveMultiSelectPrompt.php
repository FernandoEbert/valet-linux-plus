<?php

declare(strict_types=1);

namespace Valet\Prompts;

use Laravel\Prompts\MultiSelectPrompt;
use Laravel\Prompts\Themes\Default\MultiSelectPromptRenderer;

/**
 * Multi-select where the options of each exclusive group behave like radio buttons:
 * checking one of them unchecks the others of the same group.
 */
class ExclusiveMultiSelectPrompt extends MultiSelectPrompt
{
    /**
     * @var array<int, array<int, int|string>>
     */
    private array $exclusive;

    /**
     * @param array<int|string, string> $options
     * @param array<int, int|string> $default
     * @param array<int, array<int, int|string>> $exclusive
     */
    public function __construct(
        string $label,
        array $options,
        array $default = [],
        array $exclusive = [],
        int $scroll = 5,
        string $hint = '',
    ) {
        $this->exclusive = $exclusive;

        parent::__construct(label: $label, options: $options, default: $default, scroll: $scroll, hint: $hint);

        $this->values = $this->enforceExclusivity($this->values);
        $this->default = $this->values;
    }

    protected function toggleHighlighted(): void
    {
        $before = $this->values;

        parent::toggleHighlighted();

        $checked = array_values(array_diff($this->values, $before));
        if ($checked === []) {
            return;
        }

        foreach ($this->groupsOf($checked[0]) as $group) {
            $this->values = array_values(array_filter(
                $this->values,
                static fn (int|string $value): bool => $value === $checked[0] || !in_array($value, $group, true)
            ));
        }
    }

    protected function toggleAll(): void
    {
        parent::toggleAll();

        $this->values = $this->enforceExclusivity($this->values);
    }

    /**
     * Rendered exactly like a regular multi-select (renderers are resolved by class name).
     */
    protected function getRenderer(): callable
    {
        return new MultiSelectPromptRenderer($this);
    }

    /**
     * Keep only the first selected member of each exclusive group.
     * @param array<int, int|string> $values
     * @return array<int, int|string>
     */
    private function enforceExclusivity(array $values): array
    {
        foreach ($this->exclusive as $group) {
            $kept = false;
            $values = array_values(array_filter($values, static function (int|string $value) use ($group, &$kept): bool {
                if (!in_array($value, $group, true)) {
                    return true;
                }
                if ($kept) {
                    return false;
                }

                return $kept = true;
            }));
        }

        return $values;
    }

    /**
     * @return array<int, array<int, int|string>>
     */
    private function groupsOf(int|string $value): array
    {
        return array_values(array_filter(
            $this->exclusive,
            static fn (array $group): bool => in_array($value, $group, true)
        ));
    }
}
