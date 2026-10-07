<?php

   
                                 
  
                                                                           
                                                                       
                                                                               
                                                                              
                                                                        
                                                                       
                                                    
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                                                                             
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\PosPaymentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                          
  
                                                                         
                                                                       
                                                                        
                                                                       
                                                                    
                                                                       
                                                                      
                                                                          
  
                                                                            
                                                                          
                                                            
  
                                                                                                             
   
class PosPaymentController extends Controller {
	   
                
   
                                         
                                                          
                                                  
                                                
                                              
    
	public function __construct(
		IRequest $request,
		private PosPaymentService $service,
		private IUserSession $session,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	                                                                        
	                                                                       
	                                                                        

	   
                                                                     
   
                                                                                                                                         
   
                        
   
                                                                                                              
    
	public function index(): JSONResponse {
		try {
			return new JSONResponse(['providers' => $this->service->listProviders()]);
		} catch (Throwable $e) {
			return $this->serverError(label: 'index', exception: $e);
		}
	}             

	   
                                                               
   
                                          
   
                        
   
                                                                           
                                                            
   
                                                                                                              
    
	public function show(string $name): JSONResponse {
		try {
			return new JSONResponse(['provider' => $this->service->getProviderConfig(name: $name)]);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			return $this->serverError(label: 'show', exception: $e);
		}
	}            

	   
                                                                             
   
                                          
   
                        
   
                                                                          
                                                                       
   
                                                                                                              
                                                                                                              
    
	public function update(string $name): JSONResponse {
		try {
			$data = $this->request->getParams();
			return new JSONResponse(['provider' => $this->service->updateProvider(name: $name, data: $data)]);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			return $this->serverError(label: 'update', exception: $e);
		}
	}              

	   
                                                                       
   
                                          
   
                        
   
                                                                       
                                                                           
                  
   
                                                                                                              
    
	public function test(string $name): JSONResponse {
		try {
			return new JSONResponse(['result' => $this->service->testConnection(name: $name)]);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			return $this->serverError(label: 'test', exception: $e);
		}
	}            

	                                                                        
	                                           
	                                                                        

	   
                                                            
   
                                         
   
                        
   
                                                                                                              
    
	#[NoAdminRequired]
	public function initiate(string $id): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$providerName = $this->stringParam(name: 'providerName');
		$paymentMethod = $this->stringParam(name: 'paymentMethod');

		if ($providerName === '' || $paymentMethod === '') {
			return new JSONResponse(
				['error' => $this->l10n->t('Betaalmethode niet geconfigureerd')],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		return $this->run(
			action: fn (): array => $this->service->initiatePayment(
				transactionId: $id,
				providerName: $providerName,
				paymentMethod: $paymentMethod
			),
			label: 'initiate'
		);
	}                

	   
                                                                     
   
                                         
   
                        
   
                                                                                                              
    
	#[NoAdminRequired]
	public function capture(string $id): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		return $this->run(
			action: fn (): array => $this->service->capturePayment(transactionId: $id),
			label: 'capture'
		);
	}               

	   
                                                               
   
                                         
   
                        
   
                                                                                                              
    
	#[NoAdminRequired]
	public function refund(string $id): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$reason = $this->stringParam(name: 'reason');
		return $this->run(
			action: fn (): array => $this->service->refundPayment(
				transactionId: $id,
				reason: $reason,
				userId: $uid
			),
			label: 'refund'
		);
	}              

	                                                                        
	                                                  
	                                                                        

	   
                                                                
   
                                                                        
                                                                     
                              
   
                                                                   
                                                                           
                                                                           
                                    
                                                                
   
                                                                            
             
   
                                              
   
                        
   
                                                                          
             
   
                                                                                                              
    
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function webhook(string $provider): JSONResponse {
		$rawBody = $this->readRawBody();
		$signature = $this->resolveSignatureHeader(provider: $provider);

		try {
			$result = $this->service->handleWebhook(
				providerName: $provider,
				rawPayload: $rawBody,
				signature: $signature
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'PosPaymentController::webhook crashed',
				[
					'provider' => $provider,
					'class' => get_class($e),
				]
			);
			                                                      
			  
			                                                                  
			                                                                 
			                                                                   
			                                                                 
			                                                                  
			                                                                 
			              
			  
			                                                                
			return new JSONResponse(
				['status' => 'error', 'error' => 'Webhook processing failed'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		$status = (string)($result['status'] ?? '');
		if ($status === 'invalid') {
			return new JSONResponse(
				['error' => $this->l10n->t('Invalid webhook signature')],
				Http::STATUS_BAD_REQUEST
			);
		}

		if ($status === 'unmatched') {
			                                                         
			                                                                  
			                                                                  
			                                                                  
			                                                            
			                                                                   
			                                                       
			                                                                  
			                
			return new JSONResponse(
				$result,
				Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		return new JSONResponse($result);
	}               

	                                                                        
	           
	                                                                        

	   
                                                        
   
                                                                         
                                                      
   
                                              
   
                  
    
	private function resolveSignatureHeader(string $provider): string {
		return match ($provider) {
			'mollie' => (string)$this->request->getHeader('X-Mollie-Signature'),
			'ccv' => (string)$this->request->getHeader('X-CCV-Signature'),
			'adyen' => (string)$this->request->getHeader('X-Adyen-Signature'),
			'stripe' => (string)$this->request->getHeader('Stripe-Signature'),
			default => '',
		};
	}                              

	   
                                                                                   
   
                  
    
	private function readRawBody(): string {
		$body = file_get_contents('php://input');
		if (is_string($body) === true && $body !== '') {
			return $body;
		}

		$params = $this->request->getParams();
		if (is_array($params) === true && $params !== []) {
			$encoded = json_encode($params);
			if (is_string($encoded) === true) {
				return $encoded;
			}
		}

		return '';
	}                   

	   
                                                                           
   
                               
    
	private function requireUserId(): string|JSONResponse {
		$user = $this->session->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authentication required')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		return $user->getUID();
	}                     

	   
                                             
   
                                       
   
                  
    
	private function stringParam(string $name): string {
		$raw = $this->request->getParam($name, '');
		if (is_string($raw) === false) {
			return '';
		}

		return trim($raw);
	}                   

	   
                                                   
   
                                       
                                       
   
                        
    
	private function run(callable $action, string $label): JSONResponse {
		try {
			return new JSONResponse($action());
		} catch (OCSNotFoundException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			return $this->serverError(label: $label, exception: $e);
		}         
	}           

	   
                                                      
   
                                       
                                              
   
                        
    
	private function serverError(string $label, Throwable $exception): JSONResponse {
		$this->logger->error(
			'PosPaymentController::' . $label . ' failed',
			['class' => get_class($exception)]
		);
		return new JSONResponse(
			['error' => $this->l10n->t('An unexpected error occurred')],
			Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}                   
}           
