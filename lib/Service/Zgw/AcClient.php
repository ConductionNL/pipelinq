<?php

/**
 * Pipelinq AcClient.
 *
 * Typed client for the ZGW Autorisaties (AC) component. Maintains an
 * in-process scope cache keyed by (endpointId, resourceUrl, component) with
 * a configurable refresh interval (default 15 minutes). Pre-flight scope
 * guards on createZaak / createBesluit / createEnkelvoudigInformatieobject
 * read this cache and raise `InsufficientScopeException` before the
 * underlying HTTP call is issued (per REQ-ZGW-006).
 *
 * Refresh strategy: the cache is opportunistically refreshed when its TTL
 * has elapsed and `hasScope()` is called. Persistent failure to refresh
 * (network outage, AC outage) is logged but does NOT clear the cache —
 * stale scopes are preferred over a fail-closed default that would block
 * gemeente operations during a planned maintenance window. Cache lifetime
 * is bounded by `AC_REFRESH_INTERVAL_S` (default 900s); after twice that
 * window we treat the cache as expired and fail closed.
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
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-006
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Zgw;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Typed AC client with scope caching.
 */
class AcClient {
	/**
	 * Default scope-cache refresh interval (seconds).
	 */
	private const DEFAULT_REFRESH_S = 900;

	/**
	 * Per-endpoint scope map.
	 *
	 *   $cache[endpointId] = [
	 *     'refreshedAt' => int,
	 *     'scopes'      => [
	 *        resourceTypeUrl => [scope1, scope2, ...]
	 *     ],
	 *   ]
	 *
	 * @var array<string, array{refreshedAt:int, scopes: array<string, array<int,string>>}>
	 */
	private array $cache = [];

	/**
	 * Constructor.
	 *
	 * @param ZgwApiClient $api Base HTTP transport.
	 * @param ZgwRegisterAccess $registers ObjectService facade.
	 * @param IAppConfig $appConfig App config (refresh interval).
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
	 * Refresh the in-memory scope cache for an endpoint.
	 *
	 * Walks `/autorisaties` on the AC component and builds the
	 * `resourceUrl → [scope, ...]` map for every entry that mentions our
	 * `clientIdentifier`. Failures are logged but do not throw.
	 *
	 * @param array<string, mixed> $endpoint ZgwEndpoint payload.
	 * @param array<string, mixed> $client ZgwClient payload.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-006
	 */
	public function refreshScopes(array $endpoint, array $client): void {
		$endpointId = (string)($endpoint['id'] ?? ($endpoint['@self']['slug'] ?? ''));
		$acUrl = (string)($endpoint['componenten']['ac'] ?? '');
		if ($endpointId === '' || $acUrl === '') {
			return;
		}

		try {
			$response = $this->api->callComponent(
				componentUrl: $acUrl,
				method: 'GET',
				path: '/autorisaties',
				client: $client
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ZGW AC: refreshScopes failed',
				['endpoint' => $endpointId, 'err' => $e->getMessage()]
			);
			return;
		}

		$results = $response['body']['results'] ?? $response['body'];
		if (is_array($results) === false) {
			return;
		}

		$scopes = $this->buildScopeMap(
			results: $results,
			clientIdentifier: (string)($client['clientIdentifier'] ?? '')
		);

		$this->cache[$endpointId] = ['refreshedAt' => time(), 'scopes' => $scopes];
	}//end refreshScopes()

	/**
	 * Build the `resourceUrl → [scope, ...]` map from `/autorisaties` results.
	 *
	 * @param array<int, mixed> $results Decoded `/autorisaties` result rows.
	 * @param string $clientIdentifier Client identifier to filter entries by.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function buildScopeMap(array $results, string $clientIdentifier): array {
		$scopes = [];

		foreach ($results as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			if ($this->entryMatchesClient(entry: $entry, clientIdentifier: $clientIdentifier) === false) {
				continue;
			}

			$autorisaties = $entry['autorisaties'] ?? [$entry];
			if (is_array($autorisaties) === false) {
				continue;
			}

			$this->collectScopes(autorisaties: $autorisaties, scopes: $scopes);
		}//end foreach

		// De-duplicate.
		foreach ($scopes as $resource => $list) {
			$scopes[$resource] = array_values(array_unique($list));
		}

		return $scopes;
	}//end buildScopeMap()

	/**
	 * Decide whether an `/autorisaties` entry belongs to the configured client.
	 *
	 * @param array<string, mixed> $entry Single `/autorisaties` row.
	 * @param string $clientIdentifier Client identifier to match.
	 *
	 * @return bool True when the entry should be processed.
	 */
	private function entryMatchesClient(array $entry, string $clientIdentifier): bool {
		$clientIds = $entry['clientIds'] ?? [];
		if (is_array($clientIds) === false || $clientIdentifier === '') {
			return true;
		}

		if (in_array($clientIdentifier, $clientIds, true) === true) {
			return true;
		}

		$entryClient = (string)($entry['component'] ?? $entry['clientIds'][0] ?? '');
		return $entryClient === $clientIdentifier;
	}//end entryMatchesClient()

