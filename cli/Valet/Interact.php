<?php

declare(strict_types=1);

namespace Valet;

use Valet\Prompts\ExclusiveMultiSelectPrompt;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Thin wrapper around laravel/prompts that falls back to the given defaults
 * whenever the session is not interactive (tests, pipes, `--no-interaction`).
 */
class Interact
{
    private bool $interactive = true;

    /**
     * Toggle interactivity, e.g. from Symfony's `InputInterface::isInteractive()`.
     */
    public function setInteractive(bool $interactive): self
    {
        $this->interactive = $interactive;

        return $this;
    }

    public function isInteractive(): bool
    {
        return $this->interactive && !testing() && stream_isatty(STDIN);
    }

    /**
     * @param array<int|string, string> $options
     */
    public function select(string $label, array $options, ?string $default = null, int $scroll = 10): ?string
    {
        if (!$this->isInteractive() || $options === []) {
            return $default;
        }

        return (string)select(label: $label, options: $options, default: $default, scroll: $scroll);
    }

    /**
     * @param array<int|string, string> $options
     * @param array<int, int|string> $default
     * @param array<int, array<int, int|string>> $exclusive Groups of options that uncheck each other (radio-like)
     * @return array<int, int|string>
     */
    public function multiselect(
        string $label,
        array $options,
        array $default = [],
        int $scroll = 10,
        array $exclusive = [],
        string $hint = '',
    ): array {
        if (!$this->isInteractive() || $options === []) {
            return $default;
        }

        if ($exclusive === []) {
            return array_values(multiselect(label: $label, options: $options, default: $default, scroll: $scroll, hint: $hint));
        }

        /** @var array<int, int|string> $selected */
        $selected = (new ExclusiveMultiSelectPrompt(
            label: $label,
            options: $options,
            default: $default,
            exclusive: $exclusive,
            scroll: $scroll,
            hint: $hint,
        ))->prompt();

        return array_values($selected);
    }

    public function confirm(string $label, bool $default = true): bool
    {
        if (!$this->isInteractive()) {
            return $default;
        }

        return confirm(label: $label, default: $default);
    }

    public function text(string $label, string $default = '', string $placeholder = ''): string
    {
        if (!$this->isInteractive()) {
            return $default;
        }

        return text(label: $label, placeholder: $placeholder, default: $default);
    }
}
