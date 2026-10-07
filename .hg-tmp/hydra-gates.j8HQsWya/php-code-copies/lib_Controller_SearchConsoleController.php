<?php

   
                                    
  
                                                                        
                                                                      
                                                                 
                                                                      
                              
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                                                       
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\SearchConsole\SearchConsoleImportService;
use OCA\Pipelinq\Service\SearchConsole\SearchQueryReportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

   
                                                                
  
                                                                                                                                                       
   
class SearchConsoleController extends Controller {

	   
                
   
                                    
                                     
                                                            
                                                                  
                                             
                                                               
   
                                                                                                                                                        
    
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly SearchQueryReportService $report,
		private readonly SearchConsoleImportService $importer,
		private readonly IUserSession $userSession,
		private readonly ObjectOwnerAccessPolicy $policy,
	) {
		parent::__construct(appName: $appName, request: $request);
	}                   

	   
                                                                    
   
                                                                   
                                                                    
                                                    
   
                                                       
                                                   
                                     
                                                          
   
                                                                                                                         
   
                                                                                                                                                        
    
	#[NoAdminRequired]
	public function index(?string $from = null, ?string $to = null, int $limit = 50, ?string $property = null): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$result = $this->report->topQueries(from: $from, to: $to, limit: $limit, property: $property);
		$result['configured'] = ($this->importer->hasKey() === true && $this->importer->properties() !== []);
		$result['properties'] = $this->importer->properties();
		$result['serviceAccountEmail'] = $this->importer->serviceAccountEmail();
		$result['lastImportAt'] = $this->importer->lastImportAt();

		return new JSONResponse($result);
	}             
}           
