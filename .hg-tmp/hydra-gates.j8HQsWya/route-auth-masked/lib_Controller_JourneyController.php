<?php

   
                              
  
                                                                            
                                                                          
                                            
  
                                                                           
                                                                         
                           
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                                                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\Marketing\JourneyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

   
                                                                    
  
                                                                                                                                                                                   
   
class JourneyController extends Controller {

	   
                
   
                                  
                                         
                                                             
                                                 
                                                               
   
                                                                                                                                                                                    
    
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly JourneyService $journeys,
		private readonly IUserSession $userSession,
		private readonly ObjectOwnerAccessPolicy $policy,
	) {
		parent::__construct(appName: $appName, request: $request);
	}                   

	   
                                                
   
                                      
   
                                                                                                                                                                                    
    
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$denied = $this->guard();
		if ($denied !== null) {
			return $denied;
		}

		return new JSONResponse(['results' => $this->journeys->listJourneys()]);
	}             

	   
                
   
                                           
   
                                             
   
                                                                             
                                                                           
                                                 
   
                                                                                                                                                                                    
    
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		$denied = $this->guard();
		if ($denied !== null) {
			return $denied;
		}

		try {
			$journey = $this->journeys->find(journeyId: $id);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => 'read_failed', 'reason' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}

		if ($journey === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($journey);
	}            

	   
                                       
   
                                            
   
                                                                                                                                                                                    
    
	#[NoAdminRequired]
	public function create(): JSONResponse {
		return $this->write(id: null);
	}              

	   
                                          
   
                                           
   
                                            
   
                                                                                                                                                                                    
    
	#[NoAdminRequired]
	public function update(string $id): JSONResponse {
		return $this->write(id: $id);
	}              

	   
                                                  
   
                                           
   
                                                          
   
                                                                                                                                                                                    
    
	#[NoAdminRequired]
	public function compile(string $id): JSONResponse {
		$denied = $this->guard();
		if ($denied !== null) {
			return $denied;
		}

		$journey = $this->journeys->compile(journeyId: $id, runAs: $this->uid());
		if ($journey === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($journey);
	}               

	   
                                              
   
                                           
   
                                  
   
                                                                                                                                                                                 
    
	#[NoAdminRequired]
	public function runs(string $id): JSONResponse {
		$denied = $this->guard();
		if ($denied !== null) {
			return $denied;
		}

		return new JSONResponse(['results' => $this->journeys->runsFor(journeyId: $id)]);
	}            

	   
                                                
   
                                                                    
   
                                                                
    
	private function write(?string $id): JSONResponse {
		$denied = $this->guard();
		if ($denied !== null) {
			return $denied;
		}

		$payload = $this->collectBody();
		if (trim((string)($payload['name'] ?? '')) === '') {
			return new JSONResponse(['error' => 'name_required'], Http::STATUS_BAD_REQUEST);
		}

		$stored = $this->journeys->save(payload: $payload, createdByUid: $this->uid(), journeyId: $id);
		if ($stored === null) {
			return new JSONResponse(['error' => 'write_failed'], Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse($stored);
	}             

	   
                                             
   
                                                                        
                                                                        
                         
   
                                                       
    
	private function collectBody(): array {
		$payload = [];
		foreach (['name', 'description', 'status', 'audienceSegment', 'waitFor'] as $key) {
			$value = $this->request->getParam($key);
			if (is_scalar($value) === true) {
				$payload[$key] = (string)$value;
			}
		}

		foreach (['trigger', 'condition', 'action'] as $key) {
			$value = $this->request->getParam($key);
			if (is_array($value) === true) {
				$payload[$key] = $value;
			}
		}

		return $payload;
	}                   

	   
                         
   
                                                           
    
	private function uid(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();
	}           

	   
                                                                   
   
                                                                               
    
	private function guard(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return null;
	}             
}           
