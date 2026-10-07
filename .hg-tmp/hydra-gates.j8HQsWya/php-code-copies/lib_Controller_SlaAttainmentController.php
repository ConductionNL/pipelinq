<?php

   
                                    
  
                                                                   
                              
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                            
  
                                                         
  
                                                                    
                                    
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\SlaAttainmentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                         
  
                                                                      
                                                                     
                             
  
                                                                                          
   
class SlaAttainmentController extends Controller {
	   
                
   
                                          
                                                               
                                                    
                                                                                
                                          
    
	public function __construct(
		IRequest $request,
		private SlaAttainmentService $attainment,
		private IUserSession $userSession,
		private ObjectOwnerAccessPolicy $accessPolicy,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                            
   
                                                                      
   
                                                          
    
	#[NoAdminRequired]
	public function attainment(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'notAuthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		                                                               
		                                                 
		if ($this->accessPolicy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$params = [
			'bucket' => $this->request->getParam('bucket', 'month'),
			'date' => $this->request->getParam('date', ''),
			'week' => $this->request->getParam('week', ''),
			'month' => $this->request->getParam('month', ''),
			'quarter' => $this->request->getParam('quarter', ''),
			'groupBy' => $this->request->getParam('groupBy', 'policy'),
			'policy' => $this->request->getParam('policy', ''),
		];

		try {
			$payload = $this->attainment->compute($params);
			return new JSONResponse($payload);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			$this->logger->error(
				'SlaAttainmentController: compute failed',
				['error' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'computeFailed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}                  
}           