	/**
	 * Accumulate scopes from a list of `autorisaties` into the scope map.
	 *
	 * @param array<int, mixed> $autorisaties List of autorisatie entries.
	 * @param array<string, array<int, string>> $scopes Scope map to append to (by reference).
	 *
	 * @return void
	 */
	private function collectScopes(array $autorisaties, array &$scopes): void {
		foreach ($autorisaties as $auth) {
			if (is_array($auth) === false) {
				continue;
			}

			$resource = (string)($auth['caseType'] ?? $auth['besluittype'] ?? $auth['informatieobjecttype'] ?? '*');
			$list = $auth['scopes'] ?? [];
			if (is_array($list) === false) {
				continue;
			}

			foreach ($list as $scope) {
				if (is_string($scope) === false || $scope === '') {
					continue;
				}

				$scopes[$resource] = $scopes[$resource] ?? [];
				$scopes[$resource][] = $scope;
			}
		}//end foreach
	}//end collectScopes()

	/**
	 * Check whether the configured client holds a scope on a target resource.
	 *
	 * The lookup falls back across (resource-specific scopes) → (wildcard
	 * scopes on `*`). Returns `false` when the cache is empty or stale
	 * beyond two refresh intervals (fail-closed).
	 *
	 * @param array<string, mixed> $endpoint ZgwEndpoint payload.
	 * @param string $resourceUrl Target zaaktype/besluittype/informatieobjecttype URL.
	 * @param string $scope Scope name (e.g. "zaken.aanmaken").
	 *
	 * @return bool True when the scope is granted.
	 */
	public function hasScope(array $endpoint, string $resourceUrl, string $scope): bool {
		$endpointId = (string)($endpoint['id'] ?? ($endpoint['@self']['slug'] ?? ''));
		if ($endpointId === '' || $scope === '') {
			return false;
		}

		// Lazy refresh on stale cache.
		$bucket = $this->cache[$endpointId] ?? null;
		if ($bucket === null || (time() - $bucket['refreshedAt']) > $this->refreshInterval()) {
			$client = $this->registers->findClientForEndpoint($endpoint);
			if ($client !== null) {
				$this->refreshScopes(endpoint: $endpoint, client: $client);
			}

			$bucket = $this->cache[$endpointId] ?? null;
		}

		if ($bucket === null) {
			return false;
		}

		// Hard cap: anything older than 2 * refresh interval is unsafe.
		if ((time() - $bucket['refreshedAt']) > (2 * $this->refreshInterval())) {
			return false;
		}

		$list = $bucket['scopes'][$resourceUrl] ?? $bucket['scopes']['*'] ?? [];
		return in_array($scope, $list, true);
	}//end hasScope()

	/**
	 * Return all scopes granted on a specific resource URL.
	 *
	 * @param array<string, mixed> $endpoint ZgwEndpoint payload.
	 * @param string $resourceUrl Target resource URL.
	 *
	 * @return array<int, string>
	 */
	public function getScopesFor(array $endpoint, string $resourceUrl): array {
		$endpointId = (string)($endpoint['id'] ?? ($endpoint['@self']['slug'] ?? ''));
		if ($endpointId === '') {
			return [];
		}

		$bucket = $this->cache[$endpointId] ?? null;
		if ($bucket === null) {
			return [];
		}

		return array_values(
			array_unique(
				array_merge(
					$bucket['scopes'][$resourceUrl] ?? [],
					$bucket['scopes']['*'] ?? []
				)
			)
		);
	}//end getScopesFor()

	/**
	 * Pre-flight guard helper: raise on missing scope.
	 *
	 * @param array<string, mixed> $endpoint ZgwEndpoint payload.
	 * @param string $resourceUrl Target resource URL.
	 * @param string $scope Required scope.
	 *
	 * @return void
	 *
	 * @throws InsufficientScopeException When the scope is not granted.
	 */
	public function require(array $endpoint, string $resourceUrl, string $scope): void {
		if ($this->hasScope(endpoint: $endpoint, resourceUrl: $resourceUrl, scope: $scope) === true) {
			return;
		}

		throw new InsufficientScopeException(
			scope: $scope,
			caseTypeUrl: $resourceUrl,
			additionalInfo: 'Vraag de gemeente-beheerder om de juiste autorisatie te verlenen op het AC.'
		);
	}//end require()

	/**
	 * Inject a pre-built scope cache (testing helper).
	 *
	 * @param string $endpointId Endpoint id.
	 * @param array<string, array<int, string>> $scopes Resource →
	 *                                                  scopes map.
	 * @param int|null $refreshedAt Optional override timestamp.
	 *
	 * @return void
	 */
	public function primeCache(string $endpointId, array $scopes, ?int $refreshedAt = null): void {
		$this->cache[$endpointId] = ['refreshedAt' => $refreshedAt ?? time(), 'scopes' => $scopes];
	}//end primeCache()

	/**
	 * Effective refresh interval (seconds).
	 *
	 * @return int
	 */
	private function refreshInterval(): int {
		$value = $this->appConfig->getValueInt(Application::APP_ID, 'zgw.ac_refresh_interval', self::DEFAULT_REFRESH_S);
		if ($value > 0) {
			return $value;
		}

		return self::DEFAULT_REFRESH_S;
	}//end refreshInterval()
}//end class
