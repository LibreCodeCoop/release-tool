<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Integration\Infrastructure\AppStore;

use LibreCode\ReleaseTool\Infrastructure\AppStore\NextcloudAppStoreRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class NextcloudAppStoreRepositoryTest extends TestCase
{

    public function testRequestsFreshAppStoreState(): void
    {
        $payload = json_encode([], JSON_THROW_ON_ERROR);
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use ($payload): MockResponse {
            self::assertSame('GET', $method);
            self::assertStringContainsString('release-tool-check=15.0.4', $url);
            self::assertContains('Cache-Control: no-cache', $options['headers']);
            self::assertContains('Pragma: no-cache', $options['headers']);

            return new MockResponse($payload);
        });
        $repository = new NextcloudAppStoreRepository($client);

        self::assertFalse($repository->hasRelease(
            'https://apps.example.test/api/v1/apps.json',
            'libresign',
            '15.0.4',
        ));
    }

    public function testFindsExactNonNightlyRelease(): void
    {
        $payload = json_encode([
            [
                'id' => 'libresign',
                'releases' => [
                    ['version' => '15.0.4', 'isNightly' => false],
                    ['version' => '15.1.0', 'isNightly' => true],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        $client = new MockHttpClient([
            new MockResponse($payload),
            new MockResponse($payload),
        ]);
        $repository = new NextcloudAppStoreRepository($client);

        self::assertTrue($repository->hasRelease(
            'https://apps.example.test/api/v1/apps.json',
            'libresign',
            '15.0.4',
        ));
        self::assertFalse($repository->hasRelease(
            'https://apps.example.test/api/v1/apps.json',
            'libresign',
            '15.1.0',
        ));
    }
}
