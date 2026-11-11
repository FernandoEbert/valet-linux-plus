<?php

namespace Valet;

use ConsoleComponents\Writer;
use DomainException;
use Valet\Facades\PhpFpm as PhpFpmFacade;
use Valet\Facades\SiteSecure as SiteSecureFacade;
use Valet\Traits\Paths;

/**
 * Per-project Nginx configuration.
 *
 * A site can ship its own server block at "<project>/nginx/<site>.<tld>.conf" using Valet placeholders.
 * Once activated (`valet nginx`), Valet renders that template every time it (re)generates the site config,
 * so `secure`, `unsecure`, `isolate`, `unisolate` and `domain` no longer discard the customization.
 */
class SiteNginxOverride
{
    use Paths;

    public const CONFIG_KEY = 'custom_nginx';
    public const STUB_HEADER = '# valet stub: custom.valet.conf';
    public const TEMPLATE_DIRECTORY = 'nginx';

    private Filesystem $files;
    private CommandLine $cli;
    private Configuration $config;
    private Site $site;

    public function __construct(Filesystem $files, CommandLine $cli, Configuration $config, Site $site)
    {
        $this->files = $files;
        $this->cli = $cli;
        $this->config = $config;
        $this->site = $site;
    }

    /**
     * Publish Valet's default config for the current state of the site into the project.
     * @throws DomainException
     */
    public function publish(string $url, bool $force = false): string
    {
        $target = $this->templateTargetPath($url);

        if ($this->files->exists($target) && !$force) {
            throw new DomainException(sprintf('[%s] already exists. Use --force to overwrite it.', $target));
        }

        $secured = $this->isSecured($url);
        $isolated = $this->isolatedPhpVersion($url) !== null;

        $stub = match (true) {
            $secured && $isolated => 'secure.isolated.valet.conf',
            $secured => 'secure.valet.conf',
            $isolated => 'isolated.valet.conf',
            default => 'site.valet.conf',
        };

        $this->files->ensureDirExists(dirname($target), user());
        $this->files->putAsUser($target, $this->stripMetadata($this->files->get(VALET_ROOT_PATH . '/cli/stubs/' . $stub)));

        return $target;
    }

    /**
     * Activate the project config for the site, validating it with `nginx -t` and rolling back on failure.
     * @throws DomainException
     */
    public function apply(string $url): string
    {
        $template = $this->templatePath($url);
        if ($template === null) {
            throw new DomainException(sprintf(
                'No project config found at [%s]. Run `valet nginx:publish` first.',
                $this->templateTargetPath($url)
            ));
        }

        $confPath = $this->nginxPath($url);
        $previous = $this->files->exists($confPath) ? $this->files->get($confPath) : null;
        $wasActive = $this->isActive($url);

        $this->activate($url);

        try {
            $this->sync($url);
            $this->validate();
        } catch (DomainException $exception) {
            $previous === null ? $this->files->unlink($confPath) : $this->files->putAsUser($confPath, $previous);
            if (!$wasActive) {
                $this->deactivate($url);
            }

            throw $exception;
        }

        return $template;
    }

    /**
     * Deactivate the project config and regenerate Valet's default config for the site.
     * The template file inside the project is kept.
     */
    public function reset(string $url): void
    {
        $phpVersion = $this->isolatedPhpVersion($url);
        $this->deactivate($url);

        $isolatedStub = $phpVersion === null ? null : strArrayReplace([
            'VALET_FPM_SOCKET_FILE'      => PhpFpmFacade::fpmSocketFile($phpVersion),
            'VALET_ISOLATED_PHP_VERSION' => $phpVersion,
        ], $this->files->get(VALET_ROOT_PATH . '/cli/stubs/' . ($this->isSecured($url) ? 'secure.' : '') . 'isolated.valet.conf'));

        if ($this->isSecured($url)) {
            $this->files->putAsUser($this->nginxPath($url), SiteSecureFacade::buildSecureNginxServer($url, $isolatedStub));

            return;
        }

        if ($isolatedStub !== null) {
            $this->files->putAsUser($this->nginxPath($url), SiteSecureFacade::buildUnsecureNginxServer($url, $isolatedStub));

            return;
        }

        $this->files->unlink($this->nginxPath($url));
    }

    /**
     * Re-render the project config over the config Valet just generated, when the site has an active override.
     * Must be called right after Valet writes (or removes) the site config, so the isolation state is preserved.
     * @throws DomainException
     */
    public function sync(string $url): bool
    {
        if (!$this->isActive($url)) {
            return false;
        }

        $template = $this->templatePath($url);
        if ($template === null) {
            return false;
        }

        $this->files->ensureDirExists($this->nginxPath(), user());
        $this->files->putAsUser(
            $this->nginxPath($url),
            $this->render($url, $this->files->get($template), $this->isolatedPhpVersion($url))
        );

        return true;
    }

    /**
     * Same as sync(), but an invalid project config never breaks the Valet command being run:
     * the config Valet generated is kept and a warning is shown.
     */
    public function syncOrWarn(string $url): void
    {
        try {
            $this->sync($url);
        } catch (DomainException $exception) {
            Writer::warn($exception->getMessage() . ' Keeping the default Valet config.');
        }
    }

