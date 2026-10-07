<?php

   
                                     
  
                                                               
                                                                          
                                                                           
                                                                        
                                                            
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                                    
                                               
  
                         
  
                                                 
  
                                                      
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\BillingHandoffAccessPolicy;
use OCA\Pipelinq\Service\IntegrationDetector;
use OCA\Pipelinq\Service\TimeBillingHandoffService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

   
                                                      
  
                                                       
                                                                             
                                                                             
                                                                    
  
                                                      
   
class BillingHandoffController extends Controller {
	   
                
   
                                         
                                                                                 
                                                                              
                                                                                         
                                                      
    
	public function __construct(
		IRequest $request,
		private TimeBillingHandoffService $handoffService,
		private BillingHandoffAccessPolicy $accessPolicy,
		private IntegrationDetector $integrations,
		private IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                      
   
                                                                     
                                                                         
                                                                   
   
                                                             
   
                                                       
                                                                                      
    
	#[NoAdminRequired]
	public function availability(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['status' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(
			[
				'available' => $this->handoffService->handoffAvailable(),
				'deepLinkUrl' => $this->integrations->shillinq()['url'],
				'isManager' => $this->accessPolicy->isManager(userId: $user->getUID()),
			]
		);
	}                    

	   
                                                                         
   
                                                              
                                                                           
                                                                       
   
                                             
   
                                                       
    
	#[NoAdminRequired]
	public function trigger(string $clientId, string $periodStart = '', string $periodEnd = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['status' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->accessPolicy->isManager(userId: $user->getUID()) === false) {
			return new JSONResponse(['status' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		if ($clientId === '' || $periodStart === '' || $periodEnd === '') {
			return new JSONResponse(
				['status' => 'invalid-request', 'message' => 'clientId, periodStart and periodEnd are required.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$result = $this->handoffService->sendToBilling(
			clientId: $clientId,
			periodStart: $periodStart,
			periodEnd: $periodEnd
		);

		return new JSONResponse($result, $this->statusFor(status: (string)($result['status'] ?? 'failed')));
	}               

	   
                                                        
   
                                             
   
                                     
    
	private function statusFor(string $status): int {
		return match ($status) {
			'synced' => Http::STATUS_OK,
			'empty' => Http::STATUS_OK,
			'not-available' => Http::STATUS_CONFLICT,
			'conflict' => Http::STATUS_CONFLICT,
			'unmapped' => Http::STATUS_UNPROCESSABLE_ENTITY,
			default => Http::STATUS_BAD_GATEWAY,
		};
	}                 
}           
