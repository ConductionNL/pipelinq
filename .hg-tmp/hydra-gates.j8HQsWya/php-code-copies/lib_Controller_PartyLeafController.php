<?php

   
                                
  
                                                                            
                                                                             
                                                                             
         
  
                                                                        
                                                                            
                                                             
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                            
  
                                                                                                                                                                                                                      
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Integration\PartyLeafProvider;
use OCA\Pipelinq\Service\PartyIndicatorService;
use OCA\Pipelinq\Service\PartyOrganisationTreeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

   
                                                                  
  
                                                                                                                                                                                                        
   
class PartyLeafController extends Controller {
	   
                
   
                                         
                                                                
                                                                                         
                                                                                   
    
	public function __construct(
		IRequest $request,
		private readonly PartyLeafProvider $provider,
		private readonly PartyIndicatorService $indicatorService,
		private readonly PartyOrganisationTreeService $treeService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                   
   
                                                   
   
                                                   
   
                                                                                                                                                                                                         
    
	#[NoAdminRequired]
	public function panel(string $partyId): JSONResponse {
		return $this->respond(result: $this->provider->describe(partyId: $partyId));
	}             

	   
                                                  
   
                                                   
                                                             
   
                                                                      
   
                                                                                                                                                                                                                       
    
	#[NoAdminRequired]
	public function blocked(string $partyId, string $act): JSONResponse {
		                                                                      
		                                                                       
		                                                               
		$panel = $this->provider->describe(partyId: $partyId);
		if ((int)($panel['status'] ?? 500) !== 200) {
			return $this->respond(result: $panel);
		}

		try {
			$answer = $this->indicatorService->isBlocked(partyId: $partyId, act: $act);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], 400);
		}

		return new JSONResponse($answer, 200);
	}               

	   
                                                                
   
                                                      
   
                                                           
   
                                                                                                                                                                                 
    
	#[NoAdminRequired]
	public function acknowledge(string $valueId): JSONResponse {
		return $this->respond(result: $this->indicatorService->acknowledge(valueId: $valueId));
	}                   

	   
                                                                    
   
                                                    
                                                                           
   
                                                                           
   
                                                                                                                                                                                                        
    
	#[NoAdminRequired]
	public function setParent(string $partyId, ?string $parentId = null): JSONResponse {
		return $this->respond(
			result: $this->treeService->setParent(nodeId: $partyId, parentId: $parentId)
		);
	}                 

	   
                                               
   
                                                             
   
                                      
    
	private function respond(array $result): JSONResponse {
		$status = (int)($result['status'] ?? 200);
		unset($result['status']);

		return new JSONResponse($result, $status);
	}               
}           
