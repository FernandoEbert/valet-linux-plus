<?php

namespace Valet\Tests\Unit;

use ConsoleComponents\Writer;
use InvalidArgumentException;
use Mockery;
use Symfony\Component\Console\Output\BufferedOutput;
use Valet\Configuration;
use Valet\DnsMasq;
use Valet\Filesystem;
use Valet\Mailpit;
use Valet\Mysql;
use Valet\Nginx;
use Valet\PhpFpm;
use Valet\Services;
use Valet\Tests\TestCase;
use Valet\ValetRedis;

use function Valet\swap;

class ServicesTest extends TestCase
{
    private Configuration $config;
    private Services $services;

    public function setUp(): void
    {
        parent::setUp();

        $this->config = new Configuration(new Filesystem());
        $this->services = new Services($this->config);
    }

    /**
     * @test
     */
    public function itWillEnableEveryOptionalServiceWhenNotConfigured(): void
    {
        $this->assertSame(['mailpit', 'mysql', 'redis', 'ngrok'], $this->services->enabledOptional());
        $this->assertTrue($this->services->isEnabled('mysql'));
        $this->assertTrue($this->services->isEnabled('nginx'));
    }

    /**
     * @test
     */
    public function itWillPersistSelectionInCanonicalOrder(): void
    {
        $stored = $this->services->setEnabledOptional(['ngrok', 'mailpit']);

        $this->assertSame(['mailpit', 'ngrok'], $stored);
        $this->assertSame(['mailpit', 'ngrok'], $this->config->get('services'));
        $this->assertFalse($this->services->isEnabled('mysql'));
        $this->assertTrue($this->services->isEnabled('php'));
    }

    /**
     * @test
     */
    public function itWillAllowDisablingEveryOptionalService(): void
    {
        $this->services->setEnabledOptional([]);

        $this->assertSame([], $this->services->enabledOptional());
    }

    /**
     * @test
     */
    public function itWillRejectUnknownServices(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown service(s): nginx, foo');

        $this->services->setEnabledOptional(['redis', 'nginx', 'foo']);
    }

    /**
     * @test
     */
    public function itWillParseCommaSeparatedList(): void
    {
        $this->assertSame(['redis', 'mysql'], $this->services->parseList(' Redis, ,mysql '));
        $this->assertSame([], $this->services->parseList(''));
    }

    /**
     * @test
     */
    public function itWillRestartOnlyEnabledServices(): void
    {
        $this->config->set('services', ['redis']);

        foreach ([DnsMasq::class, PhpFpm::class, Nginx::class, ValetRedis::class] as $class) {
            $mock = Mockery::mock($class);
            $mock->shouldReceive('restart')->once();
            swap($class, $mock);
        }

        foreach ([Mailpit::class, Mysql::class] as $class) {
            $mock = Mockery::mock($class);
            $mock->shouldReceive('restart')->never();
            swap($class, $mock);
        }

        $this->services->restart();
    }

    /**
     * @test
     */
    public function itWillWarnWhenExplicitlyRequestedServiceIsDisabled(): void
    {
        Writer::fake();
        $this->config->set('services', []);

        $nginx = Mockery::mock(Nginx::class);
        $nginx->shouldReceive('stop')->once();
        swap(Nginx::class, $nginx);

        $mysql = Mockery::mock(Mysql::class);
        $mysql->shouldReceive('stop')->never();
        swap(Mysql::class, $mysql);

        $this->services->stop(['nginx', 'mysql']);

        /** @var BufferedOutput $output */
        $output = Writer::output();
        $this->assertStringContainsString('Skipped service(s) not enabled during install: mysql', $output->fetch());
    }
    /**
     * @test
     */
    public function itWillRejectMysqlAndMariaDbTogether(): void
    {
        $this->assertSame([['mysql', 'mariadb']], $this->services->conflicts(['mariadb', 'redis', 'mysql']));
        $this->assertSame([], $this->services->conflicts(['mariadb', 'redis']));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only one of [mysql, mariadb] can be enabled.');

        $this->services->setEnabledOptional(['mysql', 'mariadb']);
    }

    /**
     * @test
     */
    public function itWillTreatMariaDbAsTheMysqlService(): void
    {
        $this->config->set('services', ['mariadb']);

        $this->assertTrue($this->services->isEnabled('mysql'));
        $this->assertTrue($this->services->isEnabled('mariadb'));
        $this->assertSame(['dnsmasq', 'php', 'nginx', 'mysql'], $this->services->enabledServices());

        $mysql = Mockery::mock(Mysql::class);
        $mysql->shouldReceive('restart')->once();
        swap(Mysql::class, $mysql);

        $this->services->restart(['mariadb']);
    }

    /**
     * @test
     */
    public function itWillHideMysqlWhereOnlyMariaDbIsPackaged(): void
    {
        $this->assertArrayHasKey('mysql', $this->services->installOptions(false));
        $this->assertArrayNotHasKey('mysql', $this->services->installOptions(true));
        $this->assertArrayHasKey('mariadb', $this->services->installOptions(true));
    }

    /**
     * @test
     */
    public function itWillPreselectTheInstalledDatabaseEngine(): void
    {
        $this->assertSame(['mailpit', 'mysql', 'redis', 'ngrok'], $this->services->installDefaults(null, false));
        $this->assertSame(['mailpit', 'mariadb', 'redis', 'ngrok'], $this->services->installDefaults('mariadb', false));
        $this->assertSame(['mailpit', 'mariadb', 'redis', 'ngrok'], $this->services->installDefaults(null, true));

        $this->config->set('services', ['redis']);
        $this->assertSame(['redis'], $this->services->installDefaults('mariadb', false));
    }

    /**
     * @test
     */
    public function itWillSwapTheSelectedDatabaseEngine(): void
    {
        $this->assertSame(['mariadb', 'redis'], $this->services->withDatabaseEngine(['redis', 'mysql'], 'mariadb'));
        $this->assertSame(['redis'], $this->services->withDatabaseEngine(['redis'], 'mariadb'));
        $this->assertSame(['mysql'], $this->services->withDatabaseEngine(['mysql'], null));
        $this->assertSame('mariadb', $this->services->selectedDatabaseEngine(['redis', 'mariadb']));
        $this->assertNull($this->services->selectedDatabaseEngine(['redis']));
    }
}
