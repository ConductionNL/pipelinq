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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;

/**
 * Service for loading and importing Pipelinq configuration.
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
        'pipeline',
    ];

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

        foreach (self::SCHEMA_SLUGS as $slug) {
            if (isset($schemaMap[$slug]) === true && $schemaMap[$slug] !== null) {
                $this->appConfig->setValueString(Application::APP_ID, "{$slug}_schema", (string) $schemaMap[$slug]);
            }
        }
    }//end updateObjectTypeConfiguration()

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
