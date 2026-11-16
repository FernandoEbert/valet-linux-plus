<?php

namespace Valet\Facades;

/**
 * Class Services.
 *
 * @method static array enabledOptional()
 * @method static bool isEnabled(string $service)
 * @method static array enabledServices()
 * @method static array conflicts(array $services)
 * @method static array installOptions(bool $forcesMariaDB)
 * @method static array installDefaults(?string $installedEngine, bool $forcesMariaDB)
 * @method static array withDatabaseEngine(array $services, ?string $engine)
 * @method static string|null selectedDatabaseEngine(array $services)
 * @method static array setEnabledOptional(array $services)
 * @method static array unknown(array $services)
 * @method static array parseList(string $list)
 * @method static void restart(array $services = [])
 * @method static void stop(array $services = [])
 */
class Services extends Facade
{
}
