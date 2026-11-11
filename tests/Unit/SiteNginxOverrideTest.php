<?php

namespace Valet\Tests\Unit;

use DomainException;
use Mockery;
use Mockery\MockInterface;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Filesystem;
use Valet\PhpFpm;
use Valet\Site;
use Valet\SiteNginxOverride;
use Valet\SiteSecure;
use Valet\Tests\TestCase;

use function Valet\resolve;
use function Valet\swap;

class SiteNginxOverrideTest extends TestCase
{
    private const URL = 'myapp.test';

    private Filesystem $files;
    private Configuration $config;
    private CommandLine|MockInterface $cli;
    private SiteNginxOverride $override;
    private string $projectPath;
    private string $templatePath;
    private string $confPath;

    public function setUp(): void
    {
        parent::setUp();

        $this->files = resolve(Filesystem::class);
        $this->config = resolve(Configuration::class);
        $this->cli = Mockery::mock(CommandLine::class);
        swap(CommandLine::class, $this->cli);

        $phpFpm = Mockery::mock(PhpFpm::class);
        $phpFpm->shouldReceive('getCurrentVersion')->andReturn('8.3');
        $phpFpm->shouldReceive('fpmSocketFile')->andReturnUsing(fn (string $version) => "/run/valet$version.sock");
        swap(PhpFpm::class, $phpFpm);

        $this->projectPath = VALET_HOME_PATH . '/Projects/myapp';
        $this->files->ensureDirExists($this->projectPath);
        $this->files->ensureDirExists(VALET_HOME_PATH . '/Sites');
        $this->files->ensureDirExists(VALET_HOME_PATH . '/Nginx');
        $this->files->ensureDirExists(VALET_HOME_PATH . '/Certificates');
        $this->files->symlink($this->projectPath, VALET_HOME_PATH . '/Sites/myapp');

        $this->templatePath = $this->projectPath . '/nginx/' . self::URL . '.conf';
        $this->confPath = VALET_HOME_PATH . '/Nginx/' . self::URL;

        $this->override = new SiteNginxOverride($this->files, $this->cli, $this->config, resolve(Site::class));
    }

    /**
     * @test
     */
    public function itPublishesTheDefaultStubForAPlainSite(): void
    {
        $path = $this->override->publish(self::URL);

        $this->assertSame($this->files->realpath($this->templatePath), $this->files->realpath($path));
        $this->assertSame(
            $this->files->get(VALET_ROOT_PATH . '/cli/stubs/site.valet.conf'),
            $this->files->get($path)
        );
    }

    /**
     * @test
     */
    public function itPublishesTheSecureIsolatedStubWithoutValetMetadata(): void
    {
        $this->files->put(VALET_HOME_PATH . '/Certificates/' . self::URL . '.crt', 'cert');
        $this->files->put($this->confPath, "# valet stub: secure.isolated.valet.conf\n\n# ISOLATED_PHP_VERSION=8.1\nserver {}\n");

        $published = $this->files->get($this->override->publish(self::URL));

        $this->assertStringContainsString('ssl_certificate "VALET_CERT";', $published);
        $this->assertStringContainsString('VALET_FPM_SOCKET_FILE', $published);
        $this->assertStringNotContainsString('# valet stub:', $published);
        $this->assertStringNotContainsString('ISOLATED_PHP_VERSION', $published);
    }

    /**
     * @test
     */
    public function itRefusesToOverwriteAPublishedConfigWithoutForce(): void
    {
        $this->files->ensureDirExists(dirname($this->templatePath));
        $this->files->put($this->templatePath, 'custom');

        try {
            $this->override->publish(self::URL);
            $this->fail('Expected DomainException.');
        } catch (DomainException) {
            $this->assertSame('custom', $this->files->get($this->templatePath));
        }

        $this->override->publish(self::URL, true);
        $this->assertNotSame('custom', $this->files->get($this->templatePath));
    }

    /**
     * @test
     */
    public function itAppliesTheProjectConfigReplacingPlaceholders(): void
    {
        $this->writeTemplate("server {\n    server_name VALET_SITE;\n    root VALET_PROJECT_PATH;\n    fastcgi_pass unix:VALET_FPM_SOCKET_FILE;\n}\n");
        $this->cli->shouldReceive('run')->once()->with('nginx -t', Mockery::type('callable'))->andReturn('');

        $this->override->apply(self::URL);

        $conf = $this->files->get($this->confPath);
        $this->assertStringStartsWith(SiteNginxOverride::STUB_HEADER, $conf);
        $this->assertStringContainsString('server_name myapp.test;', $conf);
        $this->assertStringContainsString('root ' . $this->files->realpath($this->projectPath) . ';', $conf);
        $this->assertStringContainsString('fastcgi_pass unix:/run/valet8.3.sock;', $conf);
        $this->assertStringNotContainsString(ISOLATED_PHP_VERSION, $conf);
        $this->assertTrue($this->override->isActive(self::URL));
        $this->assertSame(['myapp'], $this->config->get(SiteNginxOverride::CONFIG_KEY));
    }

