<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Application\Publication;

use DomainException;
use LibreCode\ReleaseTool\Application\Publication\AppStorePublicationWaiter;
use LibreCode\ReleaseTool\Application\Publication\Port\AppStoreRepository;
use PHPUnit\Framework\TestCase;

final class AppStorePublicationWaiterTest extends TestCase
{
    public function testReturnsWhenReleaseBecomesVisible(): void
    {
        $state = (object) ['calls' => 0];
        $sleeps = [];
        $repository = new class($state) implements AppStoreRepository {
            public function __construct(private object $state)
            {
            }

            public function hasRelease(string $apiUrl, string $appId, string $version): bool
            {
                ++$this->state->calls;
                TestCase::assertSame('https://example.invalid/apps.json', $apiUrl);
                TestCase::assertSame('example', $appId);
                TestCase::assertSame('1.2.3', $version);
                return $this->state->calls === 3;
            }
        };

        $waiter = new AppStorePublicationWaiter(
            $repository,
            static function (int $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            },
        );

        $waiter->wait('https://example.invalid/apps.json', 'example', '1.2.3', 3, 7);

        self::assertSame(3, $state->calls);
        self::assertSame([7, 7], $sleeps);
    }

    public function testRetriesTransientErrorsAndReportsLastError(): void
    {
        $repository = new class implements AppStoreRepository {
            public function hasRelease(string $apiUrl, string $appId, string $version): bool
            {
                throw new DomainException('temporary failure');
            }
        };

        $waiter = new AppStorePublicationWaiter($repository, static function (int $seconds): void {
        });

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('App Store publication could not be verified for example 1.2.3: temporary failure');
        $waiter->wait('https://example.invalid/apps.json', 'example', '1.2.3', 2, 0);
    }

    public function testRejectsInvalidRetryBounds(): void
    {
        $repository = new class implements AppStoreRepository {
            public function hasRelease(string $apiUrl, string $appId, string $version): bool
            {
                return false;
            }
        };
        $waiter = new AppStorePublicationWaiter($repository);

        foreach ([[0, 0], [121, 0], [1, -1], [1, 301]] as [$attempts, $delay]) {
            try {
                $waiter->wait('https://example.invalid/apps.json', 'example', '1.2.3', $attempts, $delay);
                self::fail('Expected invalid retry bounds to fail.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
