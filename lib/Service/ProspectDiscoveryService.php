<?php

/**
 * Pipelinq ProspectDiscoveryService.
 *
 * Orchestrates prospect search, scoring, caching, and client exclusion.
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

use Psr\Log\LoggerInterface;

/**
 * Orchestrator for prospect discovery.
 */
class ProspectDiscoveryService
{
    /**
     * Cache TTL in seconds (1 hour).
     *
     * @var int
     */
    private const CACHE_TTL = 3600;

    /**
     * Cache key prefix.
     *
     * @var string
     */
    private const CACHE_PREFIX = 'pipelinq_prospects_';

    /**
     * Constructor.
     *
     * @param IcpConfigService        $icpConfig The ICP config service.
     * @param KvkApiClient            $kvkClient The KVK API client.
     * @param OpenCorporatesApiClient $ocClient  The OpenCorporates client.
     * @param ProspectScoringService  $scoring   The scoring service.
     * @param SettingsService         $settings  The settings service.
     * @param LoggerInterface         $logger    The logger.
     */
    public function __construct(
        private IcpConfigService $icpConfig,
        private KvkApiClient $kvkClient,
        private OpenCorporatesApiClient $ocClient,
        private ProspectScoringService $scoring,
        private SettingsService $settings,
        private LoggerInterface $logger,
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
     */
    public function discover(bool $refresh=false): array
    {
        if ($this->icpConfig->isConfigured() === false) {
            return [
                'error'   => 'no_icp_configured',
                'message' => 'Configure your Ideal Customer Profile in admin settings first',
            ];
        }

        $icpHash  = $this->icpConfig->getIcpHash();
        $cacheKey = self::CACHE_PREFIX.$icpHash;

        // Check cache.
        if ($refresh === false && function_exists(function: 'apcu_exists') === true) {
            $cached = $this->getFromCache(key: $cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $criteria = $this->icpConfig->getCriteria();
        $apiKey   = $this->icpConfig->getKvkApiKey();

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
                    callback: fn(array $prospect): bool => ($prospect['isActive'] ?? true) === true
                )
            );
        }

        $result = [
            'prospects' => array_slice(array: $prospects, offset: 0, length: 10),
            'total'     => count($prospects),
            'displayed' => min(count($prospects), 10),
            'cachedAt'  => date(format: 'c'),
            'icpHash'   => $icpHash,
        ];

        // Store in cache.
        $this->setInCache(key: $cacheKey, data: $result);

        return $result;
    }//end discover()

    /**
     * Create a client and lead from a prospect.
     *
     * @param array $prospectData The prospect data.
     *
     * @return array The created client and lead IDs.
     */
    public function createLeadFromProspect(array $prospectData): array
    {
        $objectStore  = $this->getObjectStoreConfig();
        $clientConfig = $objectStore['client'] ?? null;
        $leadConfig   = $objectStore['lead'] ?? null;

        if ($clientConfig === null || $leadConfig === null) {
            return ['error' => 'Object store not configured for client or lead'];
        }

        // Create client.
        $clientData = [
            'name'    => $prospectData['tradeName'] ?? 'Unknown',
            'type'    => 'organization',
            'address' => $prospectData['address'] ?? '',
            'notes'   => sprintf(
                'KVK: %s | SBI: %s',
                $prospectData['kvkNumber'] ?? '',
                $prospectData['sbiDescription'] ?? ''
            ),
        ];

        // Create lead.
        $leadData = [
            'title'       => $prospectData['tradeName'] ?? 'New Lead',
            'description' => sprintf(
                'Prospect from %s discovery. %s',
                $prospectData['source'] ?? 'unknown',
                $prospectData['sbiDescription'] ?? ''
            ),
        ];

        return [
            'clientData' => $clientData,
            'leadData'   => $leadData,
        ];
    }//end createLeadFromProspect()

    /**
     * Exclude existing clients from prospect results by matching company names.
     *
     * @param array $prospects The prospects to filter.
     *
     * @return array The filtered prospects.
     */
    private function excludeExistingClients(array $prospects): array
    {
        $clientNames = $this->getExistingClientNames();

        if (count($clientNames) === 0) {
            return $prospects;
        }

        return array_values(
            array: array_filter(
                array: $prospects,
                callback: function (array $prospect) use ($clientNames): bool {
                    $tradeName = strtolower(string: trim(string: $prospect['tradeName'] ?? ''));
                    foreach ($clientNames as $clientName) {
                        if ($tradeName === $clientName) {
                            return false;
                        }

                        // Fuzzy match: check if one contains the other.
                        if ($tradeName !== '' && $clientName !== '') {
                            if (str_contains(haystack: $tradeName, needle: $clientName) === true
                                || str_contains(haystack: $clientName, needle: $tradeName) === true
                            ) {
                                return false;
                            }
                        }
                    }

                    return true;
                }
            )
        );
    }//end excludeExistingClients()

    /**
     * Get names of existing clients (lowercased).
     *
     * @return array The client names.
     */
    private function getExistingClientNames(): array
    {
        try {
            $register = $this->settings->getConfigValue(key: 'register');
            $schema   = $this->settings->getConfigValue(key: 'client_schema');

            if ($register === '' || $schema === '') {
                return [];
            }

            $url = \OC::$server->getURLGenerator()->getAbsoluteURL(
                "/apps/openregister/api/objects/{$register}/{$schema}?_limit=500"
            );

            $client   = \OC::$server->getHTTPClientService()->newClient();
            $response = $client->get(
                $url,
                [
                    'headers'   => [
                        'OCS-APIREQUEST' => 'true',
                        'requesttoken'   => \OC::$server->getCsrfTokenManager()->getToken()->getEncryptedValue(),
                    ],
                    'nextcloud' => ['allow_local_address' => true],
                ]
            );

            $data    = json_decode($response->getBody(), true);
            $results = $data['results'] ?? $data ?? [];
            $names   = [];

            foreach ($results as $item) {
                $name = trim($item['name'] ?? '');
                if ($name !== '') {
                    $names[] = strtolower($name);
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
     * Get object store configuration.
     *
     * @return array The object store config.
     */
    private function getObjectStoreConfig(): array
    {
        $config = $this->settings->getSettings();
        $result = [];

        if (($config['register'] ?? '') !== '' && ($config['client_schema'] ?? '') !== '') {
            $result['client'] = [
                'register' => $config['register'],
                'schema'   => $config['client_schema'],
            ];
        }

        if (($config['register'] ?? '') !== '' && ($config['lead_schema'] ?? '') !== '') {
            $result['lead'] = [
                'register' => $config['register'],
                'schema'   => $config['lead_schema'],
            ];
        }

        return $result;
    }//end getObjectStoreConfig()

    /**
     * Get cached results.
     *
     * @param string $key The cache key.
     *
     * @return array|null The cached data or null.
     */
    private function getFromCache(string $key): ?array
    {
        if (function_exists(function: 'apcu_fetch') === false) {
            return null;
        }

        $success = false;
        $data    = apcu_fetch(key: $key, success: $success);

        if ($success === true && is_array(value: $data) === true) {
            return $data;
        }

        return null;
    }//end getFromCache()

    /**
     * Store results in cache.
     *
     * @param string $key  The cache key.
     * @param array  $data The data to cache.
     *
     * @return void
     */
    private function setInCache(string $key, array $data): void
    {
        if (function_exists(function: 'apcu_store') === true) {
            apcu_store($key, $data, self::CACHE_TTL);
        }
    }//end setInCache()
}//end class
