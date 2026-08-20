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
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-004
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-005
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Zgw;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Typed ZTC client with omschrijving → URL cache.
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
	 */
	public function resolveZaaktype(array $endpoint, string $omschrijving): string {
		$hit = $this->resolveByOmschrijving(
			endpoint: $endpoint,
			resourceType: self::RESOURCE_ZAAKTYPE,
			omschrijving: $omschrijving
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
	 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-004
	 */
	public function resolveStatustype(array $endpoint, string $caseTypeUrl, string $omschrijving): string {
		$hit = $this->resolveByOmschrijving(
			endpoint: $endpoint,
			resourceType: self::RESOURCE_STATUSTYPE,
			omschrijving: $omschrijving,
			extraQuery: ['caseType' => $caseTypeUrl]
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
	 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-004
	 */
	public function resolveRoltype(array $endpoint, string $caseTypeUrl, string $omschrijving): string {
		$hit = $this->resolveByOmschrijving(
			endpoint: $endpoint,
			resourceType: self::RESOURCE_ROLTYPE,
			omschrijving: $omschrijving,
			extraQuery: ['caseType' => $caseTypeUrl]
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
	 */
	public function resolveBesluittype(array $endpoint, string $omschrijving): string {
		$hit = $this->resolveByOmschrijving(
			endpoint: $endpoint,
			resourceType: self::RESOURCE_BESLUITTYPE,
			omschrijving: $omschrijving
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
	 */
	public function resolveOmschrijvingFromUrl(array $endpoint, string $statustypeUrl): ?string {
		$endpointId = (string)($endpoint['id'] ?? '');
		$bucket = $this->cache[$endpointId][self::RESOURCE_STATUSTYPE] ?? [];
		foreach ($bucket as $entry) {
			if ($entry['url'] === $statustypeUrl) {
				$omsch = $entry['data']['omschrijving'] ?? null;
				if (is_string($omsch) === true) {
					return $omsch;
				}

				return null;
			}
		}

		// Cache miss: fetch directly.
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

		$omsch = $response['body']['omschrijving'] ?? null;
		if (is_string($omsch) === true) {
			return $omsch;
		}

		return null;
	}//end resolveOmschrijvingFromUrl()

	/**
	 * Invalidate the cache for one resource type (called on catalogi NRC events).
	 *
	 * @param array<string, mixed> $endpoint ZgwEndpoint payload.
	 * @param string $resourceType One of RESOURCE_* (or '*' for all).
	 *
	 * @return void
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
	 * Inject a cache entry (testing helper).
	 *
	 * @param string $endpointId Endpoint id.
	 * @param string $resourceType One of RESOURCE_*.
	 * @param string $omschrijving Omschrijving.
	 * @param string $url Resolved URL.
	 * @param array<string, mixed> $data Optional full body for ZTC entry.
	 *
	 * @return void
	 */
	public function primeCache(string $endpointId, string $resourceType, string $omschrijving, string $url, array $data = []): void {
		$cacheData = $data;
		if ($data === []) {
			$cacheData = ['omschrijving' => $omschrijving];
		}

		$this->cache[$endpointId][$resourceType][$omschrijving] = [
			'url' => $url,
			'data' => $cacheData,
			'storedAt' => time(),
		];
	}//end primeCache()

	/**
	 * Internal: lookup-with-cache for any resource type.
	 *
	 * @param array<string, mixed> $endpoint Endpoint payload.
	 * @param string $resourceType One of RESOURCE_*.
	 * @param string $omschrijving Omschrijving to look up.
	 * @param array<string,string|int> $extraQuery Optional extra ZTC filters.
	 *
	 * @return array{url:string,data:array<string,mixed>}|null
	 */
	private function resolveByOmschrijving(
		array $endpoint,
		string $resourceType,
		string $omschrijving,
		array $extraQuery = [],
	): ?array {
		$endpointId = (string)($endpoint['id'] ?? '');
		$cacheKey = $omschrijving . '|' . json_encode($extraQuery, JSON_UNESCAPED_SLASHES);
		$bucket = $this->cache[$endpointId][$resourceType][$cacheKey] ?? null;
		if ($bucket !== null && (time() - $bucket['storedAt']) < $this->ttl()) {
			return ['url' => $bucket['url'], 'data' => $bucket['data']];
		}

		$resolved = $this->fetchByOmschrijving(
			endpoint: $endpoint,
			endpointId: $endpointId,
			resourceType: $resourceType,
			omschrijving: $omschrijving,
			extraQuery: $extraQuery
		);
		if ($resolved === null) {
			return null;
		}

		$this->cache[$endpointId][$resourceType][$cacheKey] = [
			'url' => $resolved['url'],
			'data' => $resolved['data'],
			'storedAt' => time(),
		];

		return $resolved;
	}//end resolveByOmschrijving()

	/**
	 * Internal: issue the ZTC lookup and extract the first definitief entry.
	 *
	 * @param array<string, mixed> $endpoint Endpoint payload.
	 * @param string $endpointId Endpoint identifier (for logging).
	 * @param string $resourceType One of RESOURCE_*.
	 * @param string $omschrijving Omschrijving to look up.
	 * @param array<string,string|int> $extraQuery Optional extra ZTC filters.
	 *
	 * @return array{url:string,data:array<string,mixed>}|null
	 */
	private function fetchByOmschrijving(
		array $endpoint,
		string $endpointId,
		string $resourceType,
		string $omschrijving,
		array $extraQuery,
	): ?array {
		$client = $this->registers->findClientForEndpoint($endpoint);
		$ztcUrl = (string)($endpoint['componenten']['ztc'] ?? '');
		if ($client === null || $ztcUrl === '') {
			return null;
		}

		$query = array_merge(['omschrijving' => $omschrijving, 'status' => 'definitief'], $extraQuery);

		try {
			$response = $this->api->callComponent(
				componentUrl: $ztcUrl,
				method: 'GET',
				path: '/' . $resourceType,
				client: $client,
				query: $query
			);
		} catch (ZgwResourceNotFoundException) {
			return null;
		} catch (Throwable $e) {
			$this->logger->warning(
				'ZGW ZTC: lookup failed',
				['endpoint' => $endpointId, 'resource' => $resourceType, 'om' => $omschrijving, 'err' => $e->getMessage()]
			);
			return null;
		}

		$results = $response['body']['results'] ?? $response['body'];
		if (is_array($results) === false || $results === []) {
			return null;
		}

		// Pick the most-recent or first entry.
		$entry = $results[0] ?? null;
		if (is_array($entry) === false) {
			return null;
		}

		$url = (string)($entry['url'] ?? '');
		if ($url === '') {
			return null;
		}

		return ['url' => $url, 'data' => $entry];
	}//end fetchByOmschrijving()

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
