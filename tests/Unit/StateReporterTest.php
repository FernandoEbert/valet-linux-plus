<?php

namespace Valet\Tests\Unit;

use Mockery;
use Mockery\MockInterface;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;
use Valet\Filesystem;
use Valet\Mysql;
use Valet\PhpFpm;
use Valet\Site;
use Valet\SiteIsolate;
use Valet\SiteLink;
use Valet\SiteNginxOverride;
use Valet\SiteProxy;
use Valet\SiteSecure;
use Valet\StateReporter;
use Valet\Tests\TestCase;

class StateReporterTest extends TestCase
{
    private Configuration|MockInterface $config;
    private Filesystem|MockInterface $files;
    private CommandLine|MockInterface $cli;
    private PackageManager|MockInterface $pm;
    private ServiceManager|MockInterface $sm;
    private PhpFpm|MockInterface $phpFpm;
    private Site|MockInterface $site;
    private SiteLink|MockInterface $siteLink;
    private SiteProxy|MockInterface $siteProxy;
    private SiteSecure|MockInterface $siteSecure;
    private SiteIsolate|MockInterface $siteIsolate;
    private SiteNginxOverride|MockInterface $nginxOverride;
    private Mysql|MockInterface $mysql;
    private StateReporter $reporter;

    public function setUp(): void
    {
        parent::setUp();

        $this->config = Mockery::mock(Configuration::class);
        $this->files = Mockery::mock(Filesystem::class);
        $this->cli = Mockery::mock(CommandLine::class);
        $this->pm = Mockery::mock(PackageManager::class);
        $this->sm = Mockery::mock(ServiceManager::class);
        $this->phpFpm = Mockery::mock(PhpFpm::class);
        $this->site = Mockery::mock(Site::class);
        $this->siteLink = Mockery::mock(SiteLink::class);
        $this->siteProxy = Mockery::mock(SiteProxy::class);
        $this->siteSecure = Mockery::mock(SiteSecure::class);
        $this->siteIsolate = Mockery::mock(SiteIsolate::class);
        $this->nginxOverride = Mockery::mock(SiteNginxOverride::class);
        $this->mysql = Mockery::mock(Mysql::class);

        $this->reporter = new StateReporter(
            $this->config,
            $this->files,
            $this->cli,
            $this->pm,
            $this->sm,
            $this->phpFpm,
            $this->site,
            $this->siteLink,
            $this->siteProxy,
            $this->siteSecure,
            $this->siteIsolate,
            $this->nginxOverride,
            $this->mysql
        );

        $this->config->shouldReceive('get')->with('domain', '')->andReturn('test');
        $this->config->shouldReceive('get')->with('port', '80')->andReturn('80');
        $this->config->shouldReceive('get')->with('https_port', '443')->andReturn('443');
        $this->config->shouldReceive('get')->with('paths', [])->andReturn(['/home/user/sites']);
        $this->config->shouldReceive('get')->with('fallback_binary')->andReturn(null);

        $this->phpFpm->shouldReceive('getCurrentVersion')->andReturn('8.3');
        $this->phpFpm->shouldReceive('getPhpExecutablePath')->withNoArgs()->andReturn('/usr/bin/php');
        $this->phpFpm->shouldReceive('normalizePhpVersion')->andReturnUsing(
            fn (string $version) => preg_match('/(\d)\.?(\d)/', $version, $m) ? $m[1] . '.' . $m[2] : ''
        );
        $this->files->shouldReceive('realpath')->with('/usr/bin/php')->andReturn('/usr/bin/php8.3');
        $this->cli->shouldReceive('run')->andReturn('8.3.10');

        $this->site->shouldReceive('servedSites')->andReturn(collect([
            'blog'  => '/home/user/sites/blog',
            'shop'  => '/home/user/projects/shop',
            'legacy' => '/home/user/sites/legacy',
        ]));
        $this->siteLink->shouldReceive('links')->andReturn(collect([
            'shop' => ['url' => 'https://shop.test', 'secured' => '✓', 'path' => '/home/user/projects/shop'],
        ]));
        $this->siteProxy->shouldReceive('proxies')->andReturn(collect([
            'mails.test' => ['url' => 'https://mails.test', 'secured' => '✓', 'path' => 'http://localhost:8025'],
        ]));
        $this->siteSecure->shouldReceive('secured')->andReturn(collect(['shop.test', 'mails.test']));

        $this->siteIsolate->shouldReceive('isolatedPhpVersion')->with('legacy.test')->andReturn('php7.4');
        $this->siteIsolate->shouldReceive('isolatedPhpVersion')->andReturn(null);

        $this->files->shouldReceive('exists')->with('/home/user/sites/blog/.valetphprc')->andReturn(true);
        $this->files->shouldReceive('get')->with('/home/user/sites/blog/.valetphprc')->andReturn("php8.2\n");
        $this->files->shouldReceive('exists')->with('/home/user/sites/legacy/.valetphprc')->andReturn(true);
        $this->files->shouldReceive('get')->with('/home/user/sites/legacy/.valetphprc')->andReturn('8.1');
        $this->files->shouldReceive('exists')->andReturn(false);

        $this->nginxOverride->shouldReceive('isActive')->with('shop.test')->andReturn(true);
        $this->nginxOverride->shouldReceive('isActive')->andReturn(false);

        $this->pm->shouldReceive('getPhpFpmName')->andReturnUsing(fn (string $version) => "php{$version}-fpm");
        $this->pm->shouldReceive('packageName')->with('redis')->andReturn('redis-server');
        $this->mysql->shouldReceive('serviceName')->andReturn('mariadb');
    }

