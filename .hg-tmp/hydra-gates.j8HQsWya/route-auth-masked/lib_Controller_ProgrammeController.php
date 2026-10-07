<?php

   
                                
  
                                                                             
                                                                               
                       
  
                                                                             
                                                                           
                      
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                            
  
                                                                                                                                                           
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ProgrammeEstimationService;
use OCA\Pipelinq\Service\ProgrammePortfolioService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Throwable;

   
                                      
  
                                                                                                                                                                    
   
class ProgrammeController extends Controller {
	   
                
   
                                         
                                                                        
                                                                               
                                                                              
                                                                        
    
	public function __construct(
		IRequest $request,
		private readonly ProgrammePortfolioService $portfolio,
		private readonly ProgrammeEstimationService $estimation,
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppManager $appManager,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                                      
   
                                                    
   
                                   
   
                                                                                                                                                                     
    
	#[NoAdminRequired]
	public function workItems(string $programmeId): JSONResponse {
		$installed = $this->installedApps();

		$items = array_map(
			fn (array $workItem): array => $this->portfolio->presentWorkItem(
				workItem: $workItem,
				resolvedTitles: [],
				installedApps: $installed,
			),
			$this->portfolio->workItemsOf(programmeId: $programmeId)
		);

		return new JSONResponse(['workItems' => $items], 200);
	}                 

	   
                                        
   
                                             
                                                                 
                                                     
                                                            
   
                                                                    
   
                                                                                                                                                                     
    
	#[NoAdminRequired]
	public function linkWork(
		string $programmeId,
		string $domainObjectType = '',
		string $domainObjectRef = '',
		string $title = '',
	): JSONResponse {
		return $this->respond(
			result: $this->portfolio->linkWork(
				programmeId: $programmeId,
				domainObjectType: $domainObjectType,
				domainObjectRef: $domainObjectRef,
				title: $title,
			)
		);
	}                

	   
                                                                 
   
                                                    
   
                                                            
   
                                                                                                                                                            
    
	#[NoAdminRequired]
	public function progress(string $programmeId): JSONResponse {
		$programme = $this->readObject(id: $programmeId);
		if ($programme === null) {
			return new JSONResponse(['error' => 'You may not read this programme.'], 403);
		}

		                                                                     
		                                                 
		$effort = null;
		if ($this->appManager->isEnabledForUser('humaniq') === true) {
			$effort = ['booked' => 0, 'estimated' => 0];
		}

		return new JSONResponse(
			$this->portfolio->progressFor(
				programme: $programme,
				tasks: $this->portfolio->tasksOf(programmeId: $programmeId),
				effort: $effort,
			),
			200
		);
	}                

	   
                                                  
   
                                            
   
                                                   
   
                                                                                                                                                                 
    
	#[NoAdminRequired]
	public function closeCycle(string $cycleId): JSONResponse {
		$cycle = $this->readObject(id: $cycleId);
		if ($cycle === null) {
			return new JSONResponse(['error' => 'You may not read this cycle.'], 403);
		}

		return $this->respond(
			result: $this->estimation->closeCycle(
				cycle: $cycle,
				tasks: $this->portfolio->read(
					schemaKey: 'programmeTask_schema',
					filters: ['cycle' => trim($cycleId)],
				),
			)
		);
	}                  

	   
                                                               
   
                                            
   
                                                        
   
                                                                                                                                                                 
    
	#[NoAdminRequired]
	public function cycleChart(string $cycleId): JSONResponse {
		$cycle = $this->readObject(id: $cycleId);
		if ($cycle === null) {
			return new JSONResponse(['error' => 'You may not read this cycle.'], 403);
		}

		return new JSONResponse(
			$this->estimation->chartFor(
				cycle: $cycle,
				tasks: $this->portfolio->read(
					schemaKey: 'programmeTask_schema',
					filters: ['cycle' => trim($cycleId)],
				),
			),
			200
		);
	}                  

	   
                               
   
                                           
    
	private function installedApps(): array {
		try {
			return $this->appManager->getEnabledApps();
		} catch (Throwable $e) {
			return [];
		}
	}                     

	   
                                  
   
                               
   
                                                          
    
	private function readObject(string $id): ?array {
		$id = trim($id);
		if ($id === '') {
			return null;
		}

		try {
			$entity = $this->objectService->find(id: $id);
		} catch (Throwable $e) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		$data = $entity->jsonSerialize();

		if (is_array($data) === false) {
			return null;
		}

		return $data;
	}                  

	   
                                               
   
                                                             
   
                                      
    
	private function respond(array $result): JSONResponse {
		$status = (int)($result['status'] ?? 200);
		unset($result['status']);

		return new JSONResponse($result, $status);
	}               
}           
