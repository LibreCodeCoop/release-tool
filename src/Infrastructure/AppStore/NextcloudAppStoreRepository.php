<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Infrastructure\AppStore;

use DomainException;
use LibreCode\ReleaseTool\Application\Publication\Port\AppStoreRepository;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class NextcloudAppStoreRepository implements AppStoreRepository
{
    private HttpClientInterface $client;

    public function __construct(?HttpClientInterface $client = null)
    {
        $this->client = $client ?? HttpClient::create([
            'headers' => ['User-Agent' => 'LibreCode-release-tool'],
        ]);
    }

    public function hasRelease(string $apiUrl, string $appId, string $version): bool
    {
        $response = $this->client->request('GET', $apiUrl);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new DomainException(sprintf('Nextcloud App Store API request failed (%d).', $status));
        }
        $apps = $response->toArray(false);

        foreach ($apps as $app) {
            if (!is_array($app) || ($app['id'] ?? null) !== $appId) {
                continue;
            }
            foreach (($app['releases'] ?? []) as $release) {
                if (
                    is_array($release)
                    && ($release['version'] ?? null) === $version
                    && ($release['isNightly'] ?? false) === false
                ) {
                    return true;
                }
            }
            return false;
        }

        return false;
    }
}
