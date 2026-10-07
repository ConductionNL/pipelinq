<?php

   
                              
  
                                                                          
                                                            
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                                               
                                    
  
                         
  
                                                 
  
                                                       
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\RoutingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

   
                                         
  
                                                  
  
                                                       
   
#[NoAdminRequired]
class RoutingController extends Controller {
	   
                                                            
   
                           
    
	private const VALID_ENTITY_TYPES = ['request', 'lead'];

	   
                
   
                                         
                                                              
                                                      
                                                                                
                                              
    
	public function __construct(
		IRequest $request,
		private RoutingService $routingService,
		private IUserSession $userSession,
		private ObjectOwnerAccessPolicy $accessPolicy,
		private LoggerInterface $logger,
	) {
		                                                                    
		                                                                 
		                                                                   
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                              
   
                 
                                      
                        
   
                                                                                
                                                                     
                                                                                         
   
                                                          
    
	#[NoAdminRequired]
	public function getSuggestions(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['message' => 'Authentication required'],
				Http::STATUS_UNAUTHORIZED
			);
		}

		                                                                    
		                                                     
		if ($this->accessPolicy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$entityType = (string)$this->request->getParam('entityType', '');
		$entityId = (string)$this->request->getParam('entityId', '');

		if ($entityType === '' || $entityId === '') {
			return new JSONResponse(
				['message' => 'entityType and entityId are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		if (in_array($entityType, self::VALID_ENTITY_TYPES, true) === false) {
			return new JSONResponse(
				['message' => 'Invalid entityType'],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$result = $this->routingService->getSuggestedAgents(
				entityType: $entityType,
				entityId: $entityId
			);
		} catch (\Throwable $e) {
			                                                                   
			$this->logger->error(
				'RoutingController: getSuggestions failed',
				[
					'exception' => $e,
					'entityType' => $entityType,
					'entityId' => $entityId,
				]
			);
			return new JSONResponse(
				['message' => 'Operation failed'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new JSONResponse($result, Http::STATUS_OK);
	}                      
}           