    /**
     * Render a template with Valet placeholders.
     * @throws DomainException
     */
    public function render(string $url, string $template, ?string $isolatedPhpVersion = null): string
    {
        $template = $this->stripMetadata($template);

        $usesCertificate = str_contains($template, 'VALET_CERT') || str_contains($template, 'VALET_KEY');
        if ($usesCertificate && !$this->isSecured($url)) {
            throw new DomainException(sprintf(
                'The project config of [%s] uses TLS certificates, but the site is not secured. Run `valet secure` first.',
                $url
            ));
        }

        $httpsPort = $this->config->get('https_port', 443);
        $phpVersion = $isolatedPhpVersion ?? PhpFpmFacade::getCurrentVersion();

        $header = [self::STUB_HEADER, '# Generated from ' . $this->templateTargetPath($url) . '. Do not edit: run `valet nginx` instead.'];
        if ($isolatedPhpVersion !== null) {
            $header[] = '# ' . ISOLATED_PHP_VERSION . '=' . $isolatedPhpVersion;
        }

        return implode("\n", $header) . "\n\n" . strArrayReplace([
            'VALET_PROJECT_PATH'         => (string)$this->site->sitePath($url),
            'VALET_HOME_PATH'            => VALET_HOME_PATH,
            'VALET_SERVER_PATH'          => VALET_SERVER_PATH,
            'VALET_STATIC_PREFIX'        => VALET_STATIC_PREFIX,
            'VALET_SITE'                 => $url,
            'VALET_CERT'                 => $this->certificatesPath($url . '.crt'),
            'VALET_KEY'                  => $this->certificatesPath($url . '.key'),
            'VALET_HTTP_PORT'            => $this->config->get('port', 80),
            'VALET_HTTPS_PORT'           => $httpsPort,
            'VALET_REDIRECT_PORT'        => $httpsPort == 443 ? '' : ':' . $httpsPort,
            'VALET_FPM_SOCKET_FILE'      => PhpFpmFacade::fpmSocketFile($phpVersion),
            'VALET_ISOLATED_PHP_VERSION' => $phpVersion,
        ], $template);
    }

    /**
     * Get the project template path for the site, if it exists.
     */
    public function templatePath(string $url): ?string
    {
        if ($this->site->sitePath($url) === null) {
            return null;
        }

        $path = $this->templateTargetPath($url);

        return $this->files->exists($path) ? $path : null;
    }

    /**
     * Determine if the site has the project config activated.
     */
    public function isActive(string $url): bool
    {
        return in_array($this->siteName($url), $this->activeSites(), true);
    }

    /**
     * Path where the project template of the site lives (existing or not).
     * @throws DomainException
     */
    private function templateTargetPath(string $url): string
    {
        $sitePath = $this->site->sitePath($url);
        if ($sitePath === null) {
            throw new DomainException(sprintf("The [%s] site could not be found in Valet's site list.", $url));
        }

        return $sitePath . '/' . self::TEMPLATE_DIRECTORY . '/' . $url . '.conf';
    }

    /**
     * @throws DomainException
     */
    private function validate(): void
    {
        $this->cli->run('nginx -t', function (int $exitCode, string $output) {
            throw new DomainException("Nginx config test failed, changes were rolled back:\n" . trim($output));
        });
    }

    private function activate(string $url): void
    {
        $sites = $this->activeSites();
        $sites[] = $this->siteName($url);

        $this->config->set(self::CONFIG_KEY, array_values(array_unique($sites)));
    }

    private function deactivate(string $url): void
    {
        $sites = array_diff($this->activeSites(), [$this->siteName($url)]);

        $this->config->set(self::CONFIG_KEY, array_values($sites));
    }

    /**
     * @return array<int, string>
     */
    private function activeSites(): array
    {
        $sites = $this->config->get(self::CONFIG_KEY, []);

        return \is_array($sites) ? array_values(array_filter($sites, 'is_string')) : [];
    }

    /**
     * Site name without TLD, so the activation survives `valet domain`.
     */
    private function siteName(string $url): string
    {
        /** @var string $tld */
        $tld = $this->config->get('domain');

        return (string)preg_replace('/\.' . preg_quote($tld, '/') . '$/', '', $url);
    }

    private function isSecured(string $url): bool
    {
        return $this->files->exists($this->certificatesPath($url . '.crt'));
    }

    private function isolatedPhpVersion(string $url): ?string
    {
        if (!$this->files->exists($this->nginxPath($url))) {
            return null;
        }

        preg_match('/^# ' . ISOLATED_PHP_VERSION . '=(.+?)$/m', $this->files->get($this->nginxPath($url)), $matches);

        return isset($matches[1]) && trim($matches[1]) !== '' ? trim($matches[1]) : null;
    }

    /**
     * Remove Valet metadata comments; they are re-added by render() according to the current site state.
     */
    private function stripMetadata(string $conf): string
    {
        $conf = (string)preg_replace('/^# valet stub: .*\R/m', '', $conf);
        $conf = (string)preg_replace('/^# ' . ISOLATED_PHP_VERSION . '=.*\R/m', '', $conf);
        $conf = (string)preg_replace('/^# Generated from .*\R/m', '', $conf);

        return ltrim($conf);
    }
}
