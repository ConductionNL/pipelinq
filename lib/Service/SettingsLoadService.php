<?php

/**
 * Pipelinq SettingsLoadService.
 *
 * Service for loading and importing Pipelinq configuration from JSON into OpenRegister.
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
 * @spec openspec/specs/openregister-integration/spec.md#requirement-register-configuration-file
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;

/**
 * Service for loading and importing Pipelinq configuration.
 *
 * @spec openspec/changes/migrate-kennisbank-to-xwiki-leaf/tasks.md#task-1.2
 */
class SettingsLoadService
{
    /**
     * Schema slugs to map.
     *
     * @var string[]
     */
    private const SCHEMA_SLUGS = [
        'client',
        'contact',
        'lead',
        // Unified inbound-matter supertype (unify-ticket-supertype). It replaced
        // the retired `request`, `complaint` and `contactmoment` schemas; a
        // subtype is selected with the `ticketType` discriminator.
        'ticket',
        'pipeline',
        'product',
        'productCategory',
        'billingCategory',
        'leadProduct',
        'task',
        'relationship',
        'queue',
        'skill',
        'agentProfile',
        // Project ledger schemas (project-to-shillinq-ledger integration).
        'project',
        'projectPhase',
        // Project / WBS hierarchy (project-task-hierarchy).
        'projectTask',
        'projectActivity',
        // Time & WIP tracking (time-wip).
        'timeEntry',
        'posTransaction',
        'posTransactionLine',
        // POS split-tender schemas (pos-split-tender). Without these two slugs
        // the `posTenderType_schema` app-config key is never populated on import,
        // and PosTenderService::config() throws OCSNotFoundException -> the
        // GET /pos/tender-types endpoint 500s. Mapping them here provisions the
        // config on every (re-)import so the endpoint returns a 200 list.
        'posTenderType',
        'posTender',
        'receiptTemplate',
        'receiptPrintLog',
        'refundReason',
        'posRefund',
        'posRefundLine',
        // POS cash-drawer (pos-cash-management).
        'cashShift',
        'cashDrop',
        'cashCount',
        'cashDiff',
        // POS staff/roles (pos-end-of-day-bookkeeping) and end-of-day Z-reports.
        'posRole',
        'posStaff',
        'posZReport',
        // POS kassakoppeling audit trail (pos-kassakoppeling-audit).
        'kassakoppelingAuditLog',
        // Customer portal schemas (live in the separate pipelinq-portal register).
        'portalAccount',
        'portalSession',
        'portalDelegation',
        'portalAuditEvent',
        'portalTenantConfig',
        // BI export + data-warehouse sink schemas.
        'exportDestination',
        'exportJob',
        'exportRun',
        'exportSchemaSnapshot',
        // Loyalty programme schemas (loyalty-program).
        'loyaltyProgramme',
        'pointsRule',
        'tierRule',
        'klantLoyaltyAccount',
        'pointsLedgerEntry',
        'redemptionOption',
        'redemption',
        'giftCard',
        'giftCardTransaction',
        // CTI screen-pop / click-to-dial adapter schemas (cti-screenpop-adapter).
        'ctiAdapterConfig',
        'ctiEventLog',
        'ctiAgentPresence',
        // SLA engine (sla-engine-and-escalation) — separate sla register.
        'slaPolicy',
        'slaBreachEvent',
        // AVG/GDPR data-subject-request workflow removed by consume-or-dsar
        // (ADR-047 Phase 3): the avgVerzoek + satellite schemas moved to
        // OpenRegister's data-subject-request register.
        // Master-data-management golden-record governance (master-data-management).
        // syncQueueItem removed by retire-mdm-sync-queue — downstream delivery is
        // OpenRegister's WebhookService, no app-side queue schema.
        'masterEntity',
        'sourceRecord',
        'trustConfiguration',
        'mergeOperation',
        // Supplier commercial master (pipelinq-product-vendor-master). Without
        // this slug the `supplier_schema` app-config key is never populated on
        // import, so ProductVendorProviderService::resolveSupplier() and the
        // IngestProductVendorMaster repair step cannot locate supplier objects.
        'supplier',
        // Contract & renewal tracking (contract-renewal-tracking) — recurring-revenue
        // contracts with renewal-window detection and churn metrics.
        'contract',
        // Appointment booking (appointment-booking).
        'service',
        'resource',
        'booking',
        'walkInTicket',
        'availabilityCache',
        // Expense-to-Shillinq accounts-payable sync (expense-shillinq-ap).
        'expense',
        // Marketing segmentation & blast (marketing-segmentation-blast).
        'blast',
        // Berichtenbox messaging channel (berichtenbox).
        'berichtenboxMessage',
        'berichtenboxReply',
        'berichtenboxTemplate',
        'mailboxResolution',
        'deliveryAuditLog',
    ];

    /**
     * Slug of the separate auth-domain portal register (ADR-005).
     *
     * @var string
     */
    private const PORTAL_REGISTER_SLUG = 'pipelinq-portal';

    /**
     * Slug of the cross-cutting SLA engine register.
     *
     * @var string
     */
    private const SLA_REGISTER_SLUG = 'sla';

    /**
     * Constructor.
     *
     * @param IAppConfig              $appConfig  The app config.
     * @param IAppManager             $appManager The app manager.
     * @param ContainerInterface      $container  The container.
     * @param SettingsMapBuilder      $mapBuilder The map builder.
     * @param ConfigFileLoaderService $fileLoader The file loader.
     */
    public function __construct(
        private IAppConfig $appConfig,
        private IAppManager $appManager,
        private ContainerInterface $container,
        private SettingsMapBuilder $mapBuilder,
        private ConfigFileLoaderService $fileLoader,
    ) {
    }//end __construct()

