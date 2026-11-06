<?php

/**
 * Pipelinq ProspectDiscoveryService.
 *
 * Orchestrates prospect search, scoring, caching, and client exclusion.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * Orchestrator for prospect discovery.
 *
 * @spec openspec/specs/prospect-discovery/spec.md#requirement-ideal-customer-profile-configuration
 */
class ProspectDiscoveryService {
	/**
	 * Default cache TTL in seconds (1 hour) when unconfigured.
	 *
	 * Tunable via `pipelinq.prospect_discovery.cache_ttl_seconds`.
	 *
	 * @var int
	 */
	private const DEFAULT_CACHE_TTL = 3600;

	/**
	 * Cache key prefix. v2: entries hold the whole scored list, not the top 10.
	 *
	 * @var string
	 */
	private const CACHE_PREFIX = 'pipelinq_prospects_v2_';

	/**
	 * Constructor.
	 *
	 * @param IcpConfigService $icpConfig The ICP config service.
	 * @param ProspectSources  $sources   The KVK and OpenCorporates searches.
	 * @param ProspectRanking  $ranking   Client exclusion, scoring and the inactive filter.
	 * @param SettingsService  $settings  The settings service (cache lifetime).
	 */
	public function __construct(
		private IcpConfigService $icpConfig,
		private ProspectSources $sources,
		private ProspectRanking $ranking,
		private SettingsService $settings,
	) {
	}//end __construct()

	/**
	 * Discover prospects based on configured ICP, with the profile they were
	 * matched on under `icp`. The profile carries the criteria only, never the
	 * KVK API key, and comes along when no profile is set yet as well, so the
	 * prospects page can show what it searches for.
	 *
	 * @param bool $refresh Whether to bypass cache.
	 * @param int  $limit   How many of the best-scored prospects to return; 0 returns all of them.
	 *
	 * @return array The discovery results.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) — $refresh is a simple cache bypass toggle
	 * @spec                                        openspec/changes/reverse-2026-05-26-be-prospect/tasks.md#task-15
	 */
	public function discover(bool $refresh=false, int $limit=10): array {
		$result        = $this->collect(refresh: $refresh, limit: $limit);
		$result['icp'] = $this->icpConfig->getCriteria();

		return $result;
	}//end discover()

	/**
	 * Collect, score and cache the prospects for the configured ICP.
	 *
	 * @param bool $refresh Whether to bypass cache.
	 * @param int  $limit   How many of the best-scored prospects to return; 0 returns all of them.
	 *
	 * @return array The discovery results.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) — $refresh is a simple cache bypass toggle
	 * @spec                                        openspec/changes/reverse-2026-05-26-be-prospect/tasks.md#task-15
	 */
	private function collect(bool $refresh, int $limit): array {
		if ($this->icpConfig->isConfigured() === false) {
			return [
				'error'   => 'no_icp_configured',
				'message' => 'Configure your Ideal Customer Profile in admin settings first',
			];
		}

		$icpHash = $this->icpConfig->getIcpHash();
		$cached  = $this->cached(refresh: $refresh, icpHash: $icpHash);
		if ($cached !== null) {
			return $this->limitResult(result: $this->withoutNewClients(result: $cached), limit: $limit);
		}

		return $this->limitResult(result: $this->search(icpHash: $icpHash), limit: $limit);
	}//end collect()

	/**
	 * The cached result for the profile, unless the caller asks to refresh.
	 *
	 * @param bool   $refresh Whether to bypass the cache.
	 * @param string $icpHash The profile's hash.
	 *
	 * @return array|null
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) — $refresh is a simple cache bypass toggle
	 */
	private function cached(bool $refresh, string $icpHash): ?array {
		if ($refresh === true) {
			return null;
		}

		return $this->getFromCache(key: self::CACHE_PREFIX . $icpHash);
	}//end cached()

	/**
	 * Search, rank and cache the whole scored list for the profile.
	 *
	 * @param string $icpHash The profile's hash.
	 *
	 * @return array The discovery result.
	 */
	private function search(string $icpHash): array {
		$criteria  = $this->icpConfig->getCriteria();
		$prospects = $this->ranking->rank(prospects: $this->sources->fetch(criteria: $criteria), criteria: $criteria);

		$result = [
			'prospects' => $prospects,
			'total'     => count($prospects),
			'cachedAt'  => date(format: 'c'),
			'icpHash'   => $icpHash,
		];
		$this->setInCache(key: self::CACHE_PREFIX . $icpHash, data: $result);
		return $result;
	}//end search()

	/**
	 * A cached result without the prospects that have since become clients.
	 *
	 * @param array $result The cached result.
	 *
	 * @return array
	 */
	private function withoutNewClients(array $result): array {
		$result['prospects'] = $this->ranking->withoutClients(prospects: $result['prospects'] ?? []);
		$result['total']     = count($result['prospects']);
		return $result;
	}//end withoutNewClients()

	/**
	 * Cut a discovery result down to its best-scored prospects.
	 *
	 * @param array $result The full discovery result, prospects sorted by score.
	 * @param int   $limit  How many prospects to keep; 0 keeps all of them.
	 *
	 * @return array The result with `prospects` and `displayed` set for the limit.
	 */
	private function limitResult(array $result, int $limit): array {
		$prospects = $result['prospects'] ?? [];
		if ($limit > 0) {
			$prospects = array_slice(array: $prospects, offset: 0, length: $limit);
		}

		$result['prospects'] = $prospects;
		$result['displayed'] = count($prospects);

		return $result;
	}//end limitResult()

	/**
	 * Get cached results.
	 *
	 * @param string $key The cache key.
	 *
	 * @return array|null The cached data or null.
	 * @spec openspec/changes/reverse-2026-05-26-be-prospect/tasks.md#task-15
	 */
	protected function getFromCache(string $key): ?array {
		if (function_exists(function: 'apcu_fetch') === false) {
			return null;
		}

		$success = false;
		$data = apcu_fetch(key: $key, success: $success);

		if ($success === true && is_array(value: $data) === true) {
			return $data;
		}

		return null;
	}//end getFromCache()

	/**
	 * Store results in cache.
	 *
	 * @param string $key The cache key.
	 * @param array $data The data to cache.
	 *
	 * @return void
	 * @spec openspec/changes/reverse-2026-05-26-be-prospect/tasks.md#task-15
	 */
	protected function setInCache(string $key, array $data): void {
		if (function_exists(function: 'apcu_store') === true) {
			$ttl = $this->settings->getIntValue(
				'prospect_discovery.cache_ttl_seconds',
				self::DEFAULT_CACHE_TTL
			);
			apcu_store($key, $data, $ttl);
		}
	}//end setInCache()
}//end class
