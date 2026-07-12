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
        'currency',
        'client_schema',
        'contact_schema',
        'lead_schema',
        // Unified ticket supertype (unify-ticket-supertype): the separate request,
        // complaint and contactmoment schemas were retired and folded into a single
        // `ticket` schema, narrowed by the `ticketType` discriminator.
        'ticket_schema',
        'pipeline_schema',
        'product_schema',
        'productCategory_schema',
        'leadProduct_schema',
        'task_schema',
        'relationship_schema',
        'queue_schema',
        'skill_schema',
        'agentProfile_schema',
        'project_schema',
        'projectPhase_schema',
        'projectTask_schema',
        'projectActivity_schema',
        'timeEntry_schema',
        'posTransaction_schema',
        'posTransactionLine_schema',
        'receiptTemplate_schema',
        'receiptPrintLog_schema',
        'refundReason_schema',
        'posRefund_schema',
        'posRefundLine_schema',
        'cashShift_schema',
        'cashDrop_schema',
        'cashCount_schema',
        'cashDiff_schema',
        // POS staff PIN + role permissions (pos-staff-pin-permissions).
        'posRole_schema',
        'posStaff_schema',
        // POS end-of-day bookkeeping post pipeline (pos-end-of-day-bookkeeping-post).
        // The journal entry + GL chart delegate to shillinq via the ADR-019 integration
        // registry (pipelinq-bookkeeping-to-shillinq); only the operational posZReport
        // (cash-drawer / takings reconciliation) stays owned by pipelinq.
        'posZReport_schema',
        // POS split-tender (multi-method payment on a single transaction; pos-split-tender).
        'posTenderType_schema',
        'posTender_schema',
        // POS Kassakoppeling-compliant Audit Log (pos-kassakoppeling-audit).
        'kassakoppelingAuditLog_schema',
        'exportDestination_schema',
        'exportJob_schema',
        'exportRun_schema',
        'exportSchemaSnapshot_schema',
        // AVG/GDPR data-subject-request workflow removed by consume-or-dsar
        // (ADR-047 Phase 3): the avgVerzoek + satellite schemas moved to
        // OpenRegister's data-subject-request register. The MigrateAvgVerzoekenToOrDsar
        // repair step still reads any pre-existing avgVerzoek_schema config value
        // (persisted from an earlier import) directly to migrate legacy objects.
        'complaint_sla_service',
        'complaint_sla_product',
        'complaint_sla_communication',
        'complaint_sla_billing',
        'complaint_sla_other',
        // Customer portal (separate auth-domain register; ADR-005 / ADR-037).
        'portal_register',
        'portalAccount_schema',
        'portalSession_schema',
        'portalDelegation_schema',
        'portalAuditEvent_schema',
        'portalTenantConfig_schema',
        // Loyalty programme schemas (loyalty-program).
        'loyaltyProgramme_schema',
        'pointsRule_schema',
        'tierRule_schema',
        'klantLoyaltyAccount_schema',
        'pointsLedgerEntry_schema',
        'redemptionOption_schema',
        'redemption_schema',
        'giftCard_schema',
        'giftCardTransaction_schema',
        // Expense → Shillinq AP integration (pipelinq-expense-to-shillinq-ap).
        'expense_schema',
        // Billing categories (billable-categories-and-tags) — REQ-BCT-001.
        'billingCategory_schema',
        // Appointment booking schemas (appointment-booking 01..11).
        'service_schema',
        'resource_schema',
        'booking_schema',
        'walkInTicket_schema',
        'availabilityCache_schema',
        // Berichtenbox bridge (burgerportaal-mijnoverheid-bridge).
        'berichtenboxMessage_schema',
        'berichtenboxReply_schema',
        'berichtenboxTemplate_schema',
        'mailboxResolution_schema',
        'deliveryAuditLog_schema',
        // Marketing segmentation & blast (marketing-segmentation-blast).
        'blast_schema',
        // SLA engine (sla-engine-and-escalation) — separate register identifier.
        'sla_register',
        'sla_policy_schema',
        'sla_breach_event_schema',
        // Supplier commercial master (pipelinq-product-vendor-master) — keyed by
        // contactsUid; read by ProductVendorProviderService + IngestProductVendorMaster.
        'supplier_schema',
        // Contract & renewal tracking (contract-renewal-tracking).
        'contract_schema',
        // Renewal engine tuning (contract-renewal-tracking).
        'renewal_default_lead_time_days',
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
        'receipt_company_name'                     => 'Conduction B.V.',
        'receipt_company_address'                  => '',
        'receipt_company_phone'                    => '',
        'receipt_company_vat'                      => '',
        'receipt_company_kvk'                      => '',
        'receipt_email_sender'                     => '',
        'receipt_printer_host'                     => '',
        'receipt_printer_port'                     => '9100',
        'receipt_default_template'                 => '',
        'shillinq_ledger_webhook_url'              => '',
        'shillinq_wip_webhook_url'                 => '',
        // Shillinq AP webhook for expense voucher dispatch (REQ-AP-004). Empty disables the integration.
        'shillinq_ap_webhook_url'                  => '',
        // Shillinq journal-entry registry endpoint for the POS-day journal raise
        // (pipelinq-bookkeeping-to-shillinq / REQ-PBTS-001). The ADR-019 integration
        // registry resolves the shillinq.JournalEntry.raise dispatch through this
        // webhook URL; empty or non-HTTPS disables the integration. Replaces the
        // retired hard-coded pos_eod.shillinq_endpoint POST to /api/JournalEntry.
        'shillinq_journal_webhook_url'             => '',
        // Base URL of the configured shillinq deployment, used to resolve the
        // "Timesheet approval" billing entry point through the registry instead of
        // the hard-coded /index.php/apps/shillinq/ path (REQ-PBTS-003).
        'shillinq_app_url'                         => '',
        // Gates the real shillinq time-intake emit (time-billing-handoff-emit).
        // Default off: an unconfigured install keeps today's deep-link-only
        // handoff (shillinq_app_url) unchanged. The manager group allowed to
        // trigger "Send to billing" (empty = NC admins only).
        'shillinq_time_intake_enabled'             => 'false',
        'billing_handoff_manager_group'            => '',
        // Lead-management: number of inactivity days before a lead is flagged stale.
        // Default mirrors REQ-LM-002 (14 days). Tenant-tunable through admin settings.
        // spec: openspec/changes/lead-management/specs/lead-management/spec.md#REQ-LM-002.
        'lead_stale_threshold_days'                => '14',
        // BI export + data-warehouse sink — admin settings (bi-export-and-data-warehouse-sink#14.1).
        'export.retention_days'                    => '365',
        'export.default_compression'               => 'none',
        'export.failure_notification_email'        => '',
        'export.at_risk_warning_hours'             => '24',
        // POS end-of-day Z-report generation + alerting (pos-end-of-day-bookkeeping-post).
        // The journal raise itself now delegates to shillinq via shillinq_journal_webhook_url
        // (pipelinq-bookkeeping-to-shillinq); these keys only tune the operational
        // Z-report close and the failure alert.
        'pos_eod.z_report_time'                    => '23:59',
        'pos_eod.alert_email'                      => '',
        'pos_eod.max_retry_attempts'               => '5',
        // XWiki integration (xwiki-integration). The default direct URL points at
        // the dev compose stack so a fresh install renders content without manual
        // configuration; admins can override or clear it to disable the fallback.
        'xwiki_default_space'                      => '',
        'xwiki_cache_ttl'                          => '300',
        'xwiki_direct_url'                         => '',
        // SLA engine (sla-engine-and-escalation) — admin settings.
        // @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-008 .
        'sla_sweep_interval_seconds'               => '300',
        'sla_business_hours_start'                 => '09:00',
        'sla_business_hours_end'                   => '17:00',
        'sla_default_holiday_calendar'             => 'nl-feestdagen-rijksoverheid',
        'sla_tenant_holiday_overrides'             => '',
        'sla_bevrijdingsdag_yearly'                => 'false',
        'sla_actor_fallback'                       => '',
        'sla_actor_assignee'                       => '',
        'sla_actor_team-lead'                      => '',
        'sla_actor_manager'                        => '',
        'sla_actor_director'                       => '',
        'sla_resolved_statuses'                    => 'resolved,completed,closed,afgehandeld',
        // AVG (GDPR data-subject request) workflow tunables.
        'avg_dpia_threshold'                       => '10',
        'avg_evidence_retention_days'              => '30',
        'avg_download_validity_days'               => '30',
        'avg_pki_cert_path'                        => '',
        'avg_evidence_sources'                     => '',
        'avg_dpia_auto_procest'                    => 'no',
        'avg_handler_group'                        => '',
        'avg_teamlead_group'                       => '',
        'avg_dpo_group'                            => '',
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
