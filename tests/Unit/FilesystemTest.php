<?php

namespace Valet\Tests\Unit;

use Valet\Filesystem;
use Valet\Tests\TestCase;

class FilesystemTest extends TestCase
{
    private Filesystem $files;
    private string $directory;

    public function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem();
        $this->directory = sys_get_temp_dir().'/valet-filesystem-test-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    public function tearDown(): void
    {
        $this->files->remove($this->directory);

        parent::tearDown();
    }

    /**
     * @test
     */
    public function itWillCreateFileAtomically(): void
    {
        $path = $this->directory.'/config.json';

        $written = $this->files->putAtomic($path, '{"domain":"test"}');

        $this->assertSame(17, $written);
        $this->assertSame('{"domain":"test"}', file_get_contents($path));
        $this->assertSame(['config.json'], $this->files->scandir($this->directory));
    }

    /**
     * @test
     */
    public function itWillReplaceFileAndPreserveItsMode(): void
    {
        $path = $this->directory.'/config.json';
        file_put_contents($path, 'old contents');
        chmod($path, 0640);

        $this->files->putAtomic($path, 'new contents');

        $this->assertSame('new contents', file_get_contents($path));
        $this->assertSame(0640, fileperms($path) & 0777);
    }

    /**
     * @test
     */
    public function itWillWriteThroughSymlinkWithoutReplacingIt(): void
    {
        $target = $this->directory.'/real.json';
        $link = $this->directory.'/config.json';
        file_put_contents($target, 'old contents');
        symlink($target, $link);

        $this->files->putAtomic($link, 'new contents');

        $this->assertTrue(is_link($link));
        $this->assertSame('new contents', file_get_contents($target));
    }

    /**
     * @test
     */
    public function itWillKeepOriginalFileWhenWriteFails(): void
    {
        if (\function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('Directory permissions are not enforced for root.');
        }

        $path = $this->directory.'/config.json';
        file_put_contents($path, 'old contents');
        chmod($this->directory, 0555);

        try {
            $this->files->putAtomic($path, 'new contents');
            $this->fail('Expected a RuntimeException.');
        } catch (\RuntimeException) {
            $this->assertSame('old contents', file_get_contents($path));
        } finally {
            chmod($this->directory, 0755);
        }

        $this->assertSame(['config.json'], $this->files->scandir($this->directory));
    }
}
