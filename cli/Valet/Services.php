<?php

declare(strict_types=1);

namespace Valet;

use ConsoleComponents\Writer;
use InvalidArgumentException;
use Valet\Facades\DnsMasq as DnsMasqFacade;
use Valet\Facades\Mailpit as MailpitFacade;
use Valet\Facades\Mysql as MysqlFacade;
use Valet\Facades\Nginx as NginxFacade;
use Valet\Facades\PhpFpm as PhpFpmFacade;
use Valet\Facades\ValetRedis as ValetRedisFacade;

/**
 * Registry of the services Valet manages and which optional ones the user opted into.
 */
class Services
{
    public const CONFIG_KEY = 'services';

    /**
     * Services Valet cannot work without; always installed and managed.
     */
    public const CORE = ['dnsmasq', 'php', 'nginx'];

    /**
     * Services the user may opt out of during `valet install`.
     */
    public const OPTIONAL = [
        'mailpit' => 'Mailpit (mail catcher)',
        'mysql'   => 'MySQL',
        'mariadb' => 'MariaDB',
        'redis'   => 'Redis',
        'ngrok'   => 'Ngrok (site sharing)',
    ];

    /**
     * Optional entries that cannot be selected together.
     */
    public const EXCLUSIVE = [
        ['mysql', 'mariadb'],
    ];

    /**
     * Optional entries managed through another service (`valet restart mysql` drives MariaDB too).
     */
    public const ALIASES = [
        'mariadb' => 'mysql',
    ];

    private Configuration $config;

    public function __construct(Configuration $config)
    {
        $this->config = $config;
    }

    /**
     * Optional services enabled in config.json; a missing key means "all" with the MySQL entry
     * (installs predating this option, whose database engine is whatever package is installed).
     * @return array<int, string>
     */
    public function enabledOptional(): array
    {
        $configured = $this->config->get(self::CONFIG_KEY);

        if (!is_array($configured)) {
            return array_values(array_diff(array_keys(self::OPTIONAL), ['mariadb']));
        }

        return array_values(array_intersect(array_keys(self::OPTIONAL), $configured));
    }

    /**
     * Whether a service (or one of its aliases, e.g. `mariadb` for `mysql`) is enabled.
     */
    public function isEnabled(string $service): bool
    {
        return in_array($this->serviceOf($service), $this->enabledServices(), true);
    }

    /**
     * Core services plus the enabled optional ones, aliases resolved to their service name.
     * @return array<int, string>
     */
    public function enabledServices(): array
    {
        return array_values(array_unique([
            ...self::CORE,
            ...array_map(fn (string $service): string => $this->serviceOf($service), $this->enabledOptional()),
        ]));
    }

    /**
     * Groups of {@see EXCLUSIVE} entries selected together.
     * @param array<int, string> $services
     * @return array<int, array<int, string>>
     */
    public function conflicts(array $services): array
    {
        $conflicts = [];

        foreach (self::EXCLUSIVE as $group) {
            $selected = array_values(array_intersect($group, $services));
            if (count($selected) > 1) {
                $conflicts[] = $selected;
            }
        }

        return $conflicts;
    }

    private function serviceOf(string $service): string
    {
        return self::ALIASES[$service] ?? $service;
    }

