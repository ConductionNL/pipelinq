<?php

/**
 * Pipelinq DefaultPipelineService.
 *
 * Service for creating default pipelines in the Pipelinq application.
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
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for creating default pipelines.
 */
class DefaultPipelineService
{
    /**
     * Constructor.
     *
     * @param IAppConfig         $appConfig The app config.
     * @param ContainerInterface $container The container.
     * @param PipelineStageData  $stageData The stage data provider.
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private IAppConfig $appConfig,
        private ContainerInterface $container,
        private PipelineStageData $stageData,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create default pipelines if none exist.
     *
     * @return void
     */
    public function createDefaultPipelines(): void
    {
        $registerId       = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $pipelineSchemaId = $this->appConfig->getValueString(Application::APP_ID, 'pipeline_schema', '');

        if ($registerId === '' || $pipelineSchemaId === '') {
            $this->logger->warning(
                'Pipelinq: Cannot create default pipelines -- register or pipeline schema not configured'
            );
            return;
        }

        try {
            $objectService = $this->getObjectService();

            $existing = $objectService->findAll(
                    [
                        'filters' => ['register' => $registerId, 'schema' => $pipelineSchemaId],
                        'limit'   => 1,
                    ],
                    _rbac: false,
                    _multitenancy: false
                    );

            if (empty($existing) === false) {
                $this->logger->info('Pipelinq: Default pipelines already exist, skipping creation');
                return;
            }

            $this->savePipeline(
                objectService: $objectService,
                registerId: $registerId,
                schemaId: $pipelineSchemaId,
                data: $this->stageData->getSalesPipelineData()
            );

            $this->savePipeline(
                objectService: $objectService,
                registerId: $registerId,
                schemaId: $pipelineSchemaId,
                data: $this->stageData->getServiceRequestsPipelineData()
            );
        } catch (\Exception $e) {
            $this->logger->error('Pipelinq: Failed to create default pipelines', ['exception' => $e->getMessage()]);
        }//end try
    }//end createDefaultPipelines()

    /**
     * Save a pipeline object.
     *
     * @param object $objectService The object service.
     * @param string $registerId    The register ID.
     * @param string $schemaId      The pipeline schema ID.
     * @param array  $data          The pipeline data.
     *
     * @return void
     */
    private function savePipeline(object $objectService, string $registerId, string $schemaId, array $data): void
    {
        $objectService->saveObject(
            $data,
            [],
            $registerId,
            $schemaId,
            null,
            _rbac: false,
            _multitenancy: false
        );

        $title = $data['title'] ?? 'Unknown';
        $this->logger->info("Pipelinq: Created default {$title}");
    }//end savePipeline()

    /**
     * Get the OpenRegister ObjectService via the container.
     *
     * @return object The object service.
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');
    }//end getObjectService()
}//end class
