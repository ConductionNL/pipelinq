<?php

   
                                  
  
                                                                             
                                                                       
                                                                     
                                          
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                            
  
                                                      
  
                                                                    
                                    
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\KccWerkplekService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

   
                                                        
  
                                                      
   
class KccWerkplekController extends Controller {
	   
                
   
                                              
                                                                       
                                                         
                                          
   
                                                       
    
	public function __construct(
		IRequest $request,
		private KccWerkplekService $werkplekService,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                
   
                                                                          
                                                        
                                                                         
   
                                                                           
   
                    
   
                                                       
    
	#[NoAdminRequired]
	public function stateAction(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['message' => 'Authentication required'],
				Http::STATUS_UNAUTHORIZED
			);
		}

		try {
			$payload = $this->werkplekService->getWorkspaceState($user->getUID());
			return new JSONResponse($payload);
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[KccWerkplekController] state failed',
				context: ['error' => $e->getMessage()]
			);
			return new JSONResponse(
				['message' => 'Operation failed'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}         
	}                   

	   
                                       
   
                                                                       
                                                                       
                                                                        
                                     
   
                                                                   
   
                    
   
                                                       
    
	#[NoAdminRequired]
	public function setAvailabilityAction(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['message' => 'Authentication required'],
				Http::STATUS_UNAUTHORIZED
			);
		}

		$param = $this->request->getParam('isAvailable', null);
		if ($param === null) {
			return new JSONResponse(
				['message' => 'isAvailable is required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		                                                                         
		$available = $this->parseAvailability(param: $param);
		if ($available === null) {
			return new JSONResponse(
				['message' => 'isAvailable must be a boolean'],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$result = $this->werkplekService->setAvailability($user->getUID(), $available);
			return new JSONResponse($result);
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[KccWerkplekController] setAvailability failed',
				context: ['error' => $e->getMessage()]
			);
			return new JSONResponse(
				['message' => 'Operation failed'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}         
	}                             

	   
                                                               
   
                                                                                  
                                                                                      
   
                                          
   
                                                                                      
    
	private function parseAvailability(mixed $param): ?bool {
		if (is_bool($param) === true) {
			return $param;
		}

		if (is_string($param) === true && in_array(strtolower($param), ['true', 'false', '1', '0'], true) === true) {
			return in_array(strtolower($param), ['true', '1'], true);
		}

		if (is_int($param) === true && ($param === 0 || $param === 1)) {
			return ($param === 1);
		}

		return null;
	}                         
}           
