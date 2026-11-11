<?php

namespace Valet\Facades;

use Illuminate\Support\Collection;

/**
 * Class Ngrok.
 *
 * @method static void                      secure(string $url, string $stub = null)
 * @method static void                      unsecure(string $url, bool $preserveUnsecureConfig = false)
 * @method static Collection<int, string>   secured()
 * @method static void                      regenerateSecuredSitesConfig()
 * @method static void                      reSecureForNewDomain(string $oldDomain, string $domain)
 * @method static string                    buildSecureNginxServer(string $url, ?string $stub = null)
 * @method static string                    buildUnsecureNginxServer(string $url, string $stub)
 */
class SiteSecure extends Facade
{
}
