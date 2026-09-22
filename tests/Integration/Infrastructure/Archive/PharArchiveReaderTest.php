<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Integration\Infrastructure\Archive;

use InvalidArgumentException;
use LibreCode\ReleaseTool\Infrastructure\Archive\PharArchiveReader;
use PharData;
use PHPUnit\Framework\TestCase;

final class PharArchiveReaderTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
    }

    public function testReadsSafeTarArchive(): void
    {
        $path = $this->path('.tar');
        $archive = new PharData($path);
        $archive->addFromString('libresign/appinfo/info.xml', '<info/>');
        $archive->addFromString('libresign/CHANGELOG.md', '# Changelog');

        $reader = new PharArchiveReader($path);

        self::assertSame(
            ['libresign/CHANGELOG.md', 'libresign/appinfo/info.xml'],
            $reader->paths(),
        );
        self::assertSame('# Changelog', $reader->read('libresign/CHANGELOG.md'));
    }

    public function testAcceptsSafeDirectoryEntriesWithTrailingSlash(): void
    {
        $path = $this->path('.tar');
        $archive = new PharData($path);
        $archive->addEmptyDir('libresign');
        $archive->addEmptyDir('libresign/appinfo');
        $archive->addFromString('libresign/appinfo/info.xml', '<info/>');

        $reader = new PharArchiveReader($path);

        self::assertSame(['libresign/appinfo/info.xml'], $reader->paths());
    }

    public function testRejectsTraversalEntryEvenWhenPharIteratorWouldHideIt(): void
    {
        $path = $this->path('.tar');
        $archive = new PharData($path);
        $archive->addFromString('../evil.txt', 'evil');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe archive path');

        new PharArchiveReader($path);
    }

    public function testRejectsMalformedArchive(): void
    {
        $path = $this->path('.tar');
        file_put_contents($path, 'not a tar archive');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Malformed TAR archive');

        new PharArchiveReader($path);
    }

    public function testRejectsUnsupportedArchiveExtension(): void
    {
        $path = $this->path('.zip');
        file_put_contents($path, 'not a supported archive');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported archive format');

        new PharArchiveReader($path);
    }

    private function path(string $suffix): string
    {
        $path = sys_get_temp_dir() . '/release-tool-' . bin2hex(random_bytes(8)) . $suffix;
        $this->paths[] = $path;

        return $path;
    }
}
