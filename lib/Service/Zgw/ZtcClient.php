<?php

/**
 * Pipelinq ZtcClient.
 *
 * Typed client for the ZGW Catalogi (ZTC) component. Resolves zaaktypen,
 * statustypen, roltypen, resultaattypen and besluittypen by omschrijving
 * and returns the canonical URL used by ZRC / BRC. Maintains an in-process
 * cache (default 1 hour TTL) keyed by (endpointId, resourceType,
 * omschrijving), invalidated by `invalidateCache()` when an inbound NRC
 * notification on the "catalogi" kanaal arrives.
 *
 * Lookup misses raise `ZaaktypeNotInCatalogusException` (or the
 * besluittype variant) so callers can surface a clear error to the
 * gemeente beheerder rather than silently fall through to a 404 from BRC.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Zgw
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-004
 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-005
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Zgw;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Typed ZTC client with omschrijving → URL cache.
 *
 * @spec openspec/specs/vng-klantinteracties-leaf/spec.md
 */
class ZtcClient {
	public const RESOURCE_ZAAKTYPE = 'zaaktypen';
	public const RESOURCE_STATUSTYPE = 'statustypen';
	public const RESOURCE_ROLTYPE = 'roltypen';
	public const RESOURCE_RESULTAATTYPE = 'resultaattypen';
	public const RESOURCE_BESLUITTYPE = 'besluittypen';

	private const DEFAULT_TTL_S = 3600;

	/**
	 * Cache:
	 *   $cache[endpointId][resourceType][key] = [
	 *     'url'     => string,
	 *     'data'    => array,
	 *     'storedAt'=> int,
	 *   ]
	 *
	 * @var array<string, array<string, array<string, array{url:string,data:array<string,mixed>,storedAt:int}>>>
	 */
	private array $cache = [];

	/**
	 * Constructor.
	 *
	 * @param ZgwApiClient $api Base transport.
	 * @param ZgwRegisterAccess $registers Register facade (client lookup).
	 * @param IAppConfig $appConfig App config (cache TTL).
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		private ZgwApiClient $api,
		private ZgwRegisterAccess $registers,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve a zaaktype URL by omschrijving.
	 *
	 * @param array<string, mixed> $endpoint ZgwEndpoint payload.
	 * @param string $omschrijving Zaaktype omschrijving.
	 *
	 * @return string Zaaktype URL.
	 *
	 * @throws ZaaktypeNotInCatalogusException When no zaaktype matches.
	 * @spec openspec/specs/vng-klantinteracties-leaf/spec.md
	 */
	public function resolveZaaktype(array $endpoint, string $omschrijving): string {
		$hit = $this->resolveByOmschrijving(
			endpoint: $endpoint,
			lookup: new ZtcLookup(resourceType: self::RESOURCE_ZAAKTYPE, omschrijving: $omschrijving)
		);
		if ($hit === null) {
			throw new ZaaktypeNotInCatalogusException($omschrijving);
		}

		return $hit['url'];
	}//end resolveZaaktype()

	/**
	 * Resolve a statustype URL by omschrijving + parent zaaktype.
	 *
	 * @param array<string, mixed> $endpoint ZgwEndpoint payload.
	 * @param string $caseTypeUrl Parent zaaktype URL.
	 * @param string $omschrijving Statustype omschrijving.
	 *
	 * @return string Statustype URL.
	 *
	 * @throws ZaaktypeNotInCatalogusException When no statustype matches.
	 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-004
	 */
	public function resolveStatustype(array $endpoint, string $caseTypeUrl, string $omschrijving): string {
		$hit = $this->resolveByOmschrijving(
			endpoint: $endpoint,
			lookup: new ZtcLookup(resourceType: self::RESOURCE_STATUSTYPE, omschrijving: $omschrijving, extraQuery: ['caseType' => $caseTypeUrl])
		);
		if ($hit === null) {
			throw new ZaaktypeNotInCatalogusException($omschrijving);
		}

		return $hit['url'];
	}//end resolveStatustype()

	/**
	 * Resolve a roltype URL by omschrijving + parent zaaktype.
	 *
	 * @param array<string, mixed> $endpoint ZgwEndpoint payload.
	 * @param string $caseTypeUrl Parent zaaktype URL.
	 * @param string $omschrijving Roltype omschrijving (e.g. "Initiator").
	 *
	 * @return string Roltype URL.
	 *
	 * @throws ZaaktypeNotInCatalogusException When no roltype matches.
	 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-004
	 */
	public function resolveRoltype(array $endpoint, string $caseTypeUrl, string $omschrijving): string {
		$hit = $this->resolveByOmschrijving(
			endpoint: $endpoint,
			lookup: new ZtcLookup(resourceType: self::RESOURCE_ROLTYPE, omschrijving: $omschrijving, extraQuery: ['caseType' => $caseTypeUrl])
		);
		if ($hit === null) {
			throw new ZaaktypeNotInCatalogusException($omschrijving);
		}

		return $hit['url'];
	}//end resolveRoltype()

