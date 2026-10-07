<?php

   
                                        
  
                                                                               
                                                                              
                                                                           
          
  
                                                                               
                                                                         
                                       
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                            
  
                                                                                                                                                                       
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Integration\ContactMomentLeafProvider;
use OCA\Pipelinq\Service\ContactMomentFilingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

   
                                       
  
                                                                                                                                                                       
   
class ContactMomentLeafController extends Controller {
	   
                
   
                                         
                                                                        
                                                                         
    
	public function __construct(
		IRequest $request,
		private readonly ContactMomentLeafProvider $provider,
		private readonly ContactMomentFilingService $filingService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                    
   
                                                                          
                                                                          
                                                                        
   
                                                 
                                
                                                                            
                                                                            
   
                                                             
   
                                                                                                                                                                        
    
	#[NoAdminRequired]
	public function index(
		string $hostId,
		int $limit = ContactMomentLeafProvider::DEFAULT_LIMIT,
		string $partyId = '',
	): JSONResponse {
		$result = $this->provider->list(hostId: $hostId, limit: $limit, partyId: $partyId);

		return $this->respond(result: $result);
	}             

	   
                                               
   
                                                 
                                                           
                                                   
                                                           
                                                     
                                                   
                                                                     
                                                                     
   
                                                                    
   
                                                                                                                                                                        
    
	#[NoAdminRequired]
	public function create(
		string $hostId,
		string $title = '',
		string $channel = '',
		string $direction = '',
		?string $outcome = null,
		?string $summary = null,
		?string $occurredAt = null,
		?string $client = null,
	): JSONResponse {
		$result = $this->provider->create(
			hostId: $hostId,
			payload: [
				'title' => $title,
				'channel' => $channel,
				'direction' => $direction,
				'outcome' => ($outcome ?? ''),
				'summary' => ($summary ?? ''),
				'occurredAt' => ($occurredAt ?? ''),
				'client' => ($client ?? ''),
			],
		);

		return $this->respond(result: $result);
	}              

	   
                                                          
   
                                                                          
                                                                        
                            
   
                                                      
                                                   
   
                                                                    
   
                                                                                                                                                                    
    
	#[NoAdminRequired]
	public function fileOnAlsoCase(string $momentId, string $caseId = ''): JSONResponse {
		return $this->respond(
			result: $this->filingService->fileOnAlsoCase(momentId: $momentId, caseId: $caseId)
		);
	}                      

	   
                                       
   
                                                      
                                                  
                                                                      
                                      
   
                                                                    
   
                                                                                                                                                                 
    
	#[NoAdminRequired]
	public function unfileFromCase(string $momentId, string $caseId, ?string $newPrimary = null): JSONResponse {
		return $this->respond(
			result: $this->filingService->unfileFromCase(
				momentId: $momentId,
				caseId: $caseId,
				newPrimary: $newPrimary,
			)
		);
	}                      

	   
                                                
   
                                                              
   
                                      
    
	private function respond(array $result): JSONResponse {
		$status = (int)($result['status'] ?? 200);
		unset($result['status']);

		return new JSONResponse($result, $status);
	}               
}           
