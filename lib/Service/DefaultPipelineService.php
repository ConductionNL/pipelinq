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
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Service for creating default pipelines.
 *
 * @spec openspec/specs/pipeline/spec.md#requirement-default-pipelines-mvp
 */
class DefaultPipelineService {
	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config.
	 * @param PipelineStageData $stageData The stage data provider.
	 * @param LoggerInterface $logger The logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private PipelineStageData $stageData,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Create default pipelines if none exist.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function createDefaultPipelines(): void {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
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
					'filters' => [
						'register' => $registerId,
						'schema' => $pipelineSchemaId,
						'title' => 'Sales Pipeline',
					],
					'limit' => 1,
				]
			);

			if (empty($existing) === false) {
				$this->logger->info('Pipelinq: Default pipelines already exist, skipping creation');
				return;
			}

			// Retrieve the default view ID if available.
			$defaultViewId = $this->appConfig->getValueString(Application::APP_ID, 'default_view', '');
			$viewId = null;
			if ($defaultViewId !== '') {
				$viewId = $defaultViewId;
			}

			$this->savePipeline(
				objectService: $objectService,
				registerId: $registerId,
				schemaId: $pipelineSchemaId,
				data: $this->stageData->getSalesPipelineData(viewId: $viewId)
			);

			$this->savePipeline(
				objectService: $objectService,
				registerId: $registerId,
				schemaId: $pipelineSchemaId,
				data: $this->stageData->getServiceRequestsPipelineData(viewId: $viewId)
			);
		} catch (\Exception $e) {
			$this->logger->error('Pipelinq: Failed to create default pipelines', ['exception' => $e->getMessage()]);
		}//end try
	}//end createDefaultPipelines()

	/**
	 * Save a pipeline object.
	 *
	 * @param object $objectService The object service.
	 * @param string $registerId The register ID.
	 * @param string $schemaId The pipeline schema ID.
	 * @param array $data The pipeline data.
	 *
	 * @return void
	 */
	private function savePipeline(object $objectService, string $registerId, string $schemaId, array $data): void {
		$objectService->saveObject(
			$data,
			[],
			$registerId,
			$schemaId,
			null
		);

		$title = $data['title'] ?? 'Unknown';
		$this->logger->info("Pipelinq: Created default {$title}");
	}//end savePipeline()

	/**
	 * Get the OpenRegister ObjectService via the container.
	 *
	 * @return object The object service.
	 */
	private function getObjectService(): object {
		return $this->objectService;
	}//end getObjectService()
}//end class