	/**
	 * Resolve a besluittype URL by omschrijving.
	 *
	 * @param array<string, mixed> $endpoint ZgwEndpoint payload.
	 * @param string $omschrijving Besluittype omschrijving.
	 *
	 * @return string Besluittype URL.
	 *
	 * @throws BesluittypeNotInCatalogusException When no besluittype matches.
	 * @spec openspec/specs/vng-klantinteracties-leaf/spec.md
	 */
	public function resolveBesluittype(array $endpoint, string $omschrijving): string {
		$hit = $this->resolveByOmschrijving(
			endpoint: $endpoint,
			lookup: new ZtcLookup(resourceType: self::RESOURCE_BESLUITTYPE, omschrijving: $omschrijving)
		);
		if ($hit === null) {
			throw new BesluittypeNotInCatalogusException($omschrijving);
		}

		return $hit['url'];
	}//end resolveBesluittype()

	/**
	 * Lookup the statustype omschrijving for a fully-qualified statustype URL.
	 *
	 * Used by `NrcNotificationListener` when a status notification arrives:
	 * we GET the status URL → extract its statustype URL → call this method
	 * → use the omschrijving to update the pipelinq Request.status field.
	 *
	 * @param array<string, mixed> $endpoint ZgwEndpoint payload.
	 * @param string $statustypeUrl Fully-qualified statustype URL.
	 *
	 * @return string|null Omschrijving (or null when the lookup fails).
	 * @spec openspec/specs/vng-klantinteracties-leaf/spec.md
	 */
	public function resolveOmschrijvingFromUrl(array $endpoint, string $statustypeUrl): ?string {
		$cached = $this->cachedStatustype(endpoint: $endpoint, statustypeUrl: $statustypeUrl);
		if ($cached !== null) {
			return self::stringOrNull(value: $cached['data']['omschrijving'] ?? null);
		}

		// Cache miss: fetch directly.
		$body = $this->fetchStatustype(endpoint: $endpoint, statustypeUrl: $statustypeUrl);
		if ($body === null) {
			return null;
		}

		return self::stringOrNull(value: $body['omschrijving'] ?? null);
	}//end resolveOmschrijvingFromUrl()

	/**
	 * The cached statustype entry with this URL, when an earlier lookup stored it.
	 *
	 * @param array<string, mixed> $endpoint      ZgwEndpoint payload.
	 * @param string               $statustypeUrl Fully-qualified statustype URL.
	 *
	 * @return array<string, mixed>|null
	 */
	private function cachedStatustype(array $endpoint, string $statustypeUrl): ?array {
		$endpointId = (string)($endpoint['id'] ?? '');
		$bucket = $this->cache[$endpointId][self::RESOURCE_STATUSTYPE] ?? [];
		foreach ($bucket as $entry) {
			if ($entry['url'] === $statustypeUrl) {
				return $entry;
			}
		}

		return null;
	}//end cachedStatustype()

	/**
	 * GET a statustype on the endpoint's own ZTC; null when it cannot be fetched.
	 *
	 * @param array<string, mixed> $endpoint      ZgwEndpoint payload.
	 * @param string               $statustypeUrl Fully-qualified statustype URL.
	 *
	 * @return array<string, mixed>|null The decoded body.
	 */
	private function fetchStatustype(array $endpoint, string $statustypeUrl): ?array {
		$client = $this->registers->findClientForEndpoint($endpoint);
		$ztcUrl = (string)($endpoint['componenten']['ztc'] ?? '');
		if ($client === null || $ztcUrl === '' || str_starts_with($statustypeUrl, $ztcUrl) === false) {
			return null;
		}

		try {
			$response = $this->api->callComponent(
				componentUrl: $ztcUrl,
				method: 'GET',
				path: substr($statustypeUrl, strlen($ztcUrl)),
				client: $client
			);
		} catch (Throwable $e) {
			$this->logger->warning('ZGW ZTC: resolveOmschrijvingFromUrl failed', ['err' => $e->getMessage()]);
			return null;
		}

		return $response['body'];
	}//end fetchStatustype()

