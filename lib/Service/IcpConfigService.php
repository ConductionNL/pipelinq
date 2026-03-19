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

/**
 * Service for reading/writing ICP settings via IAppConfig.
 */
class IcpConfigService
{
    /**
     * ICP config keys for hash calculation.
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
        'sbiCodes'   => 'icp_sbi_codes',
        'provinces'  => 'icp_provinces',
        'cities'     => 'icp_cities',
        'legalForms' => 'icp_legal_forms',
        'keywords'   => 'icp_keywords',
    ];

    /**
     * Constructor.
     *
     * @param IcpConfigReader $reader The config reader.
     */
    public function __construct(
        private IcpConfigReader $reader,
    ) {
    }//end __construct()

    /**
     * Get all ICP settings.
     *
     * @return array The ICP configuration.
     */
    public function getSettings(): array
    {
        $kvkApiKey = $this->reader->getString(key: 'icp_kvk_api_key');

        return [
            'sbiCodes'              => $this->reader->getJsonArray(key: 'icp_sbi_codes'),
            'employeeCountMin'      => $this->reader->getInt(key: 'icp_employee_count_min'),
            'employeeCountMax'      => $this->reader->getInt(key: 'icp_employee_count_max'),
            'provinces'             => $this->reader->getJsonArray(key: 'icp_provinces'),
            'cities'                => $this->reader->getJsonArray(key: 'icp_cities'),
            'legalForms'            => $this->reader->getJsonArray(key: 'icp_legal_forms'),
            'excludeInactive'       => $this->reader->isBoolTrue(key: 'icp_exclude_inactive'),
            'keywords'              => $this->reader->getJsonArray(key: 'icp_keywords'),
            'kvkApiKey'             => $this->maskApiKey(apiKey: $kvkApiKey),
            'openCorporatesEnabled' => $this->reader->isBoolTrue(
                key: 'icp_opencorporates_enabled',
                default: 'false'
            ),
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
        $this->saveJsonArrayFields(data: $data);
        $this->saveIntegerFields(data: $data);
        $this->saveBooleanFields(data: $data);
        $this->saveApiKeyField(data: $data);

        return $this->getIcpHash();
    }//end saveSettings()

    /**
     * Check if ICP is configured.
     *
     * @return bool True if at least one ICP criterion is set.
     */
    public function isConfigured(): bool
    {
        $sbiCodes = $this->reader->getJsonArray(key: 'icp_sbi_codes');

        return count($sbiCodes) > 0;
    }//end isConfigured()

    /**
     * Get the raw KVK API key.
     *
     * @return string The API key.
     */
    public function getKvkApiKey(): string
    {
        return $this->reader->getString(key: 'icp_kvk_api_key');
    }//end getKvkApiKey()

    /**
     * Get ICP criteria for scoring.
     *
     * @return array The raw ICP criteria.
     */
    public function getCriteria(): array
    {
        return [
            'sbiCodes'         => $this->reader->getJsonArray(key: 'icp_sbi_codes'),
            'employeeCountMin' => $this->reader->getInt(key: 'icp_employee_count_min'),
            'employeeCountMax' => $this->reader->getInt(key: 'icp_employee_count_max'),
            'provinces'        => $this->reader->getJsonArray(key: 'icp_provinces'),
            'legalForms'       => $this->reader->getJsonArray(key: 'icp_legal_forms'),
            'excludeInactive'  => $this->reader->isBoolTrue(key: 'icp_exclude_inactive'),
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
            $values[$key] = $this->reader->getString(key: $key);
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
                $this->reader->setJsonArray(key: $configKey, value: $data[$dataKey]);
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
            $this->reader->setInt(key: 'icp_employee_count_min', value: $data['employeeCountMin']);
        }

        if (isset($data['employeeCountMax']) === true) {
            $this->reader->setInt(key: 'icp_employee_count_max', value: $data['employeeCountMax']);
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
            $this->reader->setBool(key: 'icp_exclude_inactive', value: $data['excludeInactive']);
        }

        if (isset($data['openCorporatesEnabled']) === true) {
            $this->reader->setBool(key: 'icp_opencorporates_enabled', value: $data['openCorporatesEnabled']);
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
            $this->reader->setString(key: 'icp_kvk_api_key', value: (string) $data['kvkApiKey']);
        }
    }//end saveApiKeyField()

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
