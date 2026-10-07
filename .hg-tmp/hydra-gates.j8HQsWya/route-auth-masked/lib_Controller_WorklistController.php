<?php

   
                               
  
                                                                       
                                                                       
                                                                 
                                        
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                            
  
                                                                    
  
                                                                    
                                    
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\WorklistService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

   
                                                                       
                                                         
  
                                                                    
   
class WorklistController extends Controller {
	   
                
   
                                              
                                                                   
                                                         
                                          
    
	public function __construct(
		IRequest $request,
		private WorklistService $worklistService,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                           
   
                                                                      
                                                                      
                                                                    
                                                                  
                                                                 
                                                                  
            
   
                                                                    
   
                                                                     
    
	#[NoAdminRequired]
	public function mine(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$limitParam = $this->request->getParam('limit', '');
		$limit = null;
		if ($limitParam !== '' && $limitParam !== null) {
			if (is_string($limitParam) === false || ctype_digit($limitParam) === false || ((int)$limitParam) < 1) {
				return new JSONResponse(['message' => 'Invalid limit'], Http::STATUS_BAD_REQUEST);
			}

			$limit = (int)$limitParam;
		}

		try {
			return new JSONResponse($this->worklistService->getMine(userId: $user->getUID(), limit: $limit));
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[WorklistController] mine failed',
				context: ['error' => $e->getMessage()]
			);
			return new JSONResponse(['message' => 'Worklist unavailable'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}         
	}            
}           
