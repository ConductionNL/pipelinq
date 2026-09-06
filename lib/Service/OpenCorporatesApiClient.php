<?php

/**
 * Pipelinq OpenCorporatesApiClient.
 *
 * HTTP client for optional OpenCorporates API integration.
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
 *
 * @spec openspec/specs/prospect-discovery/spec.md#requirement-opencorporates-integration
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Client for the OpenCorporates API.
 *
 * @spec openspec/specs/prospect-discovery/spec.md#requirement-ideal-customer-profile-configuration
 */
class OpenCorporatesApiClient {
	/**
	 * Default OpenCorporates API base URL when unconfigured.
	 *
	 * The effective base URL is admin-tunable via
	 * `pipelinq.opencorporates.api_base_url`. The value is admin-only (written
	 * through the admin-gated SettingsController); no end-user input reaches
	 * the request URL, so there is no SSRF regression.
	 *
	 * @var string
	 */
	private const DEFAULT_API_BASE = 'https://api.opencorporates.com/v0.4';

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService The HTTP client service.
	 * @param IAppConfig $appConfig The app config.
	 * @param LoggerInterface $logger The logger.
	 * @param OpenCorporatesResultMapper $resultMapper The result mapper.
	 * @param IURLGenerator $urlGenerator URL generator (resolves the OR leaf endpoint).
	 */
	public function __construct(
		private IClientService $clientService,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private OpenCorporatesResultMapper $resultMapper,
		private IURLGenerator $urlGenerator,
	) {
	}//end __construct()

	/**
	 * Get the admin-configured OpenCorporates API base URL.
	 *
	 * Default https://api.opencorporates.com/v0.4.
	 *
	 * @return string The base URL with no trailing slash.
	 */
	private function getApiBase(): string {
		return rtrim(
			$this->appConfig->getValueString(
				Application::APP_ID,
				'opencorporates.api_base_url',
				self::DEFAULT_API_BASE
			),
			'/'
		);
	}//end getApiBase()

	/**
	 * Search OpenCorporates for Dutch companies.
	 *
	 * @param array $criteria The search criteria.
	 *
	 * @return array The search results.
	 *
	 * @spec openspec/specs/prospect-discovery/spec.md#requirement-opencorporates-integration
	 */
	public function search(array $criteria): array {
		$keywords = $criteria['keywords'] ?? [];

		if (count($keywords) === 0) {
			return [];
		}

		$results = [];
		foreach ($keywords as $keyword) {
			$this->searchByKeyword(keyword: $keyword, results: $results);
		}

		return array_values(array: $results);
	}//end search()

	/**
	 * Search for a single keyword and merge results.
	 *
	 * @param string $keyword The keyword to search.
	 * @param array $results The results array to populate (by reference).
	 *
	 * @return void
	 */
	private function searchByKeyword(string $keyword, array &$results): void {
		try {
			$body = $this->fetchCompanies(keyword: $keyword);
			$companies = $body['results']['companies'] ?? [];

			foreach ($companies as $entry) {
				$company = $entry['company'] ?? [];
				$mapped = $this->resultMapper->mapResult(company: $company);
				if ($mapped !== null) {
					$results[$mapped['kvkNumber']] = $mapped;
				}
			}
		} catch (\Exception $e) {
			$this->logger->warning(
				message: 'OpenCorporates search failed for keyword {kw}',
				context: ['kw' => $keyword, 'error' => $e->getMessage()]
			);
		}//end try
	}//end searchByKeyword()

	/**
	 * Fetch companies from the OpenCorporates API.
	 *
	 * @param string $keyword The search keyword.
	 *
	 * @return array The decoded response body.
	 */
	private function fetchCompanies(string $keyword): array {
		// OR-first (ADR-022): try the OpenRegister OpenCorporates leaf, which
		// round-trips the raw company objects. On 200 we re-wrap them in the
		// legacy `results.companies[].company` shape so the existing mapper
		// loop is byte-identical. On 503 (source not usable) / OR-absent the
		// method returns null and we fall back to the legacy direct path below.
		$viaOr = $this->fetchViaOpenRegister(keyword: $keyword);
		if ($viaOr !== null) {
			return ['results' => ['companies' => $viaOr]];
		}

		$queryParams = [
			'q' => $keyword,
			'jurisdiction_code' => 'nl',
			'per_page' => '30',
			'order' => 'score',
		];

		$url = $this->getApiBase() . '/companies/search?' . http_build_query(data: $queryParams);

		$client = $this->clientService->newClient();
		$response = $client->get(
			uri: $url,
			options: [
				'headers' => ['Accept' => 'application/json'],
				'timeout' => 15,
			]
		);

		$decoded = json_decode(json: $response->getBody(), associative: true);

		if (is_array(value: $decoded) === true) {
			return $decoded;
		}

		return [];
	}//end fetchCompanies()

	/**
	 * Fetch raw company rows via OpenRegister's OpenConnector-routed leaf (ADR-022).
	 *
	 * Calls `GET /apps/openregister/api/integrations/opencorporates/search`
	 * server-side (internal, OCS-APIREQUEST, allow_local_address). On HTTP 200
	 * returns the raw OpenCorporates company objects (the leaf unwraps
	 * `results.companies[].company`), each re-wrapped as `['company' => $row]`
	 * so {@see searchByKeyword()} maps them through
	 * {@see OpenCorporatesResultMapper} exactly as today. Returns null when the
	 * OR `opencorporates` source is not usable yet — OR responds 503 with
	 * `details.cause`, or OR/openregister is absent — so the caller falls back
	 * to the legacy direct path and configured envs keep working until an
	 * operator enables the OR source.
	 *
	 * @param string $keyword The search keyword.
	 *
	 * @return array<int,array<string,mixed>>|null Wrapped company rows, or null to fall back.
	 *
	 * @spec openspec/changes/archive/2026-06-21-pipelinq-lookups-via-or-leaf/specs/company-lookup/spec.md
	 */
	private function fetchViaOpenRegister(string $keyword): ?array {
		$params = http_build_query(
			[
				'q' => $keyword,
				'jurisdiction' => 'nl',
				'limit' => 30,
				'page' => 1,
			]
		);
		$url = $this->urlGenerator->getAbsoluteURL('/apps/openregister/api/integrations/opencorporates/search?' . $params);

		try {
			$client = $this->clientService->newClient();
			$response = $client->get(
				$url,
				[
					'timeout' => 15,
					'connect_timeout' => 3,
					'headers' => ['OCS-APIREQUEST' => 'true', 'Accept' => 'application/json'],
					'nextcloud' => ['allow_local_address' => true],
				]
			);
		} catch (Throwable $e) {
			// 503 (source not usable) surfaces as a client exception here — fall
			// back to the legacy path. Also covers connection refused / OR absent.
			$this->logger->debug('OpenCorporates OR search unavailable, falling back to direct path', ['exception' => $e]);
			return null;
		}

		if ($response->getStatusCode() !== 200) {
			return null;
		}

		$decoded = json_decode((string)$response->getBody(), true);
		if (is_array($decoded) === false || isset($decoded['results']) === false || is_array($decoded['results']) === false) {
			return null;
		}

		$rows = [];
		foreach ($decoded['results'] as $row) {
			if (is_array($row) === true) {
				$rows[] = ['company' => $row];
			}
		}

		return $rows;
	}//end fetchViaOpenRegister()
}//end class
