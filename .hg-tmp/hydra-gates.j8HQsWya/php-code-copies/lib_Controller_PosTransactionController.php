<?php

   
                                     
  
                                                                          
                                                                      
                         
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                           
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\PosTransactionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

   
                                                      
  
                                                                        
                                                                                
                                                                     
                                                                      
                                                                          
                                                                              
                                                    
  
                                                  
  
                                                           
                                                                   
   
class PosTransactionController extends Controller {
	   
                
   
                                         
                                                                      
                                                      
                                                
                                              
    
	public function __construct(
		IRequest $request,
		private PosTransactionService $service,
		private IUserSession $userSession,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                          
   
                                           
   
                                                 
   
                                                            
    
	#[NoAdminRequired]
	public function confirm(string $id): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		return $this->run(
			action: fn (): array => $this->service->confirmTransaction(id: $id, userId: $uid),
			label: 'confirm'
		);
	}               

	   
                                   
   
                                           
   
                                                 
   
                                                            
    
	#[NoAdminRequired]
	public function settle(string $id): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		return $this->run(
			action: fn (): array => $this->service->settleTransaction(id: $id, userId: $uid),
			label: 'settle'
		);
	}              

	   
                                                                    
   
                                           
   
                                                 
   
                                                            
    
	#[NoAdminRequired]
	public function refund(string $id): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$reason = (string)$this->request->getParam('reason', '');
		return $this->run(
			action: fn (): array => $this->service->refundTransaction(id: $id, reason: $reason, userId: $uid),
			label: 'refund'
		);
	}              

	   
                             
   
                                           
   
                                                 
   
                                                            
    
	#[NoAdminRequired]
	public function park(string $id): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		return $this->run(
			action: fn (): array => $this->service->parkTransaction(id: $id, userId: $uid),
			label: 'park'
		);
	}            

	   
                                
   
                                           
   
                                                 
   
                                                            
    
	#[NoAdminRequired]
	public function resume(string $id): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		return $this->run(
			action: fn (): array => $this->service->resumeTransaction(id: $id, userId: $uid),
			label: 'resume'
		);
	}              

	   
                                                           
   
                                                                      
                                                                       
                                                                               
                                                                          
                                                   
   
                                               
   
                                                  
    
	#[NoAdminRequired]
	public function taxReport(): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$statusParam = (string)$this->request->getParam('status', '');
		$status = null;
		if ($statusParam !== '') {
			$status = $statusParam;
		}

		return $this->run(
			action: fn (): array => $this->service->taxReport(status: $status, userId: $uid),
			label: 'taxReport',
			key: 'report'
		);
	}                 

	   
                                                       
   
                                                                    
                                                                            
                                                                        
                                                                            
   
                                                                       
    
	private function requireUserId(): string|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authentication required')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		return $user->getUID();
	}                     

	   
                                                      
   
                                                                          
                                                          
   
                                              
                                                       
                                                                         
   
                                      
    
	private function run(callable $action, string $label, string $key = 'transaction'): JSONResponse {
		try {
			return new JSONResponse([$key => $action()]);
		} catch (OCSNotFoundException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (\Throwable $e) {
			$this->logger->error('PosTransactionController::' . $label . ' failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(
				['error' => $this->l10n->t('An unexpected error occurred')],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}         
	}           
}           
