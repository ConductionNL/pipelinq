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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-62
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
        'request',
        'complaint',
        'pipeline',
        'product',
        'productCategory',
        'leadProduct',
        'intakeForm',
        'intakeSubmission',
        'automation',
        'automationLog',
        'contactmoment',
        'task',
        'emailLink',
        'calendarLink',
        'relationship',
        'survey',
        'surveyResponse',
        'queue',
        'skill',
        'agentProfile',
        // Project ledger schemas (project-to-shillinq-ledger integration).
        'project',
        'projectPhase',
        'posTransaction',
        'posTransactionLine',
        'receiptTemplate',
        'receiptPrintLog',
        'refundReason',
        'posRefund',
        'posRefundLine',
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
    ];

    /**
     * Slug of the separate auth-domain portal register (ADR-005).
     *
     * @var string
     */
    private const PORTAL_REGISTER_SLUG = 'pipelinq-portal';

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
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-62
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

        foreach (self::SCHEMA_SLUGS as $slug) {
            if (isset($schemaMap[$slug]) === true && $schemaMap[$slug] !== null) {
                $this->appConfig->setValueString(Application::APP_ID, "{$slug}_schema", (string) $schemaMap[$slug]);
            }
        }

        $defaultViewId = $this->mapBuilder->findDefaultViewId(
            views: ($importResult['views'] ?? [])
        );

        if ($defaultViewId !== null) {
            $this->appConfig->setValueString(Application::APP_ID, 'default_view', (string) $defaultViewId);
        }
    }//end updateObjectTypeConfiguration()

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
