<?php

   
                            
  
                                                                      
                                                                     
                                                                       
                                                                
                                                                        
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                         
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\AttributionService;
use OCA\Pipelinq\Service\BlastService;
use OCA\Pipelinq\Service\CampaignPerformanceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

   
                                      
  
                                                                         
                                                                        
                                                        
  
                                                                                                                         
   
class BlastController extends Controller {
	   
                
   
                                         
                                                                  
                                                                                          
                                                          
                                                                          
                                                                                                                               
    
	public function __construct(
		IRequest $request,
		private readonly BlastService $blastService,
		private readonly AttributionService $attributionService,
		private readonly IUserSession $userSession,
		private readonly ObjectOwnerAccessPolicy $policy,
		private readonly CampaignPerformanceService $performanceService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                                    
   
                                                                                
                                         
                                
   
                                                
   
                                              
    
	#[NoAdminRequired]
	public function index(?string $status = null, int $page = 1, int $limit = 20): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->unauthorized();
		}

		                                                                     
		                                                                 
		                                
		if ($this->policy->isPrivileged(uid: $uid) === false) {
			return $this->forbidden();
		}

		$envelope = $this->blastService->listBlasts(status: $status, page: $page, limit: $limit);
		return new JSONResponse($envelope);
	}             

	   
                                            
   
                                                                      
   
                                              
    
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$user = $this->requireUser();
		if ($user === null) {
			return $this->unauthorized();
		}

		$payload = $this->collectBlastBody();
		$result = $this->blastService->createDraftBlast(payload: $payload, createdByUid: $user);
		return $this->renderCreate(result: $result);
	}              

	   
                                          
   
                                         
   
                                                           
   
                                              
    
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->unauthorized();
		}

		                                                                     
		                                                                 
		                                
		if ($this->policy->isPrivileged(uid: $uid) === false) {
			return $this->forbidden();
		}

		$blast = $this->blastService->getBlastById(blastId: $id);
		if ($blast === null) {
			return $this->notFound();
		}

		return new JSONResponse($blast);
	}            

	   
                                                             
   
                                         
   
                                                                        
   
                                              
    
	#[NoAdminRequired]
	public function update(string $id): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->unauthorized();
		}

		                                                                     
		                                                                 
		                                
		if ($this->policy->isPrivileged(uid: $uid) === false) {
			return $this->forbidden();
		}

		$name = trim((string)$this->request->getParam('name', ''));
		if ($name === '') {
			return new JSONResponse(['error' => 'Invalid name'], Http::STATUS_BAD_REQUEST);
		}

		$blast = $this->blastService->patchBlastName(blastId: $id, name: $name);
		if ($blast === null) {
			return $this->notFound();
		}

		return new JSONResponse($blast);
	}              

	   
                                                             
   
                                         
   
                                                            
   
                                              
    
	#[NoAdminRequired]
	public function send(string $id): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->unauthorized();
		}

		                                                                     
		                                                                 
		                                
		if ($this->policy->isPrivileged(uid: $uid) === false) {
			return $this->forbidden();
		}

		$summary = $this->blastService->sendBlast(blastId: $id);
		return new JSONResponse($summary);
	}            

	   
                                                                 
   
                                         
   
                                                     
   
                                              
    
	#[NoAdminRequired]
	public function cancel(string $id): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->unauthorized();
		}

		                                                                     
		                                                                 
		                                
		if ($this->policy->isPrivileged(uid: $uid) === false) {
			return $this->forbidden();
		}

		$summary = $this->blastService->cancelBlast(blastId: $id);
		return new JSONResponse($summary);
	}              

	   
                                                                  
   
                                         
                                         
                                
   
                                                                             
   
                                              
    
	#[NoAdminRequired]
	public function deliveries(string $id, int $page = 1, int $limit = 20): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->unauthorized();
		}

		                                                                     
		                                                                 
		                                
		if ($this->policy->isPrivileged(uid: $uid) === false) {
			return $this->forbidden();
		}

		$envelope = $this->blastService->listDeliveriesForBlast(blastId: $id, page: $page, limit: $limit);
		return new JSONResponse($envelope);
	}                  

	   
                                                                    
                                                                    
                                
   
                                         
   
                                                                                                          
   
                                                                                                                                        
    
	#[NoAdminRequired]
	public function attribution(string $id): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->unauthorized();
		}

		                                                                     
		                                                                 
		                                
		if ($this->policy->isPrivileged(uid: $uid) === false) {
			return $this->forbidden();
		}

		$blast = $this->blastService->getBlastById(blastId: $id);
		if ($blast === null) {
			return $this->notFound();
		}

		$summary = $this->attributionService->getBlastAttributionSummary(blastId: $id);
		return new JSONResponse($summary);
	}                   

	   
                                                                  
                                                                     
                                                                      
                                                              
   
                                         
                                                                                          
                                                                      
   
                                                                
   
                                                                                                                                                                      
    
	#[NoAdminRequired]
	public function performance(string $id, ?string $from = null, ?string $to = null): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->unauthorized();
		}

		                                                              
		                                
		if ($this->policy->isPrivileged(uid: $uid) === false) {
			return $this->forbidden();
		}

		$record = $this->performanceService->forBlast(blastId: $id, from: $from, to: $to);
		if ($record === null) {
			return $this->notFound();
		}

		return new JSONResponse($record);
	}                   

	   
                                                                   
   
                                    
    
	private function requireUser(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		return $user->getUID();
	}                   

	   
                                                          
   
                                          
    
	private function forbidden(): JSONResponse {
		return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
	}                 

	   
                                                          
   
                                                                     
                                                    
   
                                                   
    
	private function collectBlastBody(): array {
		return [
			'name' => (string)$this->request->getParam('name', ''),
			'segmentId' => (string)$this->request->getParam('segmentId', ''),
			'templateId' => (string)$this->request->getParam('templateId', ''),
			'channel' => (string)$this->request->getParam('channel', ''),
			'connectorSourceId' => (string)$this->request->getParam('connectorSourceId', ''),
			'scheduledFor' => (string)$this->request->getParam('scheduledFor', ''),
		];
	}                        

	   
                                                                     
   
                                                                                      
   
                        
    
	private function renderCreate(array $result): JSONResponse {
		if (isset($result['error']) === true) {
			return new JSONResponse(['error' => $result['error']], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($result['blast'] ?? null, Http::STATUS_CREATED);
	}                    

	   
                         
   
                        
    
	private function unauthorized(): JSONResponse {
		return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
	}                    

	   
                         
   
                        
    
	private function notFound(): JSONResponse {
		return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
	}                
}           
