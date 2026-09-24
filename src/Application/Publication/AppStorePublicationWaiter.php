<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Publication;

use Closure;
use DomainException;
use LibreCode\ReleaseTool\Application\Publication\Port\AppStoreRepository;

final readonly class AppStorePublicationWaiter
{
    /** @var Closure(int): void */
    private Closure $sleep;

    /** @param null|Closure(int): void $sleep */
    public function __construct(
        private AppStoreRepository $appStore,
        ?Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static fn (int $seconds): mixed => sleep($seconds);
    }

    public function wait(
        string $apiUrl,
        string $appId,
        string $version,
        int $attempts = 12,
        int $delaySeconds = 10,
    ): void {
        if ($attempts < 1 || $attempts > 120) {
            throw new DomainException('attempts must be between 1 and 120');
        }
        if ($delaySeconds < 0 || $delaySeconds > 300) {
            throw new DomainException('delay-seconds must be between 0 and 300');
        }

        $lastError = null;
        for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
            try {
                if ($this->appStore->hasRelease($apiUrl, $appId, $version)) {
                    return;
                }
                $lastError = null;
            } catch (\Throwable $exception) {
                $lastError = $exception;
            }

            if ($attempt < $attempts) {
                ($this->sleep)($delaySeconds);
            }
        }

        $detail = $lastError === null ? '' : ': ' . $lastError->getMessage();
        throw new DomainException(sprintf(
            'App Store publication could not be verified for %s %s%s',
            $appId,
            $version,
            $detail,
        ));
    }
}
