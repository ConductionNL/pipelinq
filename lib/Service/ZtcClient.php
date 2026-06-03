<?php

/**
 * Pipelinq ZtcClient.
 *
 * Catalogi API (ZTC) client with in-process caching. Resolves zaaktypen,
 * statustypen, roltypen and besluittypen by omschrijving to their canonical
 * URLs, caching results for a configurable TTL (default 1h) and invalidating on
 * inbound "catalogi" NRC notifications (REQ-ZGW-005).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#REQ-ZGW-005
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Exception\BesluittypeNotInCatalogusException;
use OCA\Pipelinq\Exception\ZaaktypeNotInCatalogusException;
use OCP\IAppConfig;

/**
 * Catalogi API (ZTC) client with caching.
 *
 * @spec openspec/changes/zgw-api-bridge/tasks.md#2.5
 */
class ZtcClient
{
    /**
     * Default cache TTL in seconds (1 hour).
     *
     * @var int
     */
    private const DEFAULT_TTL = 3600;

    /**
     * In-process cache: key => ['url' => string, 'expires' => int].
     *
     * @var array<string, array{url:string, expires:int}>
     */
    private array $cache = [];

    /**
     * Constructor.
     *
     * @param ZgwApiClient $apiClient The base ZGW HTTP client.
     * @param IAppConfig   $appConfig The app config (TTL tuning).
     */
    public function __construct(
        private ZgwApiClient $apiClient,
        private IAppConfig $appConfig,
    ) {
    }//end __construct()

    /**
     * Get the configured cache TTL in seconds.
     *
     * @return int The TTL.
     */
    private function ttl(): int
    {
        $ttl = (int) $this->appConfig->getValueString(
            Application::APP_ID,
            'zgw.ztc_cache_ttl',
            (string) self::DEFAULT_TTL
        );

        if ($ttl <= 0) {
            return self::DEFAULT_TTL;
        }

        return $ttl;
    }//end ttl()

    /**
     * Build a cache key for a (endpoint, resourceType, omschrijving) tuple.
     *
     * @param array<string, mixed> $endpoint     The ZgwEndpoint object array.
     * @param string               $resourceType The ZTC resource type (e.g. "zaaktypen").
     * @param string               $omschrijving The omschrijving.
     *
     * @return string The cache key.
     */
    private function cacheKey(array $endpoint, string $resourceType, string $omschrijving): string
    {
        return ((string) ($endpoint['id'] ?? '')).'|'.$resourceType.'|'.$omschrijving;
    }//end cacheKey()

    /**
     * Read a fresh (non-expired) cache entry, if present.
     *
     * @param string $key The cache key.
     *
     * @return string|null The cached URL, or null on miss/expiry.
     */
    private function readCache(string $key): ?string
    {
        $entry = ($this->cache[$key] ?? null);
        if ($entry === null) {
            return null;
        }

        if ($entry['expires'] < time()) {
            unset($this->cache[$key]);
            return null;
        }

        return $entry['url'];
    }//end readCache()

    /**
     * Resolve a ZTC resource URL by omschrijving, with caching.
     *
     * @param array<string, mixed>  $endpoint     The ZgwEndpoint object array.
     * @param array<string, mixed>  $client       The ZgwClient object array.
     * @param string                $resourceType The ZTC resource collection (e.g. "zaaktypen").
     * @param string                $omschrijving The omschrijving to resolve.
     * @param array<string, string> $extraQuery   Extra query params (e.g. zaaktype= for statustypen).
     *
     * @return string|null The resolved URL, or null when not found.
     */
    private function resolve(
        array $endpoint,
        array $client,
        string $resourceType,
        string $omschrijving,
        array $extraQuery=[]
    ): ?string {
        $key    = $this->cacheKey(
            endpoint: $endpoint,
            resourceType: $resourceType,
            omschrijving: $omschrijving.'|'.http_build_query($extraQuery)
        );
        $cached = $this->readCache(key: $key);
        if ($cached !== null) {
            return $cached;
        }

        $ztcUrl = (string) ($endpoint['componenten']['ztc'] ?? '');
        $query  = array_merge(['omschrijving' => $omschrijving], $extraQuery);
        $path   = '/'.$resourceType.'?'.http_build_query($query);

        $response = $this->apiClient->callComponent($ztcUrl, 'GET', $path, $client);
        $results  = ($response['body']['results'] ?? []);

        if (is_array($results) === false || count($results) === 0) {
            return null;
        }

        $url = (string) ($results[0]['url'] ?? '');
        if ($url === '') {
            return null;
        }

        $this->cache[$key] = ['url' => $url, 'expires' => (time() + $this->ttl())];

        return $url;
    }//end resolve()

