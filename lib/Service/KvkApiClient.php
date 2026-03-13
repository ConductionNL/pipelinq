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

        // Search by SBI codes.
        foreach ($sbiCodes as $sbiCode) {
            try {
                $queryParams = [
                    'apikey' => $apiKey,
                    'type'   => 'hoofdvestiging',
                    'pagina' => '1',
                    'aantal' => '50',
                ];

                // Add location filters.
                if (isset($criteria['provinces']) === true && count($criteria['provinces']) > 0) {
                    // KVK API doesn't support province filter directly,
                    // so we filter in post-processing.
                }

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

                $body = json_decode(json: $response->getBody(), associative: true);

                if (isset($body['resultaten']) === true) {
                    foreach ($body['resultaten'] as $item) {
                        $mapped = $this->mapResult(item: $item, sbiCode: $sbiCode);
                        if ($mapped !== null) {
                            $results[$mapped['kvkNumber']] = $mapped;
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->logger->warning(
                    message: 'KVK API search failed for SBI code {sbi}',
                    context: ['sbi' => $sbiCode, 'error' => $e->getMessage()]
                );
            }//end try
        }//end foreach

        return array_values(array: $results);
    }//end search()

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

        $address        = $item['adres'] ?? ($item['vestingAdres'] ?? []);
        $sbiActivities  = $item['spiActiviteiten'] ?? [];
        $sbiDescription = '';

        foreach ($sbiActivities as $activity) {
            if (str_starts_with(haystack: (string) ($activity['sbiCode'] ?? ''), needle: $sbiCode) === true) {
                $sbiDescription = $activity['sbiOmschrijving'] ?? '';
                break;
            }
        }

        return [
            'kvkNumber'        => (string) $kvkNumber,
            'tradeName'        => $item['eersteHandelsnaam'] ?? ($item['naam'] ?? ''),
            'legalForm'        => $item['rechtsvorm'] ?? '',
            'sbiCode'          => $sbiCode,
            'sbiDescription'   => $sbiDescription,
            'employeeCount'    => $item['totaalWerkzamePersonen'] ?? null,
            'address'          => [
                'street'     => ($address['straatnaam'] ?? '').' '.($address['huisnummer'] ?? ''),
                'city'       => $address['plaats'] ?? '',
                'province'   => $address['provincie'] ?? '',
                'postalCode' => $address['postcode'] ?? '',
            ],
            'website'          => null,
            'registrationDate' => $item['registratieDatum'] ?? null,
            'isActive'         => ($item['actief'] ?? 'Ja') === 'Ja',
            'source'           => 'kvk',
        ];
    }//end mapResult()
}//end class
