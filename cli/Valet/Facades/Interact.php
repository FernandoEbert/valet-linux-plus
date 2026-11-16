<?php

namespace Valet\Facades;

/**
 * Class Interact.
 *
 * @method static \Valet\Interact setInteractive(bool $interactive)
 * @method static bool isInteractive()
 * @method static string|null select(string $label, array $options, ?string $default = null, int $scroll = 10)
 * @method static array multiselect(string $label, array $options, array $default = [], int $scroll = 10, array $exclusive = [], string $hint = '')
 * @method static bool confirm(string $label, bool $default = true)
 * @method static string text(string $label, string $default = '', string $placeholder = '')
 */
class Interact extends Facade
{
}