    /**
     * @test
     */
    public function itFailsToApplyWithoutAProjectConfig(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('valet nginx:publish');

        $this->override->apply(self::URL);
    }

    /**
     * @test
     */
    public function itRefusesATlsTemplateForAnUnsecuredSite(): void
    {
        $this->writeTemplate("server {\n    ssl_certificate VALET_CERT;\n}\n");

        try {
            $this->override->apply(self::URL);
            $this->fail('Expected DomainException.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('valet secure', $exception->getMessage());
        }

        $this->assertFalse($this->files->exists($this->confPath));
        $this->assertFalse($this->override->isActive(self::URL));
    }

    /**
     * @test
     */
    public function itRollsBackWhenNginxConfigTestFails(): void
    {
        $this->files->put($this->confPath, "# valet stub: isolated.valet.conf\n\n# ISOLATED_PHP_VERSION=8.1\nserver {}\n");
        $original = $this->files->get($this->confPath);
        $this->writeTemplate("server { broken\n");

        $this->cli->shouldReceive('run')->once()->andReturnUsing(function (string $command, callable $onError) {
            $onError(1, 'nginx: [emerg] unexpected end of file');
            return '';
        });

        try {
            $this->override->apply(self::URL);
            $this->fail('Expected DomainException.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('unexpected end of file', $exception->getMessage());
        }

        $this->assertSame($original, $this->files->get($this->confPath));
        $this->assertFalse($this->override->isActive(self::URL));
    }

    /**
     * @test
     */
    public function itKeepsTheIsolatedVersionWhenSyncing(): void
    {
        $this->activate("server {\n    fastcgi_pass unix:VALET_FPM_SOCKET_FILE;\n}\n");
        $this->files->put($this->confPath, "# valet stub: isolated.valet.conf\n\n# ISOLATED_PHP_VERSION=8.1\nserver {}\n");

        $this->assertTrue($this->override->sync(self::URL));

        $conf = $this->files->get($this->confPath);
        $this->assertStringContainsString('# ISOLATED_PHP_VERSION=8.1', $conf);
        $this->assertStringContainsString('fastcgi_pass unix:/run/valet8.1.sock;', $conf);
        $this->assertSame(1, substr_count($conf, '# valet stub:'));
    }

    /**
     * @test
     */
    public function itDoesNotSyncInactiveSites(): void
    {
        $this->writeTemplate("server {}\n");

        $this->assertFalse($this->override->sync(self::URL));
        $this->assertFalse($this->files->exists($this->confPath));
    }

    /**
     * @test
     */
    public function itResetsToTheDefaultValetConfig(): void
    {
        $this->activate("server {}\n");
        $this->override->sync(self::URL);

        $this->override->reset(self::URL);

        $this->assertFalse($this->files->exists($this->confPath));
        $this->assertFalse($this->override->isActive(self::URL));
        $this->assertTrue($this->files->exists($this->templatePath));
    }

    /**
     * @test
     */
    public function itResetsToTheIsolatedValetConfig(): void
    {
        $this->activate("server {}\n");
        $this->files->put($this->confPath, "# ISOLATED_PHP_VERSION=8.1\nserver {}\n");
        $this->override->sync(self::URL);

        $this->override->reset(self::URL);

        $conf = $this->files->get($this->confPath);
        $this->assertStringStartsWith('# valet stub: isolated.valet.conf', $conf);
        $this->assertStringContainsString('# ISOLATED_PHP_VERSION=8.1', $conf);
        $this->assertStringContainsString('fastcgi_pass "unix:/run/valet8.1.sock";', $conf);
    }

    /**
     * @test
     */
    public function valetUnsecureReappliesTheActiveProjectConfig(): void
    {
        swap(SiteNginxOverride::class, $this->override);
        $this->activate("server {\n    server_name VALET_SITE;\n}\n");
        $this->files->put($this->confPath, 'stale');

        resolve(SiteSecure::class)->unsecure(self::URL, true);

        $this->assertStringContainsString('server_name myapp.test;', $this->files->get($this->confPath));
    }

    private function writeTemplate(string $contents): void
    {
        $this->files->ensureDirExists(dirname($this->templatePath));
        $this->files->put($this->templatePath, $contents);
    }

    private function activate(string $template): void
    {
        $this->writeTemplate($template);
        $this->config->set(SiteNginxOverride::CONFIG_KEY, ['myapp']);
    }
}
