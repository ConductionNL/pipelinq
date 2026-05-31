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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-37
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Client for the OpenCorporates API.
 */
class OpenCorporatesApiClient
{
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
     * @param IClientService             $clientService The HTTP client service.
     * @param IAppConfig                 $appConfig     The app config.
     * @param LoggerInterface            $logger        The logger.
     * @param OpenCorporatesResultMapper $resultMapper  The result mapper.
     */
    public function __construct(
        private IClientService $clientService,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
        private OpenCorporatesResultMapper $resultMapper,
    ) {
    }//end __construct()

    /**
     * Get the admin-configured OpenCorporates API base URL.
     *
     * Default https://api.opencorporates.com/v0.4.
     *
     * @return string The base URL with no trailing slash.
     */
    private function getApiBase(): string
    {
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
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-37
     */
    public function search(array $criteria): array
    {
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
     * @param array  $results The results array to populate (by reference).
     *
     * @return void
     */
    private function searchByKeyword(string $keyword, array &$results): void
    {
        try {
            $body      = $this->fetchCompanies(keyword: $keyword);
            $companies = $body['results']['companies'] ?? [];

            foreach ($companies as $entry) {
                $company = $entry['company'] ?? [];
                $mapped  = $this->resultMapper->mapResult(company: $company);
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
    private function fetchCompanies(string $keyword): array
    {
        $queryParams = [
            'q'                 => $keyword,
            'jurisdiction_code' => 'nl',
            'per_page'          => '30',
            'order'             => 'score',
        ];

        $url = $this->getApiBase().'/companies/search?'.http_build_query(data: $queryParams);

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
    }//end fetchCompanies()
}//end class
