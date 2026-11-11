<?php

declare(strict_types=1);

namespace Valet;

use Illuminate\Support\Collection;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;

/**
 * Builds a machine-readable snapshot of the Valet environment, consumed by `valet state --json`
 * (and by the desktop UI). Bump SCHEMA_VERSION on any breaking change to the payload.
 */
class StateReporter
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        private Configuration $config,
        private Filesystem $files,
        private CommandLine $cli,
        private PackageManager $pm,
        private ServiceManager $sm,
        private PhpFpm $phpFpm,
        private Site $site,
        private SiteLink $siteLink,
        private SiteProxy $siteProxy,
        private SiteSecure $siteSecure,
        private SiteIsolate $siteIsolate,
        private SiteNginxOverride $nginxOverride,
        private Mysql $mysql
    ) {
    }

    /**
     * Full environment snapshot.
     * @return array<string, mixed>
     */
    public function state(string $valetVersion): array
    {
        $globalPhp = $this->phpFpm->getCurrentVersion();
        $sites = $this->sites($globalPhp);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'valet_version'  => $valetVersion,
            'home'           => VALET_HOME_PATH,
            'domain'         => $this->configString('domain'),
            'port'           => $this->configString('port', '80'),
            'https_port'     => $this->configString('https_port', '443'),
            'php'            => $this->php($globalPhp),
            'paths'          => array_values((array)$this->config->get('paths', [])),
            'sites'          => $sites,
            'services'       => $this->services($globalPhp, $sites),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function php(string $globalPhp): array
    {
        $cliPath = $this->phpFpm->getPhpExecutablePath();

        return [
            'global'          => $globalPhp,
            'cli'             => [
                'path'    => $cliPath ?: null,
                'binary'  => $cliPath ? $this->files->realpath($cliPath) : null,
                'version' => $cliPath ? $this->binaryVersion($cliPath) : null,
            ],
            'fallback_binary' => $this->config->get('fallback_binary'),
            'installed'       => $this->installedPhpVersions(),
        ];
    }

    /**
     * Parked, linked and proxied sites in a single list, sorted by name.
     * @return array<int, array<string, mixed>>
     */
    private function sites(string $globalPhp): array
    {
        $tld = $this->configString('domain');
        $secured = $this->siteSecure->secured();
        $linked = $this->siteLink->links()->keys()->all();

        $sites = $this->site->servedSites()->map(function (string $path, int|string $name) use ($tld, $secured, $linked, $globalPhp) {
            $name = (string)$name;
            $domain = $name . '.' . $tld;
            $isSecure = $secured->contains($domain);
            $isolatedPhp = $this->normalize($this->siteIsolate->isolatedPhpVersion($domain));
            $phpRc = $this->phpRcVersion($path);

            return [
                'name'         => $name,
                'domain'       => $domain,
                'url'          => $this->url($domain, $isSecure),
                'path'         => $path,
                'type'         => \in_array($name, $linked, false) ? 'linked' : 'parked',
                'secure'       => $isSecure,
                'php'          => $isolatedPhp ?? $phpRc ?? $globalPhp,
                'isolated_php' => $isolatedPhp,
                'phprc_php'    => $phpRc,
                'custom_nginx' => $this->nginxOverride->isActive($domain),
                'proxy_host'   => null,
            ];
        });

        /** @var Collection<string, array{url: string, secured: string, path: string}> $proxies */
        $proxies = $this->siteProxy->proxies();
        $proxies = $proxies
            ->reject(fn (array $proxy, string $domain) => $sites->has(substr($domain, 0, -\strlen('.' . $tld))))
            ->map(function (array $proxy, string $domain) use ($tld, $secured) {
                $isSecure = $secured->contains($domain);

                return [
                    'name'         => substr($domain, 0, -\strlen('.' . $tld)),
                    'domain'       => $domain,
                    'url'          => $this->url($domain, $isSecure),
                    'path'         => null,
                    'type'         => 'proxy',
                    'secure'       => $isSecure,
                    'php'          => null,
                    'isolated_php' => null,
                    'phprc_php'    => null,
                    'custom_nginx' => false,
                    'proxy_host'   => $proxy['path'],
                ];
            });

        return $sites->values()
            ->merge($proxies->values())
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * Valet-managed services and whether they are running (null when not installed).
     * @param array<int, array<string, mixed>> $sites
     * @return array<int, array{name: string, unit: string, active: bool|null}>
     */
    private function services(string $globalPhp, array $sites): array
    {
        $phpVersions = collect($sites)
            ->pluck('isolated_php')
            ->push($globalPhp)
            ->filter()
            ->unique()
            ->sort(SORT_NATURAL)
            ->values();

        $services = collect([
            'nginx'   => 'nginx',
            'dnsmasq' => 'dnsmasq',
        ])->merge(
            $phpVersions->mapWithKeys(fn (string $version) => ['php' . $version => $this->pm->getPhpFpmName($version)])
        )->merge([
            'mysql'   => $this->mysql->serviceName(),
            'redis'   => $this->pm->packageName('redis'),
            'mailpit' => 'mailpit',
        ]);

        return $services->map(fn (string $unit, string $name) => [
            'name'   => $name,
            'unit'   => $unit,
            'active' => $this->sm->isActive($unit),
        ])->values()->all();
    }

    private function url(string $domain, bool $secure): string
    {
        $default = $secure ? '443' : '80';
        $port = $this->configString($secure ? 'https_port' : 'port', $default);

        return \sprintf('%s://%s%s', $secure ? 'https' : 'http', $domain, $port === $default ? '' : ':' . $port);
    }

    private function configString(string $key, string $default = ''): string
    {
        $value = $this->config->get($key, $default);

        return \is_scalar($value) ? (string)$value : $default;
    }

    private function phpRcVersion(string $sitePath): ?string
    {
        $path = $sitePath . '/.valetphprc';

        return $this->files->exists($path) ? $this->normalize(trim($this->files->get($path))) : null;
    }

    private function normalize(?string $version): ?string
    {
        if ($version === null) {
            return null;
        }

        return $this->phpFpm->normalizePhpVersion($version) ?: null;
    }

    private function binaryVersion(string $binary): ?string
    {
        $version = trim($this->cli->run(escapeshellarg($binary) . ' -r ' . escapeshellarg('echo PHP_VERSION;')));

        return preg_match('/^\d+\.\d+\.\d+/', $version) === 1 ? $version : null;
    }

    /**
     * PHP versions with a versioned binary in /usr/bin (php7.4, php8.3, php83...).
     * @return array<int, string>
     */
    private function installedPhpVersions(): array
    {
        /** @var array<int, string> $binaries */
        $binaries = glob('/usr/bin/php*') ?: [];

        return collect($binaries)
            ->map(fn (string $bin) => preg_match('/^php(\d)\.?(\d)$/', basename($bin), $m) ? $m[1] . '.' . $m[2] : '')
            ->filter(fn (string $version) => $version !== '')
            ->unique()
            ->sort(SORT_NATURAL)
            ->values()
            ->all();
    }
}
