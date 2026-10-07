<?php

   
                               
  
                                                   
  
                       
                                    
  
                                                              
                                  
                                                                                    
  
                         
  
                            
  
                                                                                            
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\ProspectDiscoveryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

   
                                     
  
                                                                                                   
   
class ProspectController extends Controller {
	   
                
   
                                         
                                                                            
                                                      
                                                
                                                                          
    
	public function __construct(
		IRequest $request,
		private ProspectDiscoveryService $discoveryService,
		private IUserSession $userSession,
		private IL10N $l10n,
		private ObjectOwnerAccessPolicy $policy,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                 
   
                                              
   
                    
                                                                                    
    
	public function index(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		                                                        
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['error' => $this->l10n->t('Forbidden')], Http::STATUS_FORBIDDEN);
		}

		$refresh = $this->request->getParam(key: 'refresh', default: 'false') === 'true';
		                                                                          
		$limit = (string)$this->request->getParam(key: 'limit', default: '10');
		if (ctype_digit($limit) === false) {
			return new JSONResponse(['error' => 'invalid_limit'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$result = $this->discoveryService->discover(refresh: $refresh, limit: (int)$limit);

			if (isset($result['error']) === true) {
				return new JSONResponse(data: $result, statusCode: 400);
			}

			return new JSONResponse(data: $result);
		} catch (\Exception $e) {
			return new JSONResponse(
				data: [
					'error' => 'api_unavailable',
					'message' => $this->l10n->t('Prospect discovery service is currently unavailable.'),
				],
				statusCode: 503
			);
		}         
	}             
}           
