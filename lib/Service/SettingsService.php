<?php

/**
 * Pipelinq SettingsService.
 *
 * Service for managing Pipelinq application settings and configuration.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Service for managing Pipelinq settings.
 */
class SettingsService
{
    private const CONFIG_KEYS = [
        'register',
        'client_schema',
        'contact_schema',
        'lead_schema',
        'request_schema',
        'pipeline_schema',
    ];

    /**
     * User setting keys and their defaults.
     *
     * @var array<string, string>
     */
    private const USER_SETTING_DEFAULTS = [
        'notify_assignments'  => 'true',
        'notify_stage_status' => 'true',
        'notify_notes'        => 'true',
    ];

    /**
     * Constructor.
     *
     * @param IAppConfig             $appConfig           The app config.
     * @param IConfig                $config              The user config service.
     * @param SettingsLoadService    $settingsLoadService The settings load service.
     * @param DefaultPipelineService $pipelineService     The default pipeline service.
     * @param LoggerInterface        $logger              The logger.
     */
    public function __construct(
        private IAppConfig $appConfig,
        private IConfig $config,
        private SettingsLoadService $settingsLoadService,
        private DefaultPipelineService $pipelineService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get all Pipelinq settings.
     *
     * @return array The settings as key-value pairs.
     */
    public function getSettings(): array
    {
        $config = [];
        foreach (self::CONFIG_KEYS as $key) {
            $config[$key] = $this->appConfig->getValueString(Application::APP_ID, $key, '');
        }

        return $config;
    }//end getSettings()

    /**
     * Update Pipelinq settings with the given data.
     *
     * @param array $data The settings data to update.
     *
     * @return array The updated settings.
     */
    public function updateSettings(array $data): array
    {
        foreach (self::CONFIG_KEYS as $key) {
            if (isset($data[$key]) === true) {
                $this->appConfig->setValueString(Application::APP_ID, $key, (string) $data[$key]);
            }
        }

        $this->logger->info('Pipelinq settings updated', ['keys' => array_keys($data)]);

        return $this->getSettings();
    }//end updateSettings()

    /**
     * Load settings by importing the register JSON via ConfigurationService.
     * Delegates to SettingsLoadService.
     *
     * @param bool $force Whether to force re-import.
     *
     * @return array The import result.
     */
    public function loadSettings(bool $force=false): array
    {
        return $this->settingsLoadService->loadSettings(force: $force);
    }//end loadSettings()

    /**
     * Create default pipelines if none exist.
     * Delegates to DefaultPipelineService.
     *
     * @return void
     */
    public function createDefaultPipelines(): void
    {
        $this->pipelineService->createDefaultPipelines();
    }//end createDefaultPipelines()

    /**
     * Get user settings for the given user.
     *
     * @param string $userId The user ID.
     *
     * @return array The user settings as key-boolean pairs.
     */
    public function getUserSettings(string $userId): array
    {
        $settings = [];
        foreach (self::USER_SETTING_DEFAULTS as $key => $default) {
            $settings[$key] = $this->config->getUserValue(
                userId: $userId,
                appName: Application::APP_ID,
                key: $key,
                default: $default
            ) === 'true';
        }

        return $settings;
    }//end getUserSettings()

    /**
     * Update user settings for the given user.
     *
     * @param string $userId The user ID.
     * @param array  $data   The settings data to update.
     *
     * @return array The updated user settings.
     */
    public function updateUserSettings(string $userId, array $data): array
    {
        foreach (self::USER_SETTING_DEFAULTS as $key => $default) {
            if (array_key_exists(key: $key, array: $data) === true) {
                $this->config->setUserValue(
                    userId: $userId,
                    appName: Application::APP_ID,
                    key: $key,
                    value: $data[$key] ? 'true' : 'false'
                );
            }
        }

        return $this->getUserSettings(userId: $userId);
    }//end updateUserSettings()

    /**
     * Get a config value by key.
     *
     * @param string $key     The config key.
     * @param string $default The default value.
     *
     * @return string The config value.
     */
    public function getConfigValue(string $key, string $default=''): string
    {
        return $this->appConfig->getValueString(Application::APP_ID, $key, $default);
    }//end getConfigValue()

    /**
     * Set a config value by key.
     *
     * @param string $key   The config key.
     * @param string $value The config value.
     *
     * @return void
     */
    public function setConfigValue(string $key, string $value): void
    {
        $this->appConfig->setValueString(Application::APP_ID, $key, $value);
    }//end setConfigValue()
}//end class
