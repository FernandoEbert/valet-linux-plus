<?php

namespace Valet\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Valet\Server;
use Valet\Tests\TestCase;

class ServerTest extends TestCase
{
    public static function notFoundReasonProvider(): array
    {
        return [
            'site' => [Server::NOT_FOUND_SITE, Server::NOT_FOUND_SITE],
            'driver' => [Server::NOT_FOUND_DRIVER, Server::NOT_FOUND_DRIVER],
            'page' => [Server::NOT_FOUND_PAGE, Server::NOT_FOUND_PAGE],
            'unknown falls back to page' => ['invalid', Server::NOT_FOUND_PAGE],
            'empty falls back to page' => ['', Server::NOT_FOUND_PAGE],
            'case sensitive' => ['SITE', Server::NOT_FOUND_PAGE],
        ];
    }

    /**
     * @dataProvider notFoundReasonProvider
     */
    #[DataProvider('notFoundReasonProvider')]
    public function testNormalizeNotFoundReason(string $reason, string $expected): void
    {
        $this->assertSame($expected, Server::normalizeNotFoundReason($reason));
    }
}
