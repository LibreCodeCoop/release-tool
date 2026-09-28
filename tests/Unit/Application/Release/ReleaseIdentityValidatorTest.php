<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Application\Release;

use DomainException;
use LibreCode\ReleaseTool\Application\Configuration\ConsumerConfigLoader;
use LibreCode\ReleaseTool\Application\Release\Port\GitRepository;
use LibreCode\ReleaseTool\Application\Release\Port\ReleaseMetadataReader;
use LibreCode\ReleaseTool\Application\Release\ReadModel\ReleaseMetadata;
use LibreCode\ReleaseTool\Application\Release\ReleaseIdentityValidator;
use LibreCode\ReleaseTool\Domain\Version\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReleaseIdentityValidatorTest extends TestCase
{
    private const string SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    #[DataProvider('validIdentityProvider')]
    public function testAcceptsMatchingReleaseIdentity(string $tag, string $version): void
    {
        [$validator, $git] = $this->validator($version);
        $config = $this->config();
        $git->expects(self::once())->method('tagExists')->with($tag)->willReturn(true);

        $result = $validator->validate($config, $tag, 'HEAD', true);

        self::assertSame($tag, $result['tag']);
        self::assertSame($version, $result['version']);
        self::assertSame(self::SHA, $result['sha']);
    }

    /** @return iterable<string, array{string,string}> */
    public static function validIdentityProvider(): iterable
    {
        yield 'patch' => ['v13.4.3', '13.4.3'];
        yield 'zero patch' => ['v14.2.0', '14.2.0'];
        yield 'large numbers' => ['v123.456.789', '123.456.789'];
        yield 'alpha' => ['v15.0.0-alpha.1', '15.0.0-alpha.1'];
        yield 'beta' => ['v15.0.0-beta.12', '15.0.0-beta.12'];
        yield 'release candidate' => ['v15.0.0-rc.4', '15.0.0-rc.4'];
    }

    #[DataProvider('invalidIdentityProvider')]
    public function testRejectsInvalidReleaseIdentity(
        string $tag,
        string $version,
        string $expectedMessage,
    ): void {
        [$validator] = $this->validator($version);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage($expectedMessage);

        $validator->validate($this->config(), $tag);
    }

    /** @return iterable<string, array{string,string,string}> */
    public static function invalidIdentityProvider(): iterable
    {
        yield 'empty tag' => [
            '',
            '13.4.3',
            'Release tag cannot be empty.',
        ];
        yield 'missing configured prefix' => [
            '13.4.3',
            '13.4.3',
            "Release tag '13.4.3' must start with configured prefix 'v'.",
        ];
        yield 'wrong prefix' => [
            'release-13.4.3',
            '13.4.3',
            "Release tag 'release-13.4.3' must start with configured prefix 'v'.",
        ];
        yield 'malformed version' => [
            'v13.4',
            '13.4.3',
            "Release tag 'v13.4' has an invalid version. Expected 'v<version>', for example 'v13.4.3'.",
        ];
        yield 'unexpected semver suffix' => [
            'v13.4.3-preview.1',
            '13.4.3',
            "Release tag 'v13.4.3-preview.1' has an invalid version. Expected 'v<version>', for example 'v13.4.3'.",
        ];
        yield 'version mismatch' => [
            'v13.4.4',
            '13.4.3',
            "Release tag 'v13.4.4' does not match app version '13.4.3'; expected 'v13.4.3'.",
        ];
        yield 'prerelease mismatch' => [
            'v15.0.0-rc.2',
            '15.0.0-rc.1',
            "Release tag 'v15.0.0-rc.2' does not match app version '15.0.0-rc.1'; expected 'v15.0.0-rc.1'.",
        ];
    }

    public function testRejectsMissingTagWhenExistenceIsRequired(): void
    {
        [$validator, $git] = $this->validator('13.4.3');
        $git->expects(self::once())->method('tagExists')->with('v13.4.3')->willReturn(false);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage("Release tag 'v13.4.3' does not exist in the repository.");

        $validator->validate($this->config(), 'v13.4.3', 'HEAD', true);
    }

    public function testRejectsWhenTagPointsToDifferentCommitThanValidatedRef(): void
    {
        $tagSha = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        [$validator, $git] = $this->validator('13.4.3', $tagSha);

        $git->expects(self::once())->method('tagExists')->with('v13.4.3')->willReturn(true);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            "Release tag 'v13.4.3' points to commit '{$tagSha}', but ref 'HEAD' resolves to '" . self::SHA . "'. Build the release from the tag commit.",
        );

        $validator->validate($this->config(), 'v13.4.3', 'HEAD', true);
    }

    public function testDoesNotQueryTagExistenceWhenNotRequired(): void
    {
        [$validator, $git] = $this->validator('13.4.3');
        $git->expects(self::never())->method('tagExists');

        $result = $validator->validate($this->config(), 'v13.4.3');

        self::assertSame('13.4.3', $result['version']);
    }

    private function config(): \LibreCode\ReleaseTool\Domain\Configuration\ConsumerConfig
    {
        return (new ConsumerConfigLoader())->load(
            dirname(__DIR__, 3) . '/Fixtures/Configuration/libresign.yml',
        );
    }

    /**
     * @return array{ReleaseIdentityValidator, GitRepository}
     */
    private function validator(string $version, string $tagSha = self::SHA): array
    {
        $git = $this->createMock(GitRepository::class);
        $git->method('resolve')->willReturnCallback(
            static fn (string $ref): string => $ref === 'HEAD' ? self::SHA : $tagSha,
        );
        $metadataReader = $this->createMock(ReleaseMetadataReader::class);
        $metadataReader
            ->method('read')
            ->willReturn(new ReleaseMetadata(Version::parse($version), 33, 35, []));

        return [
            new ReleaseIdentityValidator($git, $metadataReader),
            $git,
        ];
    }
}
