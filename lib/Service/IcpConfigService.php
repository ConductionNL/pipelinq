<?php

/**
 * Pipelinq IcpConfigService.
 *
 * Service for managing Ideal Customer Profile (ICP) configuration.
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

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Service for reading/writing ICP settings via IAppConfig.
 */
class IcpConfigService
{
    /**
     * ICP config keys.
     *
     * @var array<string>
     */
    private const ICP_KEYS = [
        'icp_sbi_codes',
        'icp_employee_count_min',
        'icp_employee_count_max',
        'icp_provinces',
        'icp_cities',
        'icp_legal_forms',
        'icp_exclude_inactive',
        'icp_keywords',
        'icp_kvk_api_key',
        'icp_opencorporates_enabled',
    ];

    /**
     * Mapping of data keys to JSON array config keys.
     *
     * @var array<string, string>
     */
    private const JSON_ARRAY_FIELDS = [
        'sbiCodes'  => 'icp_sbi_codes',
        'provinces' => 'icp_provinces',
        'cities'    => 'icp_cities',
        'legalForms' => 'icp_legal_forms',
        'keywords'  => 'icp_keywords',
    ];

    /**
     * Constructor.
     *
     * @param IAppConfig $appConfig The app config service.
     */
    public function __construct(
        private IAppConfig $appConfig,
    ) {
    }//end __construct()

    /**
     * Get all ICP settings.
     *
     * @return array The ICP configuration.
     */
    public function getSettings(): array
    {
        $kvkApiKey = $this->getConfigString(key: 'icp_kvk_api_key');

        return [
            'sbiCodes'              => $this->getJsonArray(key: 'icp_sbi_codes'),
            'employeeCountMin'      => (int) $this->getConfigString(key: 'icp_employee_count_min', default: '0'),
            'employeeCountMax'      => (int) $this->getConfigString(key: 'icp_employee_count_max', default: '0'),
            'provinces'             => $this->getJsonArray(key: 'icp_provinces'),
            'cities'                => $this->getJsonArray(key: 'icp_cities'),
            'legalForms'            => $this->getJsonArray(key: 'icp_legal_forms'),
            'excludeInactive'       => $this->getConfigString(key: 'icp_exclude_inactive', default: 'true') === 'true',
            'keywords'              => $this->getJsonArray(key: 'icp_keywords'),
            'kvkApiKey'             => $this->maskApiKey(apiKey: $kvkApiKey),
            'openCorporatesEnabled' => $this->getConfigString(key: 'icp_opencorporates_enabled', default: 'false') === 'true',
        ];
    }//end getSettings()

    /**
     * Save ICP settings.
     *
     * @param array $data The ICP data to save.
     *
     * @return string The ICP hash.
     */
    public function saveSettings(array $data): string
    {
        $this->saveJsonArrayFields($data);
        $this->saveIntegerFields($data);
        $this->saveBooleanFields($data);
        $this->saveApiKeyField($data);

        return $this->getIcpHash();
    }//end saveSettings()

    /**
     * Check if ICP is configured.
     *
     * @return bool True if at least one ICP criterion is set.
     */
    public function isConfigured(): bool
    {
        $sbiCodes = $this->getJsonArray(key: 'icp_sbi_codes');

        return count($sbiCodes) > 0;
    }//end isConfigured()

    /**
     * Get the raw KVK API key.
     *
     * @return string The API key.
     */
    public function getKvkApiKey(): string
    {
        return $this->getConfigString(key: 'icp_kvk_api_key');
    }//end getKvkApiKey()

    /**
     * Get ICP criteria for scoring.
     *
     * @return array The raw ICP criteria.
     */
    public function getCriteria(): array
    {
        return [
            'sbiCodes'         => $this->getJsonArray(key: 'icp_sbi_codes'),
            'employeeCountMin' => (int) $this->getConfigString(key: 'icp_employee_count_min', default: '0'),
            'employeeCountMax' => (int) $this->getConfigString(key: 'icp_employee_count_max', default: '0'),
            'provinces'        => $this->getJsonArray(key: 'icp_provinces'),
            'legalForms'       => $this->getJsonArray(key: 'icp_legal_forms'),
            'excludeInactive'  => $this->getConfigString(key: 'icp_exclude_inactive', default: 'true') === 'true',
        ];
    }//end getCriteria()

