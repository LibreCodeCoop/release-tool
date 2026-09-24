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
        $response = $this->client->request('GET', $apiUrl, [
            'headers' => [
                'Cache-Control' => 'no-cache',
                'Pragma' => 'no-cache',
            ],
            'query' => [
                'release-tool-check' => sprintf('%s-%s', $version, bin2hex(random_bytes(8))),
            ],
        ]);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new DomainException(sprintf('Nextcloud App Store API request failed (%d).', $status));
        }
        $payload = $response->toArray(false);
        $apps = isset($payload['data']) && is_array($payload['data'])
            ? $payload['data']
            : $payload;

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
