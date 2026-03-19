<?php

/**
 * Pipelinq OpenCorporatesApiClient.
 *
 * HTTP client for optional OpenCorporates API integration.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Client for the OpenCorporates API.
 */
class OpenCorporatesApiClient
{
    /**
     * OpenCorporates API base URL.
     *
     * @var string
     */
    private const API_BASE = 'https://api.opencorporates.com/v0.4';

    /**
     * Constructor.
     *
     * @param IClientService  $clientService The HTTP client service.
     * @param LoggerInterface $logger        The logger.
     */
    public function __construct(
        private IClientService $clientService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Search OpenCorporates for Dutch companies.
     *
     * @param array $criteria The search criteria.
     *
     * @return array The search results.
     */
    public function search(array $criteria): array
    {
        $keywords = $criteria['keywords'] ?? [];

        if (count($keywords) === 0) {
            return [];
        }

        $results = [];
        foreach ($keywords as $keyword) {
            $this->searchByKeyword($keyword, $results);
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
            $body      = $this->fetchCompanies($keyword);
            $companies = $body['results']['companies'] ?? [];

            foreach ($companies as $entry) {
                $company = $entry['company'] ?? [];
                $mapped  = $this->mapResult(company: $company);
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

        $url = self::API_BASE.'/companies/search?'.http_build_query(data: $queryParams);

        $client   = $this->clientService->newClient();
        $response = $client->get(
            uri: $url,
            options: [
                'headers' => ['Accept' => 'application/json'],
                'timeout' => 15,
            ]
        );

        return json_decode(json: $response->getBody(), associative: true) ?: [];
    }//end fetchCompanies()

    /**
     * Map an OpenCorporates result to our prospect format.
     *
     * @param array $company The raw company data.
     *
     * @return array|null The mapped result or null.
     */
    private function mapResult(array $company): ?array
    {
        $companyNumber = $company['company_number'] ?? null;
        if ($companyNumber === null) {
            return null;
        }

        $address = $company['registered_address'] ?? [];

        return [
            'kvkNumber'        => (string) $companyNumber,
            'tradeName'        => $company['name'] ?? '',
            'legalForm'        => $company['company_type'] ?? '',
            'sbiCode'          => '',
            'sbiDescription'   => $company['industry_codes'][0]['description'] ?? '',
            'employeeCount'    => null,
            'address'          => $this->mapAddress($address),
            'website'          => null,
            'registrationDate' => $company['incorporation_date'] ?? null,
            'isActive'         => ($company['current_status'] ?? 'Active') === 'Active',
            'source'           => 'opencorporates',
        ];
    }//end mapResult()

    /**
     * Map an OpenCorporates address to our format.
     *
     * @param array $address The raw address data.
     *
     * @return array The mapped address.
     */
    private function mapAddress(array $address): array
    {
        return [
            'street'     => $address['street_address'] ?? '',
            'city'       => $address['locality'] ?? '',
            'province'   => $address['region'] ?? '',
            'postalCode' => $address['postal_code'] ?? '',
        ];
    }//end mapAddress()
}//end class
