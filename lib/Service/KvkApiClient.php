<?php

/**
 * Pipelinq KvkApiClient.
 *
 * HTTP client for KVK Handelsregister Zoeken API integration.
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
     */
    public function __construct(
        private IClientService $clientService,
        private LoggerInterface $logger,
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
            $this->searchBySbiCode($apiKey, $sbiCode, $results);
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
            $body = $this->fetchResults($apiKey);

            if (isset($body['resultaten']) === false) {
                return;
            }

            foreach ($body['resultaten'] as $item) {
                $mapped = $this->mapResult(item: $item, sbiCode: $sbiCode);
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
                'headers' => [
                    'Accept' => 'application/json',
                ],
                'timeout' => 15,
            ]
        );

        return json_decode(json: $response->getBody(), associative: true) ?: [];
    }//end fetchResults()

    /**
     * Map a KVK API result to our prospect format.
     *
     * @param array  $item    The raw API result item.
     * @param string $sbiCode The SBI code that matched.
     *
     * @return array|null The mapped result or null.
     */
    private function mapResult(array $item, string $sbiCode): ?array
    {
        $kvkNumber = $item['kvkNummer'] ?? null;
        if ($kvkNumber === null) {
            return null;
        }

        $address = $item['adres'] ?? ($item['vestingAdres'] ?? []);

        return [
            'kvkNumber'        => (string) $kvkNumber,
            'tradeName'        => $item['eersteHandelsnaam'] ?? ($item['naam'] ?? ''),
            'legalForm'        => $item['rechtsvorm'] ?? '',
            'sbiCode'          => $sbiCode,
            'sbiDescription'   => $this->findSbiDescription($item, $sbiCode),
            'employeeCount'    => $item['totaalWerkzamePersonen'] ?? null,
            'address'          => $this->mapAddress($address),
            'website'          => null,
            'registrationDate' => $item['registratieDatum'] ?? null,
            'isActive'         => ($item['actief'] ?? 'Ja') === 'Ja',
            'source'           => 'kvk',
        ];
    }//end mapResult()

    /**
     * Find the SBI description matching the given code.
     *
     * @param array  $item    The raw API result item.
     * @param string $sbiCode The SBI code to look for.
     *
     * @return string The SBI description or empty string.
     */
    private function findSbiDescription(array $item, string $sbiCode): string
    {
        $sbiActivities = $item['spiActiviteiten'] ?? [];

        foreach ($sbiActivities as $activity) {
            $activityCode = (string) ($activity['sbiCode'] ?? '');
            if (str_starts_with(haystack: $activityCode, needle: $sbiCode) === true) {
                return $activity['sbiOmschrijving'] ?? '';
            }
        }

        return '';
    }//end findSbiDescription()

    /**
     * Map a KVK address to our format.
     *
     * @param array $address The raw address data.
     *
     * @return array The mapped address.
     */
    private function mapAddress(array $address): array
    {
        return [
            'street'     => ($address['straatnaam'] ?? '').' '.($address['huisnummer'] ?? ''),
            'city'       => $address['plaats'] ?? '',
            'province'   => $address['provincie'] ?? '',
            'postalCode' => $address['postcode'] ?? '',
        ];
    }//end mapAddress()
}//end class
