<?php

   
                                
  
                                                                               
                                                                           
  
                                                                              
                                                              
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                            
  
                                                                                                                                                                                           
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\PartyKindRegistryService;
use OCA\Pipelinq\Service\PartyLinkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

   
                                                                    
  
                                                                                                                                                                                      
   
class PartyKindController extends Controller {
	   
                
   
                                         
                                                                          
                                                                       
    
	public function __construct(
		IRequest $request,
		private readonly PartyKindRegistryService $registry,
		private readonly PartyLinkService $linkService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                           
   
                                                                            
                                  
   
                                                                             
   
                                                                                                                                                                                            
    
	#[NoAdminRequired]
	public function index(string $recordType = ''): JSONResponse {
		return new JSONResponse(
			[
				'recordType' => $recordType,
				                                                           
				                                                                
				'declared' => ($this->registry->acceptanceFor(recordType: $recordType) !== null),
				'kinds' => $this->registry->kindsFor(recordType: $recordType),
			],
			200
		);
	}             

	   
                                          
   
                                                                  
                                       
                                   
                                            
   
                                                                         
   
                                                                                                                                                                                          
    
	#[NoAdminRequired]
	public function link(
		string $recordType = '',
		string $recordId = '',
		string $party = '',
		string $kind = '',
	): JSONResponse {
		return $this->respond(
			result: $this->linkService->link(
				recordType: $recordType,
				recordId: $recordId,
				party: $party,
				kind: $kind,
			)
		);
	}            

	   
                                                                     
   
                                                                  
                                       
                                                                                
   
                                                                   
   
                                                                                                                                                                                          
    
	#[NoAdminRequired]
	public function import(string $recordType = '', string $recordId = '', array $rows = []): JSONResponse {
		return $this->respond(
			result: $this->linkService->import(
				recordType: $recordType,
				recordId: $recordId,
				rows: $rows,
			)
		);
	}              

	   
                     
   
                                          
   
                                                        
   
                                                                                                                                                                                         
    
	#[NoAdminRequired]
	public function endLink(string $linkId): JSONResponse {
		return $this->respond(result: $this->linkService->end(linkId: $linkId));
	}               

	   
                                               
   
                                                             
   
                                      
    
	private function respond(array $result): JSONResponse {
		$status = (int)($result['status'] ?? 200);
		unset($result['status']);

		return new JSONResponse($result, $status);
	}               
}           