    /**
     * @test
     */
    public function itWillBuildTheEnvironmentSnapshot(): void
    {
        $this->sm->shouldReceive('isActive')->andReturn(true);

        $state = $this->reporter->state('2.2.0');

        $this->assertSame(StateReporter::SCHEMA_VERSION, $state['schema_version']);
        $this->assertSame('2.2.0', $state['valet_version']);
        $this->assertSame('test', $state['domain']);
        $this->assertSame(['/home/user/sites'], $state['paths']);
        $this->assertSame(
            ['path' => '/usr/bin/php', 'binary' => '/usr/bin/php8.3', 'version' => '8.3.10'],
            $state['php']['cli']
        );
        $this->assertSame('8.3', $state['php']['global']);
    }

    /**
     * @test
     */
    public function itWillMergeParkedLinkedAndProxiedSites(): void
    {
        $this->sm->shouldReceive('isActive')->andReturn(true);

        $sites = collect($this->reporter->state('2.2.0')['sites'])->keyBy('name');

        $this->assertSame(['blog', 'legacy', 'mails', 'shop'], $sites->keys()->all());

        $this->assertSame('parked', $sites['blog']['type']);
        $this->assertSame('http://blog.test', $sites['blog']['url']);

        $this->assertSame('linked', $sites['shop']['type']);
        $this->assertSame('https://shop.test', $sites['shop']['url']);
        $this->assertTrue($sites['shop']['secure']);
        $this->assertTrue($sites['shop']['custom_nginx']);

        $this->assertSame('proxy', $sites['mails']['type']);
        $this->assertNull($sites['mails']['path']);
        $this->assertSame('http://localhost:8025', $sites['mails']['proxy_host']);
    }

    /**
     * @test
     */
    public function itWillResolveSitePhpVersionByPriority(): void
    {
        $this->sm->shouldReceive('isActive')->andReturn(true);

        $sites = collect($this->reporter->state('2.2.0')['sites'])->keyBy('name');

        // Isolated version wins over .valetphprc
        $this->assertSame('7.4', $sites['legacy']['php']);
        $this->assertSame('7.4', $sites['legacy']['isolated_php']);
        $this->assertSame('8.1', $sites['legacy']['phprc_php']);

        // .valetphprc wins over the global version
        $this->assertSame('8.2', $sites['blog']['php']);
        $this->assertNull($sites['blog']['isolated_php']);

        // Global version as fallback
        $this->assertSame('8.3', $sites['shop']['php']);
    }

    /**
     * @test
     */
    public function itWillReportServicesStatus(): void
    {
        $this->sm->shouldReceive('isActive')->with('nginx')->andReturn(false);
        $this->sm->shouldReceive('isActive')->with('redis-server')->andReturn(null);
        $this->sm->shouldReceive('isActive')->andReturn(true);

        $services = collect($this->reporter->state('2.2.0')['services'])->keyBy('name');

        $this->assertSame(
            ['nginx', 'dnsmasq', 'php7.4', 'php8.3', 'mysql', 'redis', 'mailpit'],
            $services->keys()->all()
        );
        $this->assertFalse($services['nginx']['active']);
        $this->assertNull($services['redis']['active']);
        $this->assertTrue($services['php7.4']['active']);
        $this->assertSame('php7.4-fpm', $services['php7.4']['unit']);
        $this->assertSame('mariadb', $services['mysql']['unit']);
    }
}
