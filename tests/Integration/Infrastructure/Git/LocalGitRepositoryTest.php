<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Integration\Infrastructure\Git;

use LibreCode\ReleaseTool\Infrastructure\Git\LocalGitRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class LocalGitRepositoryTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/release-tool-git-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0777, true);

        $this->runGit(['git', 'init', '-b', 'main']);
        $this->runGit(['git', 'config', 'user.name', 'Release Tool Test']);
        $this->runGit(['git', 'config', 'user.email', 'release-tool@example.invalid']);
        file_put_contents($this->directory . '/README.md', "first\n");
        $this->runGit(['git', 'add', 'README.md']);
        $this->runGit(['git', 'commit', '-m', 'chore: initial']);
        $this->runGit(['git', 'tag', 'v1.0.0']);
        file_put_contents($this->directory . '/README.md', "second\n");
        $this->runGit(['git', 'commit', '-am', 'fix: second']);
    }

    protected function tearDown(): void
    {
        if (!isset($this->directory) || !is_dir($this->directory)) {
            return;
        }

        $process = new Process(['rm', '-rf', $this->directory]);
        $process->run();
    }

    public function testResolvesBranchAndReachableReleaseFromRealGitRepository(): void
    {
        $repository = new LocalGitRepository($this->directory);
        $head = $repository->branchHead('main');
        $previous = $repository->previousRelease($head, 'v', null);

        self::assertSame(40, strlen($head));
        self::assertSame('v1.0.0', $previous->tag);
        self::assertTrue($repository->isAncestor($previous->sha, $head));
        self::assertSame("second\n", $repository->readFile($head, 'README.md'));
        self::assertCount(1, $repository->commitsBetween($previous->sha, $head));
    }

    /**
     * @param list<string> $command
     */
    private function runGit(array $command): void
    {
        $process = new Process($command, $this->directory);
        $process->mustRun();
    }
}
