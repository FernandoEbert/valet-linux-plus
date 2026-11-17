<?php

use ConsoleComponents\Writer;
use Illuminate\Container\Container;
use Silly\Application;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Valet\Drivers\ValetDriver;
use Valet\Exceptions\ConfigurationException;
use Valet\Facades\Configuration;
use Valet\Facades\DevTools;
use Valet\Facades\DnsMasq;
use Valet\Facades\Filesystem;
use Valet\Facades\Interact;
use Valet\Facades\Mailpit;
use Valet\Facades\Mysql;
use Valet\Facades\Nginx;
use Valet\Facades\Ngrok;
use Valet\Facades\PhpFpm;
use Valet\Facades\Requirements;
use Valet\Facades\Services;
use Valet\Facades\Site;
use Valet\Facades\SiteIsolate;
use Valet\Facades\SiteLink;
use Valet\Facades\SiteNginxOverride;
use Valet\Facades\SiteProxy;
use Valet\Facades\SiteSecure;
use Valet\Facades\StateReporter;
use Valet\Facades\Valet;
use Valet\Facades\ValetRedis;

/**
 * Load correct autoloader depending on install location.
 */
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/../../../autoload.php')) {
    require_once __DIR__ . '/../../../autoload.php';
} else {
    require_once getenv('HOME') . '/.composer/vendor/autoload.php';
}

/**
 * Create the application.
 */
Container::setInstance(new Container());
Container::getInstance()->singleton(\Valet\Interact::class);
$version = '2.2.0';

$app = new Application('ValetLinux+', $version);

/**
 * Detect environment.
 */
Valet::environmentSetup();
Valet::migrateConfig();

/**
 * Install valet required services
 */
$app->command('install [--ignore-selinux] [--mariadb] [--services=]', function (
    bool $ignoreSELinux,
    bool $mariadb,
    ?string $services,
    InputInterface $input
) {
    Interact::setInteractive($input->isInteractive());
    Writer::info('Installing valet services');

    passthru(dirname(__FILE__) . '/scripts/update.sh'); // Clean up cruft
    Requirements::setIgnoreSELinux($ignoreSELinux)->check();
    Configuration::install();

    $installedEngine = Mysql::installedEngine();
    $forcesMariaDB = Mysql::forcesMariaDB();

    $optional = $services !== null
        ? Services::parseList($services)
        : Interact::multiselect(
            label: 'Which optional services do you want to install? (Nginx, PHP-FPM and DnsMasq are always installed)',
            options: Services::installOptions($forcesMariaDB),
            default: Services::installDefaults($installedEngine, $forcesMariaDB),
            exclusive: \Valet\Services::EXCLUSIVE,
            hint: 'Space toggles an option. MySQL and MariaDB are mutually exclusive.',
        );

    if ($unknown = Services::unknown($optional)) {
        Writer::error(sprintf(
            'Unknown service(s): %s. Available: %s.',
            implode(', ', $unknown),
            implode(', ', array_keys(\Valet\Services::OPTIONAL))
        ));

        return 1;
    }

    if ($conflicts = Services::conflicts($optional)) {
        Writer::error(sprintf('Only one of [%s] can be installed.', implode(', ', $conflicts[0])));

        return 1;
    }

    // `--mariadb` keeps working as a shortcut, and MySQL-less distributions always get MariaDB.
    if ($mariadb && Services::selectedDatabaseEngine($optional) === null) {
        $optional[] = 'mariadb';
    }
    $optional = Services::withDatabaseEngine($optional, ($mariadb || $forcesMariaDB) ? 'mariadb' : null);

    $engine = Services::selectedDatabaseEngine($optional);
    if ($engine !== null && $installedEngine !== null && $engine !== $installedEngine) {
        Writer::warn(sprintf(
            '%s is already installed and will be kept; uninstall it first to switch to %s.',
            \Valet\Services::OPTIONAL[$installedEngine],
            \Valet\Services::OPTIONAL[$engine]
        ));
        $optional = Services::withDatabaseEngine($optional, $installedEngine);
        $engine = $installedEngine;
    }

    $optional = Services::setEnabledOptional($optional);

    Nginx::install();
    PhpFpm::install();
    DnsMasq::install(Configuration::get('domain'));

    if (in_array('mailpit', $optional, true)) {
        Mailpit::install();
    }

    if (in_array('redis', $optional, true)) {
        ValetRedis::install();
    }

    Nginx::restart();

    if ($engine !== null) {
        Mysql::install($engine === 'mariadb');
    }

    if (in_array('ngrok', $optional, true)) {
        Ngrok::install();
    }

    Valet::symlinkToUsersBin();

    Writer::info('Valet installed successfully!');

    $canLinkValetPhp = Writer::confirm('Do you want to link valet\'s php binary?', true);
    if ($canLinkValetPhp) {
        Valet::symlinkPhpToUsersBin();
    }

    if ($canLinkValetPhp) {
        Writer::info('Valet executable php helper is linked to /usr/local/bin/php.');
    }
})->descriptions('Install the Valet services', [
    '--ignore-selinux' => 'Skip SELinux checks',
    '--mariadb'        => 'Install MariaDB instead of MySQL (implies the database service)',
    '--services'       => 'Comma separated optional services to install, skipping the prompt (mailpit,mysql|mariadb,redis,ngrok); empty for none',
]);

