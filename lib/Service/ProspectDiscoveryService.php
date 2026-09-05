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

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

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
	 * Cache key prefix.
	 *
	 * @var string
	 */
	private const CACHE_PREFIX = 'pipelinq_prospects_';

	/**
	 * Constructor.
	 *
	 * @param IcpConfigService $icpConfig The ICP config service.
	 * @param KvkApiClient $kvkClient The KVK API client.
	 * @param OpenCorporatesApiClient $ocClient The OpenCorporates client.
	 * @param ProspectScoringService $scoring The scoring service.
	 * @param SettingsService $settings The settings service.
	 * @param LoggerInterface $logger The logger.
	 * @param ContainerInterface $container The DI container.
	 * @param IAppManager $appManager The app manager.
	 */
	public function __construct(
		private IcpConfigService $icpConfig,
		private KvkApiClient $kvkClient,
		private OpenCorporatesApiClient $ocClient,
		private ProspectScoringService $scoring,
		private SettingsService $settings,
		private LoggerInterface $logger,
		private ContainerInterface $container,
		private IAppManager $appManager,
	) {
	}//end __construct()

	/**
	 * Discover prospects based on configured ICP.
	 *
	 * @param bool $refresh Whether to bypass cache.
	 *
	 * @return array The discovery results.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)  — $refresh is a simple cache bypass toggle
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) — orchestration method with multiple sources
	 * @SuppressWarnings(PHPMD.NPathComplexity)      — orchestration method with multiple sources
	 * @spec                                         openspec/changes/reverse-2026-05-26-be-prospect/tasks.md#task-15
	 */
	public function discover(bool $refresh = false): array {
		if ($this->icpConfig->isConfigured() === false) {
			return [
				'error' => 'no_icp_configured',
				'message' => 'Configure your Ideal Customer Profile in admin settings first',
			];
		}

		$icpHash = $this->icpConfig->getIcpHash();
		$cacheKey = self::CACHE_PREFIX . $icpHash;

		// Check cache.
		if ($refresh === false && function_exists(function: 'apcu_exists') === true) {
			$cached = $this->getFromCache(key: $cacheKey);
			if ($cached !== null) {
				return $cached;
			}
		}

		$criteria = $this->icpConfig->getCriteria();
		$apiKey = $this->icpConfig->getKvkApiKey();

		$prospects = [];

		// Fetch from KVK.
		try {
			$kvkResults = $this->kvkClient->search(apiKey: $apiKey, criteria: $criteria);
			foreach ($kvkResults as $result) {
				$prospects[$result['kvkNumber']] = $result;
			}
		} catch (\Exception $e) {
			$this->logger->error(
				message: 'KVK API search failed',
				context: ['error' => $e->getMessage()]
			);
		}//end try

		// Fetch from OpenCorporates (if enabled).
		try {
			$ocEnabled = $this->icpConfig->getSettings()['openCorporatesEnabled'] ?? false;
			if ($ocEnabled === true) {
				$ocResults = $this->ocClient->search(criteria: $criteria);
				foreach ($ocResults as $result) {
					if (isset($prospects[$result['kvkNumber']]) === false) {
						$prospects[$result['kvkNumber']] = $result;
					}
				}
			}
		} catch (\Exception $e) {
			$this->logger->warning(
				message: 'OpenCorporates search failed',
				context: ['error' => $e->getMessage()]
			);
		}//end try

		// Exclude existing clients.
		$prospects = $this->excludeExistingClients(prospects: array_values(array: $prospects));

		// Score and sort.
		$prospects = $this->scoring->scoreAll(prospects: $prospects, criteria: $criteria);

		// Filter inactive if configured.
		if (($criteria['excludeInactive'] ?? true) === true) {
			$prospects = array_values(
				array: array_filter(
					array: $prospects,
					callback: fn (array $prospect): bool => ($prospect['isActive'] ?? true) === true
				)
			);
		}

		$result = [
			'prospects' => array_slice(array: $prospects, offset: 0, length: 10),
			'total' => count($prospects),
			'displayed' => min(count($prospects), 10),
			'cachedAt' => date(format: 'c'),
			'icpHash' => $icpHash,
		];

		// Store in cache.
		$this->setInCache(key: $cacheKey, data: $result);

		return $result;
	}//end discover()

	/**
	 * Exclude existing clients from prospect results by matching company names.
	 *
	 * @param array $prospects The prospects to filter.
	 *
	 * @return array The filtered prospects.
	 */
	private function excludeExistingClients(array $prospects): array {
		$clientNames = $this->getExistingClientNames();

		if (count($clientNames) === 0) {
			return $prospects;
		}

		return array_values(
			array: array_filter(
				array: $prospects,
				callback: function (array $prospect) use ($clientNames): bool {
					$tradeName = strtolower(string: trim(string: $prospect['tradeName'] ?? ''));
					if ($tradeName === '') {
						return true;
					}

					// Use strict normalised equality only; bidirectional str_contains
					// produces false positives on common substrings like "BV" or "Group".
					return in_array($tradeName, $clientNames, true) === false;
				}
			)
		);
	}//end excludeExistingClients()

	/**
	 * Get names of existing clients (lowercased) from OpenRegister.
	 *
	 * @return array The client names.
	 */
	private function getExistingClientNames(): array {
		try {
			$register = $this->settings->getConfigValue(key: 'register');
			$schema = $this->settings->getConfigValue(key: 'client_schema');

			if ($register === '' || $schema === '') {
				return [];
			}

			if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
				return [];
			}

			/*
			 * @var \OCA\OpenRegister\Service\ObjectService $objectService
			 */

			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

			$clients = $objectService->findAll(
				[
					'filters' => [
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 1000,
				]
			);

			$names = [];
			foreach ($clients as $client) {
				$name = $client['name'] ?? $client['tradeName'] ?? '';
				if ($name !== '') {
					$names[] = strtolower(trim($name));
				}
			}

			return $names;
		} catch (\Exception $e) {
			$this->logger->warning(
				message: 'Failed to fetch existing clients for exclusion',
				context: ['error' => $e->getMessage()]
			);
			return [];
		}//end try
	}//end getExistingClientNames()

	/**
	 * Get cached results.
	 *
	 * @param string $key The cache key.
	 *
	 * @return array|null The cached data or null.
	 */
	private function getFromCache(string $key): ?array {
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
	 */
	private function setInCache(string $key, array $data): void {
		if (function_exists(function: 'apcu_store') === true) {
			$ttl = $this->settings->getIntValue(
				'prospect_discovery.cache_ttl_seconds',
				self::DEFAULT_CACHE_TTL
			);
			apcu_store($key, $data, $ttl);
		}
	}//end setInCache()
}//end class
