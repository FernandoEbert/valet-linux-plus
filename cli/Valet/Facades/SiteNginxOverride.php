<?php

namespace Valet\Facades;

/**
 * Class SiteNginxOverride.
 *
 * @method static string      publish(string $url, bool $force = false)
 * @method static string      apply(string $url)
 * @method static void        reset(string $url)
 * @method static bool        sync(string $url)
 * @method static void        syncOrWarn(string $url)
 * @method static string      render(string $url, string $template, ?string $isolatedPhpVersion = null)
 * @method static string|null templatePath(string $url)
 * @method static bool        isActive(string $url)
 */
class SiteNginxOverride extends Facade
{
}