/**
 * Most commands are available only if valet is installed.
 */
if (is_dir(VALET_HOME_PATH)) {
    /**
     * Prune missing directories and symbolic links on every command.
     */
    try {
        Configuration::prune();
    } catch (ConfigurationException $exception) {
        Writer::error($exception->getMessage());

        exit(1);
    }
    Site::pruneLinks();

    /**
     * Start the daemon services.
     */
    $app->command('start [services]*', function (array $services) {
        Services::restart($services);

        Writer::info($services === [] ? 'Valet services have been started.' : 'Specified Valet services have been started.');
    })->descriptions('Start the Valet services');

    /**
     * Restart the daemon services.
     */
    $app->command('restart [services]*', function (array $services) {
        Services::restart($services);

        Writer::info($services === [] ? 'Valet services have been restarted.' : 'Specified Valet services have been restarted.');
    })->descriptions('Restart the Valet services');

    /**
     * Stop the daemon services.
     */
    $app->command('stop [services]*', function (array $services) {
        Services::stop($services);

        Writer::info($services === [] ? 'Valet services have been stopped.' : 'Specified Valet services have been stopped.');
    })->descriptions('Stop the Valet services');

    /**
     * Uninstall Valet entirely.
     */
    $app->command('uninstall', function () {
        Nginx::uninstall();
        PhpFpm::uninstall();
        DnsMasq::uninstall();
        Mailpit::uninstall();
        Configuration::uninstall();
        Valet::uninstall();

        Writer::info('Valet has been uninstalled.');
    })->descriptions('Uninstall the Valet services');

    /**
     * Render the services table shared by `status` and `state`.
     * @param array<int, array{name: string, unit: string, active: bool|null}> $services
     */
    $renderServices = static function (array $services): void {
        Writer::table(['Service', 'Unit', 'Status'], array_map(static fn (array $service): array => [
            $service['name'],
            $service['unit'],
            match ($service['active']) {
                true => 'running',
                false => 'stopped',
                null => 'not installed',
            },
        ], $services));
    };

    /**
     * Display the status of the Valet services.
     */
    $app->command('status', function () use ($renderServices) {
        $renderServices(StateReporter::enabledServices());
    })->descriptions('View Valet service status');

    /**
     * Snapshot of the whole Valet environment (services, PHP, paths and sites).
     */
    $app->command('state [--json]', function (bool $json, OutputInterface $output) use ($version, $renderServices) {
        $state = StateReporter::state($version);

        if ($json) {
            $output->writeln(
                json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                OutputInterface::OUTPUT_RAW
            );

            return;
        }

        Writer::twoColumnDetail('Domain', $state['domain']);
        Writer::twoColumnDetail('PHP (global)', $state['php']['global']);
        Writer::twoColumnDetail('PHP (CLI)', (string)($state['php']['cli']['version'] ?? '-'));

        $renderServices($state['services']);

        Writer::table(['Site', 'Type', 'PHP', 'SSL', 'Path'], array_map(fn (array $site) => [
            $site['url'],
            $site['type'],
            $site['php'] ?? '-',
            $site['secure'] ? '✓' : '✕',
            $site['path'] ?? $site['proxy_host'],
        ], $state['sites']));
    })->descriptions('Display a snapshot of the Valet environment', [
        '--json' => 'Output the snapshot as JSON (schema documented by its "schema_version" key)',
    ]);

    /**
     * Determine if this is the latest release of Valet.
     */
    $app->command('is-latest', function () use ($version) {
        if (Valet::onLatestVersion($version)) {
            Writer::info('YES');
        } else {
            Writer::info('NO');
        }
    })->descriptions('Determine if this is the latest version of Valet');

    /**
     * Determine if this is the latest release of Valet.
     */
    $app->command('update', function () use ($version) {
        $script = dirname(__FILE__) . '/scripts/update.sh';

        if (Valet::onLatestVersion($version)) {
            Writer::info('You have the latest version of Valet Linux+');
            passthru($script);
        } else {
            Writer::warn('There is a new release of Valet Linux+');
            Writer::warn('Updating now...');
            $latestVersion = Valet::getLatestVersion();
            if ($latestVersion) {
                passthru($script . " update $latestVersion");
            } else {
                passthru($script . ' update');
            }
        }
    })->descriptions('Update Valet Linux+ and clean up cruft');

    /**
     * Get or set the domain currently being used by Valet.
     */
    $app->command('domain [domain]', function ($domain = null) {
        if ($domain === null) {
            Writer::info(sprintf('Your current Valet domain is [%s].', Configuration::get('domain')));

            return;
        }

        DnsMasq::updateDomain($domain = trim($domain, '.'));
        $oldDomain = Configuration::get('domain');

        Configuration::set('domain', $domain);
        SiteSecure::reSecureForNewDomain($oldDomain, $domain);
        PhpFpm::restart();
        Nginx::restart();

        Writer::info('Your Valet domain has been updated to [' . $domain . '].');
    })->descriptions('Get or set the domain used for Valet sites');

    /**
     * Get or set the port number currently being used by Valet.
     */
    $app->command('port [port] [--https]', function ($port, $https) {
        if ($port === null) {
            Writer::info('Current Nginx port (HTTP): ' . Configuration::get('port', 80));
            Writer::info('Current Nginx port (HTTPS): ' . Configuration::get('https_port', 443));

            return;
        }

        $port = trim($port);

        if ($https) {
            Configuration::set('https_port', $port);
        } else {
            Nginx::updatePort($port);
            Configuration::set('port', $port);
        }

        SiteSecure::regenerateSecuredSitesConfig();

        Nginx::restart();
        PhpFpm::restart();

        $protocol = $https ? 'HTTPS' : 'HTTP';
        Writer::info("Your Nginx $protocol port has been updated to [$port].");
    })->descriptions('Get or set the port number used for Valet sites');

    /**
     * Determine which Valet driver the current directory is using.
     */
    $app->command('which', function () {
        $driver = ValetDriver::assign(getcwd(), basename(getcwd()), '/');

        if ($driver) {
            Writer::info('This site is served by [' . get_class($driver) . '].');
        } else {
            Writer::warn('Valet could not determine which driver to use for this site.');
        }
    })->descriptions('Determine which Valet driver serves the current working directory');

    /**
     * Add the current working directory to paths configuration.
     */
    $app->command('park [path]', function ($path = null) {
        $path = $path ?: getcwd();
        Configuration::addPath($path);

        Writer::info("The [$path] directory has been added to Valet's paths.");
    })->descriptions('Register the current working (or specified) directory with Valet');

    /**
     * Display all the registered paths.
     */
    $app->command('paths', function () {
        $paths = Configuration::get('paths');

        if (count($paths) > 0) {
            $paths = array_map(function ($path) {
                return [$path];
            }, $paths);

            Writer::table(['Path'], $paths);
        } else {
            Writer::warn('No paths have been registered.');
        }
    })->descriptions('Get all of the paths registered with Valet');

    /**
     * Remove the current working directory from paths configuration.
     */
    $app->command('forget [path]', function ($path = null) {
        $path = $path ?: getcwd();
        Configuration::removePath($path);

        Writer::info("The [$path] directory has been removed from Valet's paths.");
    })->descriptions('Remove the current working (or specified) directory from Valet\'s list of paths');

    /**
     * Create Nginx proxy config for the specified domain.
     */
    $app->command('proxy [domain] [host] [--secure]', function ($domain, $host, $secure) {
        if ($domain === null) {
            Writer::error('Please provide domain');
            return;
        }

        if ($host === null) {
            Writer::error('Please provide host');
            return;
        }

        if (!preg_match('~^https?://.*$~', $host)) {
            Writer::error(sprintf('"%s" is not a valid URL', $host));
            return;
        }

        $tld = Configuration::get('domain');

        if (!str_ends_with($domain, '.' . $tld)) {
            $domain .= '.' . $tld;
        }

        SiteProxy::proxyCreate($domain, $host, $secure);
        Nginx::restart();

        $protocol = $secure ? 'https' : 'http';

        Writer::info('Valet will now proxy [' . $protocol . '://' . $domain . '] traffic to [' . $host . '].');
    })->descriptions('Create an Nginx proxy site for the specified host. Useful for docker, node etc.', [
        '--secure' => 'Create a proxy with a trusted TLS certificate',
    ]);

    /**
     * Delete Nginx proxy config.
     */
    $app->command('unproxy [domain]', function ($domain) {
        if ($domain === null) {
            Writer::error('Please provide domain');
            return;
        }

        $tld = Configuration::get('domain');
        if (!str_ends_with($domain, '.' . $tld)) {
            $domain .= '.' . $tld;
        }

        SiteSecure::unsecure($domain);
        Nginx::restart();

        Writer::info('Valet will no longer proxy [' . $domain . '].');
    })->descriptions('Delete an Nginx proxy config.');

    /**
     * Display all the sites that are proxies.
     */
    $app->command('proxies', function () {
        $proxies = SiteProxy::proxies();

        Writer::table(['URL', 'SSL', 'Host'], $proxies->all());
    })->descriptions('Display all of the proxy sites');

    /**
     * Register a symbolic link with Valet.
     */
    $app->command('link [name]', function ($name) {
        $name = $name ?: basename(getcwd());
        $linkPath = SiteLink::link(getcwd(), $name);

        Writer::info('A [' . $name . '] symbolic link has been created in [' . $linkPath . '].');
    })->descriptions('Link the current working directory to Valet');

    /**
     * Unlink a link from the Valet links directory.
     */
    $app->command('unlink [name]', function ($name) {
        $name = $name ?: basename(getcwd());
        SiteLink::unlink($name);

        Writer::info('The [' . $name . '] symbolic link has been removed.');
    })->descriptions('Remove the specified Valet link');

    /**
     * Display all the registered symbolic links.
     */
    $app->command('links', function () {
        $links = SiteLink::links();

        Writer::table(['URL', 'SSL', 'Path'], $links->all());
    })->descriptions('Display all of the registered Valet links');

    /**
     * Secure the given domain with a trusted TLS certificate.
     */
    $app->command('secure [domain]', function ($domain = null) {
        $url = ($domain ?: basename(getcwd()));
        $url = Configuration::parseDomain($url);

        SiteSecure::secure($url);
        Nginx::restart();

        Writer::info('The [' . $url . '] site has been secured with a fresh TLS certificate.');
    })->descriptions('Secure the given domain with a trusted TLS certificate');

    /**
     * Stop serving the given domain over HTTPS and remove the trusted TLS certificate.
     */
    $app->command('unsecure [domain]', function ($domain = null) {
        $url = ($domain ?: basename(getcwd()));
        $url = Configuration::parseDomain($url);

        SiteSecure::unsecure($url, true);
        Nginx::restart();

        Writer::info('The [' . $url . '] site will now serve traffic over HTTP.');
    })->descriptions('Stop serving the given domain over HTTPS and remove the trusted TLS certificate');

    /**
     * Determine if the site is secured or not.
     */
    $app->command('secured [site]', function ($site) {
        $site = $site ?: basename(getcwd());
        $site = Configuration::parseDomain($site);

        if (SiteSecure::secured()->contains($site)) {
            Writer::info("$site is secured.");
            return;
        }

        Writer::info("$site is not secured.");
    })->descriptions('Determine if the site is secured or not');

    /**
     * Publish Valet's default Nginx config of the site into the project, to be customized.
     */
    $app->command('nginx:publish [site] [--force]', function ($site, $force) {
        try {
            $url = Site::getSiteUrl($site ?: '.');
            $path = SiteNginxOverride::publish($url, (bool)$force);
        } catch (\DomainException $exception) { // @phpstan-ignore catch.neverThrown (thrown behind the facade)
            Writer::error($exception->getMessage());
            return 1;
        }

        Writer::info(sprintf('Nginx config of [%s] published to [%s].', $url, $path));
        Writer::info('Customize it and run `valet nginx` to activate it.');

        return 0;
    })->descriptions('Publish the default Nginx config of the site into "<project>/nginx/<site>.conf"', [
        'site'    => 'The site to publish (defaults to the current directory)',
        '--force' => 'Overwrite the project config if it already exists',
    ]);

    /**
     * Activate the project Nginx config of the site.
     */
    $app->command('nginx [site]', function ($site) {
        try {
            $url = Site::getSiteUrl($site ?: '.');
            $path = SiteNginxOverride::apply($url);
        } catch (\DomainException $exception) { // @phpstan-ignore catch.neverThrown (thrown behind the facade)
            Writer::error($exception->getMessage());
            return 1;
        }

        Nginx::restart();

        Writer::info(sprintf('The [%s] site is now served by [%s].', $url, $path));

        return 0;
    })->descriptions('Activate the project Nginx config ("<project>/nginx/<site>.conf") of the site', [
        'site' => 'The site to activate (defaults to the current directory)',
    ]);

    /**
     * Deactivate the project Nginx config of the site.
     */
    $app->command('nginx:reset [site]', function ($site) {
        try {
            $url = Site::getSiteUrl($site ?: '.');
        } catch (\DomainException $exception) { // @phpstan-ignore catch.neverThrown (thrown behind the facade)
            Writer::error($exception->getMessage());
            return 1;
        }

        SiteNginxOverride::reset($url);
        Nginx::restart();

        Writer::info(sprintf('The [%s] site is now served by the default Valet config.', $url));

        return 0;
    })->descriptions('Deactivate the project Nginx config of the site and restore the default Valet config', [
        'site' => 'The site to reset (defaults to the current directory)',
    ]);

    /**
     * Change the PHP version to the desired one.
     */
    $app->command('use [preferredVersion] [--update-cli] [--ignore-ext]', function (
        $preferredVersion = null,
        $updateCli = null,
        $ignoreExt = null
    ) {
        $preferredVersion = PhpFpm::normalizePhpVersion($preferredVersion);
        $isValid = PhpFpm::validateVersion($preferredVersion);
        if (!$isValid) {
            Writer::error(
                sprintf(
                    "Invalid version [%s] used. Supported versions are: %s",
                    $preferredVersion,
                    implode(', ', \Valet\PhpFpm::SUPPORTED_PHP_VERSIONS)
                )
            );
            Writer::info(
                sprintf(
                    'You can still use any version from [%s] list using `valet isolate` command',
                    implode(', ', \Valet\PhpFpm::ISOLATION_SUPPORTED_PHP_VERSIONS)
                )
            );
            return;
        }

        PhpFpm::switchVersion($preferredVersion, $updateCli, $ignoreExt);
        Writer::info(sprintf('PHP version successfully changed to [%s]', $preferredVersion));
    })->descriptions(
        sprintf(
            'Set the PHP version to use, enter "default" or leave empty to use version: %s',
            PHP_VERSION
        ),
        [
            '--update-cli'    => 'Updates CLI version as well',
            '--ignore-ext'    => 'Installs extension with selected php version',
        ]
    );

    /**
     * List MySQL Database.
     */
    $app->command('db:list', function () {
        $databases = Mysql::getDatabases();

        Writer::table(['Database'], $databases);
    })->descriptions('List all available database in MySQL/MariaDB');

    /**
     * Create new database in MySQL.
     */
    $app->command('db:create [databaseName]', function ($databaseName) {
        $databaseName = $databaseName ?: basename((string)getcwd());

        $isCreated = Mysql::createDatabase($databaseName);
        if ($isCreated) {
            Writer::info(sprintf('Database [%s] created successfully', $databaseName));
        }
    })->descriptions('Create new database in MySQL/MariaDB');

    /**
     * Drop database in MySQL.
     */
    $app->command('db:drop [databaseName] [-y|--yes]', function ($databaseName, $yes) {
        $databaseName = $databaseName ?: basename((string)getcwd());

        if (!$yes) {
            $confirm = Writer::confirm(sprintf('Are you sure you want to delete [%s] database?', $databaseName));
            if (!$confirm) {
                Writer::warn('Aborted');

                return;
            }
        }
        $isDropped = Mysql::dropDatabase($databaseName);
        if ($isDropped) {
            Writer::info(sprintf('Database [%s] dropped successfully', $databaseName));
        }
    })->descriptions('Drop given database from MySQL/MariaDB');

    /**
     * Reset database in MySQL.
     */
    $app->command('db:reset [databaseName] [-y|--yes]', function ($databaseName, $yes) {
        $databaseName = $databaseName ?: basename((string)getcwd());

        if (!$yes) {
            $confirm = Writer::confirm(sprintf('Are you sure you want to reset [%s] database?', $databaseName));
            if (!$confirm) {
                Writer::warn('Aborted');

                return;
            }
        }
        $dropDB = Mysql::dropDatabase($databaseName);
        if (!$dropDB) {
            Writer::warn('Error resetting database');

            return;
        }

        $isCreated = Mysql::createDatabase($databaseName);

        if (!$isCreated) {
            Writer::warn('Error resetting database');

            return;
        }

        Writer::info(sprintf('Database [%s] reset successfully', $databaseName));
    })->descriptions('Clear all tables for given database in MySQL/MariaDB');

    /**
     * Import database in MySQL.
     *
     * @throws Exception
     */
    $app->command('db:import [databaseName] [dumpFile]', function ($databaseName, $dumpFile) {
        if (!$databaseName) {
            Writer::error('Please provide database name');
            return;
        }
        if (!$dumpFile) {
            Writer::error('Please provide a dump file path');
            return;
        }

        if (!Filesystem::exists($dumpFile)) {
            Writer::error(sprintf('Unable to locate [%s]', $dumpFile));
            return;
        }
        Writer::info('Importing database...');

        Mysql::importDatabase($dumpFile, $databaseName);

        Writer::info(sprintf('Database [%s] imported successfully', $databaseName));
    })->descriptions('Import dump file for selected database in MySQL/MariaDB');

    /**
     * Export database in MySQL.
     */
    $app->command('db:export [databaseName] [--sql]', function ($databaseName, $sql) {
        Writer::info('Exporting database...');
        $databaseName = $databaseName ?: basename((string)getcwd());

        $data = Mysql::exportDatabase($databaseName, $sql);

        Writer::info(sprintf("Database [%s] exported into file %s", $data['database'], $data['filename']));
    })->descriptions('Export selected MySQL/MariaDB database');

    /**
     * Configure valet database user for MySQL/MariaDB.
     */
    $app->command('db:configure [--force]', function ($force) {
        Mysql::configure($force);
    })->descriptions('Configure valet database user for MySQL/MariaDB');

    /**
     * Visual Studio Code IDE Helper Command.
     */
    $app->command('code [folder]', function ($folder) {
        $folder = $folder ?: getcwd();
        DevTools::run($folder, \Valet\DevTools::VS_CODE);
    })->descriptions('Open project in Visual Studio Code');

    /**
     * PHPStorm IDE Helper Command.
     */
    $app->command('ps [folder]', function ($folder) {
        $folder = $folder ?: getcwd();
        DevTools::run($folder, \Valet\DevTools::PHP_STORM);
    })->descriptions('Open project in PHPStorm');

    /**
     * Atom IDE Helper Command.
     */
    $app->command('atom [folder]', function ($folder) {
        $folder = $folder ?: getcwd();
        DevTools::run($folder, \Valet\DevTools::ATOM);
    })->descriptions('Open project in Atom');

    /**
     * Sublime IDE Helper Command.
     */
    $app->command('subl [folder]', function ($folder) {
        $folder = $folder ?: getcwd();
        DevTools::run($folder, \Valet\DevTools::SUBLIME);
    })->descriptions('Open project in Sublime');

    /**
     * Allow the user to change the version of PHP Valet uses to serve the current site.
     */
    $app->command('isolate [phpVersion] [--site=] [--secure]', function (
        ?string $phpVersion,
        ?string $site,
        bool $secure,
        InputInterface $input
    ) {
        Interact::setInteractive($input->isInteractive());
        $currentSite = $site ?: basename((string)getcwd());

        if ($phpVersion === null && $phpVersion = Site::phpRcVersion($currentSite)) {
            Writer::info("Found '$currentSite/.valetphprc' specifying version: $phpVersion");
        }

        if ($phpVersion === null && Interact::isInteractive()) {
            // Preselect the version the site is already isolated with, falling back to Valet's global one.
            $isolatedVersion = SiteIsolate::isolatedPhpVersion(Configuration::parseDomain($currentSite));
            $phpVersion = Interact::select(
                label: 'Which PHP version would you like to use?',
                options: \Valet\PhpFpm::ISOLATION_SUPPORTED_PHP_VERSIONS,
                default: in_array($isolatedVersion, \Valet\PhpFpm::ISOLATION_SUPPORTED_PHP_VERSIONS, true)
                    ? $isolatedVersion
                    : PhpFpm::getCurrentVersion(),
                hint: $isolatedVersion !== null ? "[$currentSite] is currently isolated with PHP $isolatedVersion." : '',
            );
        }

        if ($phpVersion === null) {
            Writer::warn('Please select version to isolate');

            return;
        }

        if ($site) {
            $sites = [$site];
        } else {
            $servedSites = Site::servedSites()->keys()->map(fn (int|string $name): string => (string)$name)->all();
            $sites = Interact::multiselect(
                label: 'Which sites would you like to isolate?',
                options: $servedSites,
                default: (in_array($currentSite, $servedSites, true) || !Interact::isInteractive()) ? [$currentSite] : [],
            );
        }

        if ($sites === []) {
            Writer::warn('No site selected to isolate');

            return;
        }

        $secured = SiteSecure::secured();

        foreach ($sites as $siteName) {
            $siteName = (string)$siteName;
            $isSecure = $secure || $secured->contains(Configuration::parseDomain($siteName));

            if (SiteIsolate::isolateDirectory($siteName, $phpVersion, $isSecure)) {
                Writer::info(sprintf('The site [%s] is now using %s.', $siteName, $phpVersion));
            }
        }
    })->descriptions('Change the version of PHP used by Valet to serve the current working directory', [
        'phpVersion' => 'The PHP version you want to use; e.g php@8.1',
        '--site'       => 'Specify the site to isolate (e.g. if the site isn\'t linked as its directory name)',
        '--secure'   => 'Create a isolated site with a trusted TLS certificate',
    ]);

    /**
     * Allow the user to un-do specifying the version of PHP Valet uses to serve the current site.
     */
    $app->command('unisolate [--site=]', function ($site = null) {
        if (!$site) {
            $site = basename((string)getcwd());
        }

        SiteIsolate::unIsolateDirectory($site);

        Writer::info(sprintf('The site [%s] is now using the default PHP version.', $site));
    })->descriptions('Stop customizing the version of PHP used by Valet to serve the current working directory', [
        '--site' => 'Specify the site to un-isolate (e.g. if the site isn\'t linked as its directory name)',
    ]);

    /**
     * List isolated sites.
     */
    $app->command('isolated', function () {
        $sites = SiteIsolate::isolatedDirectories();

        Writer::table(['URL', 'SSL', 'PHP Version'], $sites->all());
    })->descriptions('List all sites using isolated versions of PHP.');

    /**
     * Get the PHP executable path for a site.
     */
    $app->command('which-php [site]', function ($site) {
        $site = basename($site ?: (string)getcwd());
        $domain = Configuration::parseDomain($site);
        $phpVersion = SiteIsolate::isolatedPhpVersion($domain);

        if (!$phpVersion) {
            $phpVersion = Site::phpRcVersion($site ?: basename(getcwd()));
        }

        echo PhpFpm::getPhpExecutablePath($phpVersion);
    })->descriptions('Get the PHP executable path for a given site', [
        'site' => 'The site to get the PHP executable path for',
    ]);

    /**
     * Proxy commands through to an isolated site's version of PHP.
     */
    $app->command('php [--site=] [command]', function () {
        Writer::warn(
            'It looks like you are running `cli/valet.php` directly;
            please use the `valet` script in the project root instead.'
        );
    })->descriptions("Proxy PHP commands with isolated site's PHP executable", [
        'command' => "Command to run with isolated site's PHP executable",
        '--site'  => 'Specify the site to use to get the PHP version',
    ]);

    /**
     * Proxy commands through to an isolated site's version of Composer.
     */
    $app->command('composer [--site=] [command]', function () {
        Writer::warn('It looks like you are running `cli/valet.php` directly;
        please use the `valet` script in the project root instead.');
    })->descriptions("Proxy Composer commands with isolated site's PHP executable", [
        'command' => "Composer command to run with isolated site's PHP executable",
        '--site'  => 'Specify the site to use to get the PHP version',
    ]);

    /**
     * Open the current directory in the browser.
     */
    $app->command('open [domain]', function ($domain = null) {
        $url = sprintf(
            'http://%s.%s/',
            $domain ?: basename(getcwd()),
            Configuration::get('domain')
        );

        passthru('xdg-open ' . escapeshellarg($url));
    })->descriptions('Open the site for the current (or specified) directory in your browser');

    /**
     * Generate a publicly accessible URL for your project.
     */
    $app->command('share', function () {
        Writer::warn(
            'It looks like you are running `cli/valet.php` directly,
            please use the `valet` script in the project root instead.'
        );
    })->descriptions('Generate a publicly accessible URL for your project');

    /**
     * Echo the currently tunneled URL.
     */
    $app->command('fetch-share-url', function () {
        echo Ngrok::currentTunnelUrl();
    })->descriptions('Get the URL to the current Ngrok tunnel');

    /**
     * Set authentication token in Ngrok.
     */
    $app->command('ngrok-auth [authtoken]', function ($authtoken) {
        if (!$authtoken) {
            Writer::error('Please provide ngrok auth token');
            return;
        }

        Ngrok::setAuthToken($authtoken);

        Writer::info('Ngrok authentication token set.');
    })->descriptions('Set authentication token for ngrok');
}

/**
 * Load all Valet extensions.
 */
foreach (Valet::extensions() as $extension) {
    include_once $extension;
}
