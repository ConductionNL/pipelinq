<?php

   
                                 
  
                                                                              
                                                                               
                             
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                  
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ReceiptDeliveryService;
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

   
                                        
  
                                                                       
                                                                       
                                                                              
                                                                               
                                                                             
                                                                               
                          
  
                                                  
  
                                                  
                                                                   
   
class PosReceiptController extends Controller {
	   
                
   
                                         
                                                                        
                                                      
                                                
                                              
    
	public function __construct(
		IRequest $request,
		private ReceiptDeliveryService $service,
		private IUserSession $userSession,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                                 
   
                                           
   
                                             
   
                                                   
    
	#[NoAdminRequired]
	public function preview(string $id): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$templateId = $this->optionalString(name: 'template');

		return $this->run(
			action: fn (): array => $this->service->preview(
				transactionId: $id,
				templateId: $templateId,
				userId: $uid
			),
			label: 'preview',
			key: 'receipt'
		);
	}               

	   
                                                         
   
                                           
   
                                          
   
                                                   
    
	#[NoAdminRequired]
	public function email(string $id): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$templateId = $this->optionalString(name: 'template');
		$recipient = $this->optionalString(name: 'recipient');

		return $this->run(
			action: fn (): array => $this->service->emailReceipt(
				transactionId: $id,
				templateId: $templateId,
				requestedRecipient: $recipient,
				userId: $uid
			),
			label: 'email',
			key: 'receipt'
		);
	}             

	   
                                                              
   
                                           
   
                                                                          
   
                                                   
    
	#[NoAdminRequired]
	public function print(string $id): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$templateId = $this->optionalString(name: 'template');

		return $this->run(
			action: fn (): array => $this->service->printReceipt(
				transactionId: $id,
				templateId: $templateId,
				userId: $uid
			),
			label: 'print',
			key: 'receipt'
		);
	}             

	   
                                                                            
   
                                       
   
                                                  
    
	private function optionalString(string $name): ?string {
		$raw = (string)$this->request->getParam($name, '');
		if (trim($raw) === '') {
			return null;
		}

		return $raw;
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

	   
                                                                   
   
                                              
                                                       
                                                 
   
                                      
    
	private function run(callable $action, string $label, string $key): JSONResponse {
		try {
			return new JSONResponse([$key => $action()]);
		} catch (OCSNotFoundException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (\Throwable $e) {
			$this->logger->error('PosReceiptController::' . $label . ' failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(
				['error' => $this->l10n->t('An unexpected error occurred')],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}         
	}           
}           