    /**
     * Resolve a zaaktype URL by omschrijving.
     *
     * @param array<string, mixed> $endpoint     The ZgwEndpoint object array.
     * @param array<string, mixed> $client       The ZgwClient object array.
     * @param string               $omschrijving The zaaktype omschrijving.
     *
     * @return string The zaaktype URL.
     *
     * @throws ZaaktypeNotInCatalogusException When the zaaktype is not found.
     */
    public function resolveZaaktype(array $endpoint, array $client, string $omschrijving): string
    {
        $url = $this->resolve(endpoint: $endpoint, client: $client, resourceType: 'zaaktypen', omschrijving: $omschrijving);
        if ($url === null) {
            throw new ZaaktypeNotInCatalogusException(omschrijving: $omschrijving);
        }

        return $url;
    }//end resolveZaaktype()

    /**
     * Resolve a statustype URL by omschrijving within a zaaktype.
     *
     * @param array<string, mixed> $endpoint     The ZgwEndpoint object array.
     * @param array<string, mixed> $client       The ZgwClient object array.
     * @param string               $zaaktypeUrl  The owning zaaktype URL.
     * @param string               $omschrijving The statustype omschrijving.
     *
     * @return string|null The statustype URL, or null when not found.
     */
    public function resolveStatustype(array $endpoint, array $client, string $zaaktypeUrl, string $omschrijving): ?string
    {
        return $this->resolve(
            endpoint: $endpoint,
            client: $client,
            resourceType: 'statustypen',
            omschrijving: $omschrijving,
            extraQuery: ['zaaktype' => $zaaktypeUrl]
        );
    }//end resolveStatustype()

    /**
     * Resolve a roltype URL by omschrijving within a zaaktype.
     *
     * @param array<string, mixed> $endpoint     The ZgwEndpoint object array.
     * @param array<string, mixed> $client       The ZgwClient object array.
     * @param string               $zaaktypeUrl  The owning zaaktype URL.
     * @param string               $omschrijving The roltype omschrijving (generiek).
     *
     * @return string|null The roltype URL, or null when not found.
     */
    public function resolveRoltype(array $endpoint, array $client, string $zaaktypeUrl, string $omschrijving): ?string
    {
        return $this->resolve(
            endpoint: $endpoint,
            client: $client,
            resourceType: 'roltypen',
            omschrijving: $omschrijving,
            extraQuery: ['zaaktype' => $zaaktypeUrl, 'omschrijvingGeneriek' => $omschrijving]
        );
    }//end resolveRoltype()

    /**
     * Resolve a besluittype URL by omschrijving.
     *
     * @param array<string, mixed> $endpoint     The ZgwEndpoint object array.
     * @param array<string, mixed> $client       The ZgwClient object array.
     * @param string               $omschrijving The besluittype omschrijving.
     *
     * @return string The besluittype URL.
     *
     * @throws BesluittypeNotInCatalogusException When the besluittype is not found.
     */
    public function resolveBesluittype(array $endpoint, array $client, string $omschrijving): string
    {
        $url = $this->resolve(endpoint: $endpoint, client: $client, resourceType: 'besluittypen', omschrijving: $omschrijving);
        if ($url === null) {
            throw new BesluittypeNotInCatalogusException(omschrijving: $omschrijving);
        }

        return $url;
    }//end resolveBesluittype()

    /**
     * Invalidate cached entries for a resource type on a catalogi notification.
     *
     * When $resourceType is empty all cache entries for the endpoint are cleared.
     *
     * @param array<string, mixed> $endpoint     The ZgwEndpoint object array.
     * @param string               $resourceType The affected ZTC resource type (or '' for all).
     *
     * @return void
     */
    public function invalidateCache(array $endpoint, string $resourceType=''): void
    {
        $endpointId = (string) ($endpoint['id'] ?? '');

        foreach (array_keys($this->cache) as $key) {
            if (str_starts_with($key, $endpointId.'|') === false) {
                continue;
            }

            if ($resourceType === '' || str_contains($key, '|'.$resourceType.'|') === true) {
                unset($this->cache[$key]);
            }
        }
    }//end invalidateCache()
}//end class