    /**
     * Persist the optional services selection, keeping the canonical order.
     * @param array<int, string> $services
     * @return array<int, string>
     */
    public function setEnabledOptional(array $services): array
    {
        $unknown = $this->unknown($services);
        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Unknown service(s): %s. Available: %s.',
                implode(', ', $unknown),
                implode(', ', array_keys(self::OPTIONAL))
            ));
        }

        $conflicts = $this->conflicts($services);
        if ($conflicts !== []) {
            throw new InvalidArgumentException(sprintf(
                'Only one of [%s] can be enabled.',
                implode(', ', $conflicts[0])
            ));
        }

        $services = array_values(array_intersect(array_keys(self::OPTIONAL), $services));
        $this->config->set(self::CONFIG_KEY, $services);

        return $services;
    }

    /**
     * Options offered by `valet install`; MySQL is hidden where only MariaDB is packaged.
     * @return array<string, string>
     */
    public function installOptions(bool $forcesMariaDB): array
    {
        return $forcesMariaDB ? array_diff_key(self::OPTIONAL, ['mysql' => true]) : self::OPTIONAL;
    }

    /**
     * Pre-checked options for `valet install`: the saved selection, with the database entry
     * matching the engine actually installed (or the one the distribution supports).
     * @return array<int, string>
     */
    public function installDefaults(?string $installedEngine, bool $forcesMariaDB): array
    {
        return $this->withDatabaseEngine($this->enabledOptional(), $installedEngine ?? ($forcesMariaDB ? 'mariadb' : null));
    }

    /**
     * Swap the selected database entry (mysql/mariadb) for the given engine; no-op when none is selected or engine is null.
     * @param array<int, string> $services
     * @return array<int, string>
     */
    public function withDatabaseEngine(array $services, ?string $engine): array
    {
        $databases = self::EXCLUSIVE[0];

        if ($engine === null || array_intersect($databases, $services) === []) {
            return array_values($services);
        }

        return array_values(array_intersect(
            array_keys(self::OPTIONAL),
            [...array_diff($services, $databases), $engine]
        ));
    }

    /**
     * Database engine selected (`mysql`, `mariadb`) or null.
     * @param array<int, string> $services
     */
    public function selectedDatabaseEngine(array $services): ?string
    {
        $selected = array_values(array_intersect(self::EXCLUSIVE[0], $services));

        return $selected[0] ?? null;
    }

    /**
     * Names that are not optional services.
     * @param array<int, string> $services
     * @return array<int, string>
     */
    public function unknown(array $services): array
    {
        return array_values(array_diff($services, array_keys(self::OPTIONAL)));
    }

    /**
     * Parse a comma separated `--services` value.
     * @return array<int, string>
     */
    public function parseList(string $list): array
    {
        return array_values(array_filter(array_map(
            static fn (string $service): string => strtolower(trim($service)),
            explode(',', $list)
        )));
    }

    /**
     * Restart the given services, or every enabled one when none is given.
     * @param array<int, string> $services
     */
    public function restart(array $services = []): void
    {
        $this->run($this->restartHandlers(), $services);
    }

    /**
     * Stop the given services, or every enabled stoppable one when none is given.
     * @param array<int, string> $services
     */
    public function stop(array $services = []): void
    {
        $this->run($this->stopHandlers(), $services);
    }

    /**
     * Services disabled at install time are skipped; a warning is shown only when they were explicitly requested.
     * @param array<string, callable(): void> $handlers
     * @param array<int, string> $services
     */
    private function run(array $handlers, array $services): void
    {
        $targets = $services === []
            ? array_keys($handlers)
            : array_values(array_unique(array_map(fn (string $service): string => $this->serviceOf($service), $services)));
        $enabled = $this->enabledServices();
        $skipped = [];

        foreach ($targets as $service) {
            if (!isset($handlers[$service])) {
                continue;
            }

            if (!in_array($service, $enabled, true)) {
                $skipped[] = $service;
                continue;
            }

            $handlers[$service]();
        }

        if ($services !== [] && $skipped !== []) {
            Writer::warn(sprintf(
                'Skipped service(s) not enabled during install: %s. Run `valet install` to enable them.',
                implode(', ', $skipped)
            ));
        }
    }

    /**
     * @return array<string, callable(): void>
     */
    private function restartHandlers(): array
    {
        return [
            'dnsmasq' => static fn () => DnsMasqFacade::restart(),
            'php'     => static fn () => PhpFpmFacade::restart(),
            'nginx'   => static fn () => NginxFacade::restart(),
            'mailpit' => static fn () => MailpitFacade::restart(),
            'mysql'   => static fn () => MysqlFacade::restart(),
            'redis'   => static fn () => ValetRedisFacade::restart(),
        ];
    }

    /**
     * DnsMasq is intentionally left running on `stop` (it backs system DNS resolution).
     * @return array<string, callable(): void>
     */
    private function stopHandlers(): array
    {
        return [
            'php'     => static fn () => PhpFpmFacade::stop(),
            'nginx'   => static fn () => NginxFacade::stop(),
            'mailpit' => static fn () => MailpitFacade::stop(),
            'mysql'   => static fn () => MysqlFacade::stop(),
            'redis'   => static fn () => ValetRedisFacade::stop(),
        ];
    }
}
