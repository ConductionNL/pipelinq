<?php

   
                                                
  
                                                                       
                                                                        
                                                                       
                                                                         
                                                                   
                                                        
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\AppointmentDepositService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                     
  
                                                                      
                                                                                    
                                                                   
                                                                        
                           
  
                                                   
   
class AppointmentPaymentWebhookController extends Controller {
	   
                
   
                                         
                                                                    
                                                                                     
                                              
   
                                                    
    
	public function __construct(
		IRequest $request,
		private IAppConfig $appConfig,
		private AppointmentDepositService $deposit,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                                    
   
                                      
       
                              
                                                                 
                                            
       
   
                                                                   
                                                                    
   
                                                                             
                                                                             
                                                                            
                                                                            
                           
   
                                                                    
                                                                     
   
                                                                              
                                                                               
                                                                      
                                                                        
                  
   
                                                    
    
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function callback(): JSONResponse {
		$rawBody = $this->readRawBody();
		$signature = (string)$this->request->getHeader('X-Pipelinq-Signature');

		if ($this->verifySignature(rawBody: $rawBody, signature: $signature) === false) {
			$this->logger->warning(
				'AppointmentPaymentWebhookController: invalid signature',
				['ip' => $this->request->getRemoteAddress()]
			);
			                                                           
			                                                           
			return new JSONResponse(
				['error' => 'Invalid webhook signature'],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$payload = json_decode($rawBody, true);
		if (is_array($payload) === false) {
			return new JSONResponse(
				['error' => 'Invalid payload'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$bookingId = trim((string)($payload['bookingId'] ?? ''));
		$status = trim((string)($payload['status'] ?? ''));
		if ($bookingId === '' || $status === '') {
			return new JSONResponse(
				['error' => 'bookingId and status are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$outcome = $this->deposit->handlePaymentCallback(
				bookingId: $bookingId,
				status: $status
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'AppointmentPaymentWebhookController: callback handler failed',
				['booking' => $bookingId]
			);
			                                                           
			                                                            
			                                                 
			return new JSONResponse(['ok' => true, 'outcome' => 'deferred']);
		}

		return new JSONResponse(['ok' => true, 'outcome' => $outcome]);
	}                

	   
                                                                    
                                                       
   
                  
    
	protected function readRawBody(): string {
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

	   
                                           
   
                                                                 
                                                               
          
   
                                                
                                                                         
   
                
    
	private function verifySignature(string $rawBody, string $signature): bool {
		if ($rawBody === '' || $signature === '') {
			return false;
		}

		$secret = $this->appConfig->getValueString(
			Application::APP_ID,
			AppointmentDepositService::WEBHOOK_SECRET_KEY,
			''
		);
		if ($secret === '') {
			return false;
		}

		$expected = hash_hmac('sha256', $rawBody, $secret);
		return hash_equals($expected, $signature);
	}                       
}           
