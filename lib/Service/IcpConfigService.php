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
        $kvkApiKey = $this->appConfig->getValueString(
            app: Application::APP_ID,
            key: 'icp_kvk_api_key',
            default: ''
        );

        return [
            'sbiCodes'              => $this->getJsonArray(key: 'icp_sbi_codes'),
            'employeeCountMin'      => (int) $this->appConfig->getValueString(
                app: Application::APP_ID,
                key: 'icp_employee_count_min',
                default: '0'
            ),
            'employeeCountMax'      => (int) $this->appConfig->getValueString(
                app: Application::APP_ID,
                key: 'icp_employee_count_max',
                default: '0'
            ),
            'provinces'             => $this->getJsonArray(key: 'icp_provinces'),
            'cities'                => $this->getJsonArray(key: 'icp_cities'),
            'legalForms'            => $this->getJsonArray(key: 'icp_legal_forms'),
            'excludeInactive'       => $this->appConfig->getValueString(
                app: Application::APP_ID,
                key: 'icp_exclude_inactive',
                default: 'true'
            ) === 'true',
            'keywords'              => $this->getJsonArray(key: 'icp_keywords'),
            'kvkApiKey'             => $this->maskApiKey(apiKey: $kvkApiKey),
            'openCorporatesEnabled' => $this->appConfig->getValueString(
                app: Application::APP_ID,
                key: 'icp_opencorporates_enabled',
                default: 'false'
            ) === 'true',
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
        if (isset($data['sbiCodes']) === true) {
            $this->setJsonArray(key: 'icp_sbi_codes', value: $data['sbiCodes']);
        }

        if (isset($data['employeeCountMin']) === true) {
            $this->appConfig->setValueString(
                app: Application::APP_ID,
                key: 'icp_employee_count_min',
                value: (string) (int) $data['employeeCountMin']
            );
        }

        if (isset($data['employeeCountMax']) === true) {
            $this->appConfig->setValueString(
                app: Application::APP_ID,
                key: 'icp_employee_count_max',
                value: (string) (int) $data['employeeCountMax']
            );
        }

        if (isset($data['provinces']) === true) {
            $this->setJsonArray(key: 'icp_provinces', value: $data['provinces']);
        }

        if (isset($data['cities']) === true) {
            $this->setJsonArray(key: 'icp_cities', value: $data['cities']);
        }

        if (isset($data['legalForms']) === true) {
            $this->setJsonArray(key: 'icp_legal_forms', value: $data['legalForms']);
        }

        if (isset($data['excludeInactive']) === true) {
            $this->appConfig->setValueString(
                app: Application::APP_ID,
                key: 'icp_exclude_inactive',
                value: $this->boolToString(value: $data['excludeInactive'])
            );
        }

        if (isset($data['keywords']) === true) {
            $this->setJsonArray(key: 'icp_keywords', value: $data['keywords']);
        }

        if (isset($data['kvkApiKey']) === true && $data['kvkApiKey'] !== '***configured***') {
            $this->appConfig->setValueString(
                app: Application::APP_ID,
                key: 'icp_kvk_api_key',
                value: (string) $data['kvkApiKey']
            );
        }

        if (isset($data['openCorporatesEnabled']) === true) {
            $this->appConfig->setValueString(
                app: Application::APP_ID,
                key: 'icp_opencorporates_enabled',
                value: $this->boolToString(value: $data['openCorporatesEnabled'])
            );
        }

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
        return $this->appConfig->getValueString(
            app: Application::APP_ID,
            key: 'icp_kvk_api_key',
            default: ''
        );
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
            'employeeCountMin' => (int) $this->appConfig->getValueString(
                app: Application::APP_ID,
                key: 'icp_employee_count_min',
                default: '0'
            ),
            'employeeCountMax' => (int) $this->appConfig->getValueString(
                app: Application::APP_ID,
                key: 'icp_employee_count_max',
                default: '0'
            ),
            'provinces'        => $this->getJsonArray(key: 'icp_provinces'),
            'legalForms'       => $this->getJsonArray(key: 'icp_legal_forms'),
            'excludeInactive'  => $this->appConfig->getValueString(
                app: Application::APP_ID,
                key: 'icp_exclude_inactive',
                default: 'true'
            ) === 'true',
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
            $values[$key] = $this->appConfig->getValueString(
                app: Application::APP_ID,
                key: $key,
                default: ''
            );
        }

        return substr(md5(string: json_encode(value: $values)), offset: 0, length: 8);
    }//end getIcpHash()

    /**
     * Get a JSON array from app config.
     *
     * @param string $key The config key.
     *
     * @return array The decoded array.
     */
    private function getJsonArray(string $key): array
    {
        $value = $this->appConfig->getValueString(
            app: Application::APP_ID,
            key: $key,
            default: '[]'
        );

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

        $this->appConfig->setValueString(
            app: Application::APP_ID,
            key: $key,
            value: json_encode(value: $arrayValue)
        );
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
