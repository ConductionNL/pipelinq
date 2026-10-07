<?php

   
                                  
  
                                                                       
                                                                        
                                                                        
                                                
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                
                                                                
                                                                
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\PosCustomerLinkService;
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

   
                                              
  
                                                                        
                                                                       
                                                                        
                                                                     
  
                                                                          
                                                                            
                                       
  
                                                                
   
class PosCustomerController extends Controller {
	   
                
   
                                         
                                                                         
                                                      
                                                
                                              
    
	public function __construct(
		IRequest $request,
		private PosCustomerLinkService $service,
		private IUserSession $userSession,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                              
   
                                                                   
                                                                 
                                                                       
   
                                                         
   
                                                                 
    
	#[NoAdminRequired]
	public function search(): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$query = (string)$this->request->getParam('query', '');
		$limit = (int)$this->request->getParam('limit', (string)PosCustomerLinkService::DEFAULT_SEARCH_LIMIT);

		return $this->run(
			action: fn (): array => $this->service->searchCustomers(query: $query, limit: $limit),
			label: 'search',
			key: 'customers'
		);
	}              

	   
                                                                        
                                             
   
                                                           
                                                              
   
                                           
   
                                                 
   
                                                                 
                                                                 
    
	#[NoAdminRequired]
	public function attach(string $id): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$contact = (string)$this->request->getParam('customer', '');
		$consent = $this->parseBool(value: $this->request->getParam('marketingConsent'));

		if ($contact === '') {
			return new JSONResponse(
				['error' => $this->l10n->t('customer UUID is required')],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		return $this->run(
			action: fn (): array => $this->service->attachCustomer(
				transactionId: $id,
				contactUuid: $contact,
				marketingConsent: $consent
			),
			label: 'attachCustomer'
		);
	}              

	   
                                                          
   
                                                 
   
                                           
   
                                                 
   
                                                                 
    
	#[NoAdminRequired]
	public function detach(string $id): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		return $this->run(
			action: fn (): array => $this->service->detachCustomer(transactionId: $id),
			label: 'detachCustomer'
		);
	}              

	   
                                              
   
                                                                      
                                                                       
                                             
   
                                                  
   
                                          
   
                                                                 
    
	#[NoAdminRequired]
	public function history(string $id): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$limit = (int)$this->request->getParam('limit', '0');

		return $this->run(
			action: fn (): array => $this->service->getCustomerHistory(contactUuid: $id, limit: $limit),
			label: 'history',
			key: 'history'
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

	   
                                                                     
   
                                            
   
                                    
    
	private function parseBool(mixed $value): bool {
		if (is_bool($value) === true) {
			return $value;
		}

		if (is_string($value) === true) {
			return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
		}

		return ((int)$value) === 1;
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
			$this->logger->error('PosCustomerController::' . $label . ' failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(
				['error' => $this->l10n->t('An unexpected error occurred')],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}         
	}           
}           
