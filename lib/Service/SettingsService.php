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
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-3
 * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-4
 * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-5
 * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-6
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Service for managing Pipelinq settings.
 *
 * @spec openspec/changes/migrate-kennisbank-to-xwiki-leaf/tasks.md#task-1.2
 */
class SettingsService
{
    private const CONFIG_KEYS = [
        'register',
        'client_schema',
        'contact_schema',
        'lead_schema',
        'request_schema',
        'complaint_schema',
        'pipeline_schema',
        'product_schema',
        'productCategory_schema',
        'leadProduct_schema',
        'intakeForm_schema',
        'intakeSubmission_schema',
        'automation_schema',
        'automationLog_schema',
        'contactmoment_schema',
        'task_schema',
        'emailLink_schema',
        'calendarLink_schema',
        'relationship_schema',
        'survey_schema',
        'surveyResponse_schema',
        'queue_schema',
        'skill_schema',
        'agentProfile_schema',
        'posTransaction_schema',
        'posTransactionLine_schema',
        'receiptTemplate_schema',
        'receiptPrintLog_schema',
        'complaint_sla_service',
        'complaint_sla_product',
        'complaint_sla_communication',
        'complaint_sla_billing',
        'complaint_sla_other',
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
     * Tenant-tunable admin-config keys migrated from hardcoded constants
     * (Phase 7). Each value is the historical constant default, so an
     * unconfigured install preserves prior behavior exactly.
     *
     * These keys are surfaced through getSettings()/updateSettings() so the
     * existing admin-gated SettingsController write path persists them.
     *
     * @var array<string, string>
     */
    public const TUNABLE_DEFAULTS = [
        'queue_overflow.poll_interval_seconds'     => '300',
        'task_expiry.poll_interval_seconds'        => '900',
        'task_expiry.escalation_threshold_seconds' => '14400',
        'task_expiry.in_progress_grace_seconds'    => '86400',
        'task_escalation.threshold_hours'          => '4',
        'task.business_hour_start'                 => '8',
        'task.business_hour_end'                   => '17',
        'prospect_discovery.cache_ttl_seconds'     => '3600',
        'kvk.api_base_url'                         => 'https://api.kvk.nl/api/v1',
        'opencorporates.api_base_url'              => 'https://api.opencorporates.com/v0.4',
    ];

    /**
     * Constructor.
     *
     * @param IAppConfig             $appConfig           The app config.
     * @param IConfig                $config              The user config service.
     * @param SettingsLoadService    $settingsLoadService The settings load service.
     * @param DefaultPipelineService $pipelineService     The default pipeline service.
     * @param DefaultQueueService    $queueService        The default queue service.
     * @param LoggerInterface        $logger              The logger.
     */
    public function __construct(
        private IAppConfig $appConfig,
        private IConfig $config,
        private SettingsLoadService $settingsLoadService,
        private DefaultPipelineService $pipelineService,
        private DefaultQueueService $queueService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get all Pipelinq settings.
     *
     * @return array The settings as key-value pairs.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-3
     */
    public function getSettings(): array
    {
        $config = [];
        foreach (self::CONFIG_KEYS as $key) {
            $config[$key] = $this->appConfig->getValueString(Application::APP_ID, $key, '');
        }

        foreach (self::TUNABLE_DEFAULTS as $key => $default) {
            $config[$key] = $this->appConfig->getValueString(Application::APP_ID, $key, $default);
        }

        return $config;
    }//end getSettings()

    /**
     * Update Pipelinq settings with the given data.
     *
     * @param array $data The settings data to update.
     *
     * @return array The updated settings.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-3
     */
    public function updateSettings(array $data): array
    {
        foreach (self::CONFIG_KEYS as $key) {
            if (isset($data[$key]) === true) {
                $this->appConfig->setValueString(Application::APP_ID, $key, (string) $data[$key]);
            }
        }

        foreach (array_keys(array: self::TUNABLE_DEFAULTS) as $key) {
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
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) — $force is a simple re-import toggle
     * @spec                                        openspec/changes/reverse-2026-05-26-be-settings/tasks.md#task-16
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
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-4
     */
    public function createDefaultPipelines(): void
    {
        $this->pipelineService->createDefaultPipelines();
    }//end createDefaultPipelines()

    /**
     * Create default queues if none exist.
     * Delegates to DefaultQueueService.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-5
     */
    public function createDefaultQueues(): void
    {
        $this->queueService->createDefaultQueues();
    }//end createDefaultQueues()

    /**
     * Create default skills if none exist.
     * Delegates to DefaultQueueService.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-6
     */
    public function createDefaultSkills(): void
    {
        $this->queueService->createDefaultSkills();
    }//end createDefaultSkills()

    /**
     * Get user settings for the given user.
     *
     * @param string $userId The user ID.
     *
     * @return array The user settings as key-boolean pairs.
     * @spec   openspec/changes/reverse-2026-05-26-be-settings/tasks.md#task-15
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
     * @spec   openspec/changes/reverse-2026-05-26-be-settings/tasks.md#task-17
     */
    public function updateUserSettings(string $userId, array $data): array
    {
        foreach (array_keys(array: self::USER_SETTING_DEFAULTS) as $key) {
            if (array_key_exists(key: $key, array: $data) === true) {
                $value = 'false';
                if ($data[$key] === true) {
                    $value = 'true';
                }

                $this->config->setUserValue(
                    userId: $userId,
                    appName: Application::APP_ID,
                    key: $key,
                    value: $value
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
     *
     * @spec openspec/changes/archive/retrofit-2026-05-24-admin-settings/tasks.md#task-1
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
     *
     * @spec openspec/changes/archive/retrofit-2026-05-24-admin-settings/tasks.md#task-1
     */
    public function setConfigValue(string $key, string $value): void
    {
        $this->appConfig->setValueString(Application::APP_ID, $key, $value);
    }//end setConfigValue()

    /**
     * Get an integer admin-config value by key.
     *
     * Used by background jobs and services for the tenant-tunable timing and
     * threshold values migrated from hardcoded constants (Phase 7). The
     * caller supplies the historical constant as the default so an
     * unconfigured install preserves prior behavior.
     *
     * @param string $key     The config key.
     * @param int    $default The default value (the historical constant).
     *
     * @return int The configured value, or the default if unset.
     *
     * @spec openspec/changes/pipelinq-admin-config-magic-numbers/specs/pipelinq-or-adoption/spec.md
     */
    public function getIntValue(string $key, int $default): int
    {
        return $this->appConfig->getValueInt(Application::APP_ID, $key, $default);
    }//end getIntValue()

    /**
     * Get a string admin-config value by key (typed wrapper).
     *
     * Used for the tenant-tunable third-party API base URLs migrated from
     * hardcoded constants (Phase 7). The caller supplies the known host as
     * the default. The key is admin-only (written via the admin-gated
     * SettingsController), so no untrusted input reaches the URL.
     *
     * @param string $key     The config key.
     * @param string $default The default value (the historical constant).
     *
     * @return string The configured value, or the default if unset.
     *
     * @spec openspec/changes/pipelinq-admin-config-magic-numbers/specs/pipelinq-or-adoption/spec.md
     */
    public function getStringValue(string $key, string $default): string
    {
        return $this->appConfig->getValueString(Application::APP_ID, $key, $default);
    }//end getStringValue()
}//end class