    /**
     * Get the ICP hash for cache invalidation.
     *
     * @return string The hash of current ICP settings.
     */
    public function getIcpHash(): string
    {
        $values = [];
        foreach (self::ICP_KEYS as $key) {
            $values[$key] = $this->getConfigString(key: $key);
        }

        return substr(md5(string: json_encode(value: $values)), offset: 0, length: 8);
    }//end getIcpHash()

    /**
     * Save JSON array fields from the data.
     *
     * @param array $data The ICP data.
     *
     * @return void
     */
    private function saveJsonArrayFields(array $data): void
    {
        foreach (self::JSON_ARRAY_FIELDS as $dataKey => $configKey) {
            if (isset($data[$dataKey]) === true) {
                $this->setJsonArray(key: $configKey, value: $data[$dataKey]);
            }
        }
    }//end saveJsonArrayFields()

    /**
     * Save integer fields from the data.
     *
     * @param array $data The ICP data.
     *
     * @return void
     */
    private function saveIntegerFields(array $data): void
    {
        if (isset($data['employeeCountMin']) === true) {
            $this->setConfigString(key: 'icp_employee_count_min', value: (string) (int) $data['employeeCountMin']);
        }

        if (isset($data['employeeCountMax']) === true) {
            $this->setConfigString(key: 'icp_employee_count_max', value: (string) (int) $data['employeeCountMax']);
        }
    }//end saveIntegerFields()

    /**
     * Save boolean fields from the data.
     *
     * @param array $data The ICP data.
     *
     * @return void
     */
    private function saveBooleanFields(array $data): void
    {
        if (isset($data['excludeInactive']) === true) {
            $this->setConfigString(key: 'icp_exclude_inactive', value: $this->boolToString(value: $data['excludeInactive']));
        }

        if (isset($data['openCorporatesEnabled']) === true) {
            $this->setConfigString(key: 'icp_opencorporates_enabled', value: $this->boolToString(value: $data['openCorporatesEnabled']));
        }
    }//end saveBooleanFields()

    /**
     * Save the API key field if provided and not masked.
     *
     * @param array $data The ICP data.
     *
     * @return void
     */
    private function saveApiKeyField(array $data): void
    {
        if (isset($data['kvkApiKey']) === true && $data['kvkApiKey'] !== '***configured***') {
            $this->setConfigString(key: 'icp_kvk_api_key', value: (string) $data['kvkApiKey']);
        }
    }//end saveApiKeyField()

    /**
     * Get a string value from app config.
     *
     * @param string $key     The config key.
     * @param string $default The default value.
     *
     * @return string The config value.
     */
    private function getConfigString(string $key, string $default = ''): string
    {
        return $this->appConfig->getValueString(
            app: Application::APP_ID,
            key: $key,
            default: $default
        );
    }//end getConfigString()

    /**
     * Set a string value in app config.
     *
     * @param string $key   The config key.
     * @param string $value The value to store.
     *
     * @return void
     */
    private function setConfigString(string $key, string $value): void
    {
        $this->appConfig->setValueString(
            app: Application::APP_ID,
            key: $key,
            value: $value
        );
    }//end setConfigString()

    /**
     * Get a JSON array from app config.
     *
     * @param string $key The config key.
     *
     * @return array The decoded array.
     */
    private function getJsonArray(string $key): array
    {
        $value   = $this->getConfigString(key: $key, default: '[]');
        $decoded = json_decode(json: $value, associative: true);

        if (is_array(value: $decoded) === true) {
            return $decoded;
        }

        return [];
    }//end getJsonArray()

    /**
     * Set a JSON array in app config.
     *
     * @param string $key   The config key.
     * @param mixed  $value The array to encode and store.
     *
     * @return void
     */
    private function setJsonArray(string $key, mixed $value): void
    {
        $arrayValue = [];
        if (is_array(value: $value) === true) {
            $arrayValue = $value;
        }

        $this->setConfigString(key: $key, value: json_encode(value: $arrayValue));
    }//end setJsonArray()

    /**
     * Convert a boolean to a string value.
     *
     * @param mixed $value The boolean value.
     *
     * @return string The string 'true' or 'false'.
     */
    private function boolToString(mixed $value): string
    {
        if ($value === true) {
            return 'true';
        }

        return 'false';
    }//end boolToString()

    /**
     * Mask an API key for display.
     *
     * @param string $apiKey The raw API key.
     *
     * @return string The masked key or empty string.
     */
    private function maskApiKey(string $apiKey): string
    {
        if ($apiKey !== '') {
            return '***configured***';
        }

        return '';
    }//end maskApiKey()
}//end class
