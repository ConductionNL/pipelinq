<?php

/**
 * Pipelinq KvkApiClient.
 *
 * HTTP client for KVK Handelsregister Zoeken API integration.
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
 * @spec openspec/specs/prospect-discovery/spec.md#requirement-kvk-api-integration
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
 * Client for the KVK Handelsregister Zoeken API.
 *
 * @spec openspec/specs/prospect-discovery/spec.md#requirement-kvk-api-integration
 */
class KvkApiClient
{
    /**
     * Default KVK API base URL when unconfigured.
     *
     * The effective base URL is admin-tunable via `pipelinq.kvk.api_base_url`
     * so EU/regional tenants can point at an alternate endpoint. The value is
     * admin-only (written through the admin-gated SettingsController); no
     * end-user input reaches the request URL, so there is no SSRF regression.
     *
     * @var string
     */
    private const DEFAULT_API_BASE = 'https://api.kvk.nl/api/v1';

    /**
     * Constructor.
     *
     * @param IClientService  $clientService The HTTP client service.
     * @param IAppConfig      $appConfig     The app config.
     * @param LoggerInterface $logger        The logger.
     * @param KvkResultMapper $resultMapper  The result mapper.
     * @param IURLGenerator   $urlGenerator  URL generator (resolves the OR leaf endpoint).
     */
    public function __construct(
        private IClientService $clientService,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
        private KvkResultMapper $resultMapper,
        private IURLGenerator $urlGenerator,
    ) {
    }//end __construct()

    /**
     * Get the admin-configured KVK API base URL (default https://api.kvk.nl/api/v1).
     *
     * @return string The base URL with no trailing slash.
     */
    private function getApiBase(): string
    {
        return rtrim(
            $this->appConfig->getValueString(
                Application::APP_ID,
                'kvk.api_base_url',
                self::DEFAULT_API_BASE
            ),
            '/'
        );
    }//end getApiBase()

    /**
     * Search the KVK API for companies matching the given criteria.
     *
     * @param string $apiKey   The KVK API key.
     * @param array  $criteria The search criteria.
     *
     * @return array The search results.
     *
     * @spec openspec/specs/prospect-discovery/spec.md#requirement-kvk-api-integration
     */
    public function search(string $apiKey, array $criteria): array
    {
        if ($apiKey === '') {
            return [];
        }

        $results  = [];
        $sbiCodes = $criteria['sbiCodes'] ?? [];

        foreach ($sbiCodes as $sbiCode) {
            $this->searchBySbiCode(apiKey: $apiKey, sbiCode: $sbiCode, results: $results);
        }

        return array_values(array: $results);
    }//end search()

    /**
     * Search for a single SBI code and merge results.
     *
     * @param string $apiKey  The KVK API key.
     * @param string $sbiCode The SBI code to search.
     * @param array  $results The results array to populate (by reference).
     *
     * @return void
     */
    private function searchBySbiCode(string $apiKey, string $sbiCode, array &$results): void
    {
        try {
            $body = $this->fetchResults(apiKey: $apiKey, sbiCode: $sbiCode);

            if (isset($body['resultaten']) === false) {
                return;
            }

            foreach ($body['resultaten'] as $item) {
                $mapped = $this->resultMapper->mapResult(item: $item, sbiCode: $sbiCode);
                if ($mapped !== null) {
                    $results[$mapped['kvkNumber']] = $mapped;
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning(
                message: 'KVK API search failed for SBI code {sbi}',
                context: ['sbi' => $sbiCode, 'error' => $e->getMessage()]
            );
        }//end try
    }//end searchBySbiCode()

    /**
     * Fetch results from the KVK API filtered by SBI code.
     *
     * @param string $apiKey  The KVK API key.
     * @param string $sbiCode The SBI code to filter on.
     *
     * @return array The decoded response body.
     */
    private function fetchResults(string $apiKey, string $sbiCode): array
    {
        // OR-first (ADR-022): try the OpenRegister KvK leaf, which round-trips
        // the raw KvK rows. On 200 we return them in the legacy `resultaten`
        // shape so the existing mapper loop is byte-identical. On 503 (source
        // not usable) / OR-absent the method returns null and we fall back to
        // the legacy direct path below, so configured envs keep working.
        $viaOr = $this->fetchViaOpenRegister(sbiCode: $sbiCode);
        if ($viaOr !== null) {
            return ['resultaten' => $viaOr];
        }

        $queryParams = [
            'apikey'             => $apiKey,
            'type'               => 'hoofdvestiging',
            'pagina'             => '1',
            'aantal'             => '50',
            'sbiHoofdActiviteit' => $sbiCode,
        ];

        $url = $this->getApiBase().'/zoeken?'.http_build_query(data: $queryParams);

        $client   = $this->clientService->newClient();
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
    }//end fetchResults()

    /**
     * Fetch raw KvK rows via OpenRegister's OpenConnector-routed leaf (ADR-022).
     *
     * Calls `GET /apps/openregister/api/integrations/kvk/search` server-side
     * (internal, OCS-APIREQUEST, allow_local_address). On HTTP 200 returns the
     * raw KvK rows under `results` (the same shape the legacy `/zoeken` path
     * yields under `resultaten`), so {@see searchBySbiCode()} maps them through
     * {@see KvkResultMapper} exactly as today. Returns null when the OR `kvk`
     * source is not usable yet — OR responds 503 with `details.cause`
     * (`openconnector-down` / `openconnector-source-missing` /
     * `upstream-service-down`), or OR/openregister is absent — so the caller
     * falls back to the legacy direct path and configured envs keep working
     * until an operator enables the OR source.
     *
     * @param string $sbiCode The SBI code to filter on.
     *
     * @return array<int,array<string,mixed>>|null Raw KvK rows, or null to fall back.
     *
     * @spec openspec/changes/archive/2026-06-21-pipelinq-lookups-via-or-leaf/specs/company-lookup/spec.md
     */
    private function fetchViaOpenRegister(string $sbiCode): ?array
    {
        $params = http_build_query(
            [
                'sbiHoofdActiviteit' => $sbiCode,
                'type'               => 'hoofdvestiging',
                'limit'              => 50,
                'page'               => 1,
            ]
        );
        $url    = $this->urlGenerator->getAbsoluteURL('/apps/openregister/api/integrations/kvk/search?'.$params);

        try {
            $client   = $this->clientService->newClient();
            $response = $client->get(
                    $url,
                    [
                        'timeout'         => 15,
                        'connect_timeout' => 3,
                        'headers'         => ['OCS-APIREQUEST' => 'true', 'Accept' => 'application/json'],
                        'nextcloud'       => ['allow_local_address' => true],
                    ]
                    );
        } catch (Throwable $e) {
            // 503 (source not usable) surfaces as a client exception here — fall
            // back to the legacy path. Also covers connection refused / OR absent.
            $this->logger->debug('KVK OR search unavailable, falling back to direct path', ['exception' => $e]);
            return null;
        }

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $decoded = json_decode((string) $response->getBody(), true);
        if (is_array($decoded) === false || isset($decoded['results']) === false || is_array($decoded['results']) === false) {
            return null;
        }

        $rows = [];
        foreach ($decoded['results'] as $row) {
            if (is_array($row) === true) {
                $rows[] = $row;
            }
        }

        return $rows;
    }//end fetchViaOpenRegister()
}//end class
