<?php

   
                              
  
                                                                        
                                                                   
                                                                   
                                                                   
                                                 
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                           
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\SegmentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

   
                                        
  
                                                                                                                           
   
class SegmentController extends Controller {
	   
                
   
                                         
                                                                      
                                                          
                                                                          
    
	public function __construct(
		IRequest $request,
		private readonly SegmentService $segmentService,
		private readonly IUserSession $userSession,
		private readonly ObjectOwnerAccessPolicy $policy,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                       
   
                                         
                                
   
                                                
   
                                              
    
	#[NoAdminRequired]
	public function index(int $page = 1, int $limit = 20): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->unauthorized();
		}

		                                                                       
		                                                                     
		if ($this->policy->isPrivileged(uid: $uid) === false) {
			return $this->forbidden();
		}

		$envelope = $this->segmentService->listSegments(page: $page, limit: $limit);
		return new JSONResponse($envelope);
	}             

	   
                                                                  
   
                                                                          
                                                                           
                                                                     
                            
   
                                                     
   
                                                                                                                                                                                    
    
	#[NoAdminRequired]
	public function signals(): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->unauthorized();
		}

		if ($this->policy->isPrivileged(uid: $uid) === false) {
			return $this->forbidden();
		}

		return new JSONResponse([
			'catalogue' => $this->segmentService->signalCatalogue(),
			'availability' => $this->segmentService->signalAvailability(),
		]);
	}               

	   
                                                                     
   
                                                                              
   
                                              
    
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$user = $this->requireUser();
		if ($user === null) {
			return $this->unauthorized();
		}

		$payload = $this->collectSegmentBody();
		$result = $this->segmentService->createSegment(payload: $payload, createdByUid: $user);
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

		$segment = $this->segmentService->getSegmentById(segmentId: $id);
		if ($segment === null) {
			return $this->notFound();
		}

		$segment['estimatedSize'] = $this->segmentService->estimateSize(segmentId: $id);
		return new JSONResponse($segment);
	}            

	   
                                                                
   
                                           
                                                       
   
                                                                      
   
                                              
    
	#[NoAdminRequired]
	public function members(string $id, int $limit = 50): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->unauthorized();
		}

		                                                                       
		                                                                     
		if ($this->policy->isPrivileged(uid: $uid) === false) {
			return $this->forbidden();
		}

		$rows = $this->segmentService->previewSegmentMembers(segmentId: $id, limit: $limit);
		return new JSONResponse(['members' => $rows]);
	}               

	   
                                                                          
   
                                                                        
                                                                       
                                                                       
                                                              
                                                                       
                      
   
                                                         
   
                                                                                                                                     
    
	#[NoAdminRequired]
	public function preview(): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->unauthorized();
		}

		                                                                     
		                                                                      
		if ($this->policy->isPrivileged(uid: $uid) === false) {
			return $this->forbidden();
		}

		$entityType = strtolower((string)$this->request->getParam('entityType', ''));
		$rules = $this->collectRulesBody();
		$result = $this->segmentService->previewRulePayload(rules: $rules, entityType: $entityType);
		return new JSONResponse($result);
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

		$payload = $this->collectSegmentBody();
		$result = $this->segmentService->updateSegment(segmentId: $id, payload: $payload);
		return $this->renderUpdate(result: $result);
	}              

	   
                                                                    
   
                                           
   
                                           
   
                                              
    
	#[NoAdminRequired]
	public function refreshSize(string $id): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->unauthorized();
		}

		                                                                       
		                                                                     
		if ($this->policy->isPrivileged(uid: $uid) === false) {
			return $this->forbidden();
		}

		try {
			$size = $this->segmentService->refreshSegmentSize(segmentId: $id);
		} catch (Throwable $e) {
			                                                                     
			                                                                
			                                                                   
			                                                           
			unset($e);
			return new JSONResponse(
				['error' => 'The refreshed segment size could not be saved'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new JSONResponse(['estimatedSize' => $size]);
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

	   
                                            
   
                                                                   
                                                                    
                                                                    
   
                                                   
    
	private function collectSegmentBody(): array {
		$rules = $this->request->getParam('rules');
		if (is_string($rules) === true) {
			$decoded = json_decode($rules, true);
			if (is_array($decoded) === true) {
				$rules = $decoded;
			}
		}

		return [
			'name' => (string)$this->request->getParam('name', ''),
			'description' => (string)$this->request->getParam('description', ''),
			'entityType' => (string)$this->request->getParam('entityType', ''),
			'rules' => $rules,
		];
	}                          

	   
                                                                         
                                                                          
                               
   
                                                                                        
    
	private function collectRulesBody(): array {
		$rules = $this->request->getParam('rules');
		if (is_string($rules) === true) {
			$decoded = json_decode($rules, true);
			if (is_array($decoded) === true) {
				$rules = $decoded;
			}
		}

		if (is_array($rules) === true) {
			return $rules;
		}

		return [];
	}                        

	   
                             
   
                                                                                                             
   
                        
    
	private function renderUpdate(array $result): JSONResponse {
		if (isset($result['error']) === true) {
			$status = Http::STATUS_BAD_REQUEST;
			if ($result['error'] === 'Segment not found') {
				$status = Http::STATUS_NOT_FOUND;
			}

			return new JSONResponse(['error' => $result['error']], $status);
		}

		$payload = [
			'segment' => ($result['segment'] ?? null),
			'estimatedSize' => (int)($result['estimatedSize'] ?? 0),
		];
		return new JSONResponse($payload, Http::STATUS_OK);
	}                    

	   
                             
   
                                                                                                             
   
                        
    
	private function renderCreate(array $result): JSONResponse {
		if (isset($result['error']) === true) {
			return new JSONResponse(['error' => $result['error']], Http::STATUS_BAD_REQUEST);
		}

		$payload = [
			'segment' => ($result['segment'] ?? null),
			'estimatedSize' => (int)($result['estimatedSize'] ?? 0),
		];
		return new JSONResponse($payload, Http::STATUS_CREATED);
	}                    

	   
                         
   
                        
    
	private function unauthorized(): JSONResponse {
		return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
	}                    

	   
                         
   
                        
    
	private function notFound(): JSONResponse {
		return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
	}                
}           