    /**
     * Load settings by importing the register JSON via ConfigurationService.
     *
     * @param bool $force Whether to force re-import.
     *
     * @return array The import result.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) — $force is a simple re-import toggle
     *
     * @spec openspec/specs/openregister-integration/spec.md#requirement-register-configuration-file
     */
    public function loadSettings(bool $force=false): array
    {
        $data = $this->fileLoader->loadConfigurationFile();
        $data = $this->fileLoader->ensureSourceType(data: $data);

        $configurationService = $this->getConfigurationService();
        $currentAppVersion    = $this->appManager->getAppVersion(Application::APP_ID);

        $result = $configurationService->importFromApp(
            appId: Application::APP_ID,
            data: $data,
            version: $currentAppVersion,
            force: $force
        );

        $this->updateObjectTypeConfiguration(importResult: $result);

        return $result;
    }//end loadSettings()

    /**
     * Update IAppConfig with imported register and schema IDs.
     *
     * @param array $importResult The import result from ConfigurationService.
     *
     * @return void
     */
    private function updateObjectTypeConfiguration(array $importResult): void
    {
        $schemaMap = $this->mapBuilder->buildSchemaSlugMap(
            schemas: ($importResult['schemas'] ?? [])
        );

        $this->applyRegisterConfig(importResult: $importResult);
        $this->applySchemaConfig(schemaMap: $schemaMap);

        $defaultViewId = $this->mapBuilder->findDefaultViewId(
            views: ($importResult['views'] ?? [])
        );

        if ($defaultViewId !== null) {
            $this->appConfig->setValueString(Application::APP_ID, 'default_view', (string) $defaultViewId);
        }
    }//end updateObjectTypeConfiguration()

    /**
     * Store the imported main/portal/SLA register ids in app config.
     *
     * @param array $importResult The import result from ConfigurationService.
     *
     * @return void
     */
    private function applyRegisterConfig(array $importResult): void
    {
        $registerId = $this->mapBuilder->findRegisterIdBySlug(
            registers: ($importResult['registers'] ?? [])
        );

        if ($registerId !== null) {
            $this->appConfig->setValueString(Application::APP_ID, 'register', (string) $registerId);
        }

        $portalRegisterId = $this->resolveRegisterIdBySlug(
            registers: ($importResult['registers'] ?? []),
            slug: self::PORTAL_REGISTER_SLUG
        );
        if ($portalRegisterId !== null) {
            $this->appConfig->setValueString(Application::APP_ID, 'portal_register', (string) $portalRegisterId);
        }

        $slaRegisterId = $this->resolveRegisterIdBySlug(
            registers: ($importResult['registers'] ?? []),
            slug: self::SLA_REGISTER_SLUG
        );
        if ($slaRegisterId !== null) {
            $this->appConfig->setValueString(Application::APP_ID, 'sla_register', (string) $slaRegisterId);
        }
    }//end applyRegisterConfig()

    /**
     * Store the imported schema ids (including the SLA schema-key overrides
     * and the generic `<slug>_schema` set) in app config.
     *
     * @param array $schemaMap The slug => schema id map.
     *
     * @return void
     */
    private function applySchemaConfig(array $schemaMap): void
    {
        // SLA schema config keys diverge from the auto-derived `<slug>_schema`
        // naming because the engine expects `sla_policy_schema` and
        // `sla_breach_event_schema` rather than `slaPolicy_schema`.
        if (isset($schemaMap['slaPolicy']) === true && $schemaMap['slaPolicy'] !== null) {
            $this->appConfig->setValueString(Application::APP_ID, 'sla_policy_schema', (string) $schemaMap['slaPolicy']);
        }

        if (isset($schemaMap['slaBreachEvent']) === true && $schemaMap['slaBreachEvent'] !== null) {
            $this->appConfig->setValueString(Application::APP_ID, 'sla_breach_event_schema', (string) $schemaMap['slaBreachEvent']);
        }

        foreach (self::SCHEMA_SLUGS as $slug) {
            if (isset($schemaMap[$slug]) === true && $schemaMap[$slug] !== null) {
                $this->appConfig->setValueString(Application::APP_ID, "{$slug}_schema", (string) $schemaMap[$slug]);
            }
        }
    }//end applySchemaConfig()

    /**
     * Resolve an imported register id by its slug.
     *
     * Mirrors {@see SettingsMapBuilder::findRegisterIdBySlug()} but for an
     * arbitrary slug, so the separate portal register id can be stored under
     * its own app-config key without coupling the map builder to the portal.
     *
     * @param array  $registers The imported registers (array or entity shape).
     * @param string $slug      The register slug to match.
     *
     * @return string|null The register id, or null when not present.
     */
    private function resolveRegisterIdBySlug(array $registers, string $slug): ?string
    {
        foreach ($registers as $register) {
            $registerArray = $register;
            if (is_object($register) === true && method_exists($register, 'jsonSerialize') === true) {
                $serialized = $register->jsonSerialize();
                if (is_array($serialized) === true) {
                    $registerArray = $serialized;
                }
            }

            if (is_array($registerArray) === false) {
                continue;
            }

            if (($registerArray['slug'] ?? null) !== $slug) {
                continue;
            }

            $id = ($registerArray['id'] ?? $registerArray['uuid'] ?? null);
            if ($id !== null) {
                return (string) $id;
            }
        }//end foreach

        return null;
    }//end resolveRegisterIdBySlug()

    /**
     * Get the OpenRegister ConfigurationService via the container.
     *
     * @return object The configuration service.
     */
    private function getConfigurationService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ConfigurationService');
    }//end getConfigurationService()
}//end class
