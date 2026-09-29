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
        $contentType = strtolower($response->getHeaders(false)['content-type'][0] ?? '');
        if (str_contains($contentType, 'xml') || str_contains($apiUrl, '/feeds/releases.')) {
            return $this->rssHasRelease($response->getContent(false), $appId, $version);
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

    private function rssHasRelease(string $xml, string $appId, string $version): bool
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $feed = simplexml_load_string($xml);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($feed === false) {
            throw new DomainException('Nextcloud App Store release feed returned invalid XML.');
        }

        $expectedGuid = sprintf('%s-%s', $appId, $version);
        foreach ($feed->channel->item ?? [] as $item) {
            if ((string) ($item->guid ?? '') === $expectedGuid) {
                return true;
            }
        }

        return false;
    }
}
