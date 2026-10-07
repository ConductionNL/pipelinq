<?php

   
                                   
  
                                                                      
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                              
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                          
  
                                                                          
   
class DefaultPipelineService {
	   
                
   
                                                
                                                                
                                              
                                                                                         
                                                                                                      
    
	public function __construct(
		private IAppConfig $appConfig,
		private PipelineStageData $stageData,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly SystemServiceAccount $systemAccount,
	) {
	}                   

	   
                                           
   
                                                                          
                                                                       
                                                                          
   
                
   
                                               
                                                                 
    
	public function createDefaultPipelines(): void {
		try {
			$this->systemAccount->runAsCurrentOrSystem(fn () => $this->createDefaultPipelinesAsCaller());
		} catch (\Exception $e) {
			$this->logger->error('Pipelinq: Failed to create default pipelines', ['exception' => $e->getMessage()]);
		}
	}                              

	   
                                                               
   
                
   
                                                                 
    
	private function createDefaultPipelinesAsCaller(): void {
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

			                                             
			$defaultViewId = $this->appConfig->getValueString(Application::APP_ID, 'default_view', '');
			$viewId = null;
			if ($defaultViewId !== '') {
				$viewId = $defaultViewId;
			}

			$this->savePipeline(
				objectService: $objectService,
				registerId: $registerId,
				schemaId: $pipelineSchemaId,
				data: $this->stageData->getSalesPipelineData(
					viewId: $viewId,
					currency: $this->appConfig->getValueString(Application::APP_ID, 'currency', 'EUR')
				)
			);

			$this->savePipeline(
				objectService: $objectService,
				registerId: $registerId,
				schemaId: $pipelineSchemaId,
				data: $this->stageData->getServiceRequestsPipelineData(viewId: $viewId)
			);
		} catch (\Exception $e) {
			$this->logger->error('Pipelinq: Failed to create default pipelines', ['exception' => $e->getMessage()]);
		}         
	}                                      

	   
                           
   
                                                    
                                              
                                                   
                                         
   
                
    
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
	}                    

	   
                                                         
   
                                      
    
	private function getObjectService(): object {
		return $this->objectService;
	}                        
}           
