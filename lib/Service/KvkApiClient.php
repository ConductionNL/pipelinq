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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Client for the KVK Handelsregister Zoeken API.
 */
class KvkApiClient
{
    /**
     * KVK API base URL.
     *
     * @var string
     */
    private const API_BASE = 'https://api.kvk.nl/api/v1';

    /**
     * Constructor.
     *
     * @param IClientService  $clientService The HTTP client service.
     * @param LoggerInterface $logger        The logger.
     * @param KvkResultMapper $resultMapper  The result mapper.
     */
    public function __construct(
        private IClientService $clientService,
        private LoggerInterface $logger,
        private KvkResultMapper $resultMapper,
    ) {
    }//end __construct()

    /**
     * Search the KVK API for companies matching the given criteria.
     *
     * @param string $apiKey   The KVK API key.
     * @param array  $criteria The search criteria.
     *
     * @return array The search results.
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
            $body = $this->fetchResults(apiKey: $apiKey);

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
     * Fetch results from the KVK API.
     *
     * @param string $apiKey The KVK API key.
     *
     * @return array The decoded response body.
     */
    private function fetchResults(string $apiKey): array
    {
        $queryParams = [
            'apikey' => $apiKey,
            'type'   => 'hoofdvestiging',
            'pagina' => '1',
            'aantal' => '50',
        ];

        $url = self::API_BASE.'/zoeken?'.http_build_query(data: $queryParams);

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
}//end class