	/**
	 * The value when it is a string, otherwise null.
	 *
	 * @param mixed $value Any value.
	 *
	 * @return string|null
	 */
	private static function stringOrNull($value): ?string {
		if (is_string($value) === true) {
			return $value;
		}

		return null;
	}//end stringOrNull()

	/**
	 * Invalidate the cache for one resource type (called on catalogi NRC events).
	 *
	 * @param array<string, mixed> $endpoint ZgwEndpoint payload.
	 * @param string $resourceType One of RESOURCE_* (or '*' for all).
	 *
	 * @return void
	 * @spec openspec/specs/vng-klantinteracties-leaf/spec.md
	 */
	public function invalidateCache(array $endpoint, string $resourceType = '*'): void {
		$endpointId = (string)($endpoint['id'] ?? '');
		if ($endpointId === '') {
			return;
		}

		if ($resourceType === '*' || $resourceType === '') {
			unset($this->cache[$endpointId]);
			return;
		}

		unset($this->cache[$endpointId][$resourceType]);
	}//end invalidateCache()

	/**
	 * Internal: lookup-with-cache for any resource type.
	 *
	 * @param array<string, mixed> $endpoint Endpoint payload.
	 * @param ZtcLookup            $lookup   The lookup.
	 *
	 * @return array{url:string,data:array<string,mixed>}|null
	 */
	private function resolveByOmschrijving(array $endpoint, ZtcLookup $lookup): ?array {
		$endpointId = (string)($endpoint['id'] ?? '');
		$cacheKey = $lookup->cacheKey();
		$bucket = $this->cache[$endpointId][$lookup->resourceType][$cacheKey] ?? null;
		if ($bucket !== null && (time() - $bucket['storedAt']) < $this->ttl()) {
			return ['url' => $bucket['url'], 'data' => $bucket['data']];
		}

		$resolved = $this->fetchByOmschrijving(endpoint: $endpoint, lookup: $lookup);
		if ($resolved === null) {
			return null;
		}

		$this->cache[$endpointId][$lookup->resourceType][$cacheKey] = [
			'url' => $resolved['url'],
			'data' => $resolved['data'],
			'storedAt' => time(),
		];

		return $resolved;
	}//end resolveByOmschrijving()

	/**
	 * Internal: issue the ZTC lookup and extract the first entry.
	 *
	 * @param array<string, mixed> $endpoint Endpoint payload.
	 * @param ZtcLookup            $lookup   The lookup.
	 *
	 * @return array{url:string,data:array<string,mixed>}|null
	 */
	private function fetchByOmschrijving(array $endpoint, ZtcLookup $lookup): ?array {
		$client = $this->registers->findClientForEndpoint($endpoint);
		$ztcUrl = (string)($endpoint['componenten']['ztc'] ?? '');
		if ($client === null || $ztcUrl === '') {
			return null;
		}

		try {
			$response = $this->api->callComponent(
				componentUrl: $ztcUrl,
				method: 'GET',
				path: '/' . $lookup->resourceType,
				client: $client,
				query: $lookup->query()
			);
		} catch (ZgwResourceNotFoundException) {
			return null;
		} catch (Throwable $e) {
			$this->logger->warning(
				'ZGW ZTC: lookup failed',
				['endpoint' => (string)($endpoint['id'] ?? ''), 'resource' => $lookup->resourceType, 'om' => $lookup->omschrijving, 'err' => $e->getMessage()]
			);
			return null;
		}

		return self::firstEntry(body: $response['body']);
	}//end fetchByOmschrijving()

	/**
	 * The first entry of a ZTC list answer, when it carries a URL.
	 *
	 * @param array<string, mixed> $body The decoded answer.
	 *
	 * @return array{url:string,data:array<string,mixed>}|null
	 */
	private static function firstEntry(array $body): ?array {
		$results = $body['results'] ?? $body;
		if (is_array($results) === false || $results === []) {
			return null;
		}

		$entry = $results[0] ?? null;
		if (is_array($entry) === false) {
			return null;
		}

		$url = (string)($entry['url'] ?? '');
		if ($url === '') {
			return null;
		}

		return ['url' => $url, 'data' => $entry];
	}//end firstEntry()

	/**
	 * Effective cache TTL (seconds).
	 *
	 * @return int
	 */
	private function ttl(): int {
		$value = $this->appConfig->getValueInt(Application::APP_ID, 'zgw.ztc_cache_ttl', self::DEFAULT_TTL_S);
		if ($value > 0) {
			return $value;
		}

		return self::DEFAULT_TTL_S;
	}//end ttl()
}//end class
