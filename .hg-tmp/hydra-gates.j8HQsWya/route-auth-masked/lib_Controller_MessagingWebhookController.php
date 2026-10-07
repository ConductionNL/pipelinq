<?php

   
                                       
  
                                                               
                                                              
                                                                  
                                                              
                                                       
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                   
  
                                                                    
                                    
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\SmsAdapter;
use OCA\Pipelinq\Service\WhatsAppAdapter;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                     
  
                                                              
                                                                 
                                                                 
               
  
                                                                   
   
class MessagingWebhookController extends Controller {
	   
                
   
                                     
                                                             
                                              
                                          
   
                                                                    
    
	public function __construct(
		IRequest $request,
		private WhatsAppAdapter $whatsAppAdapter,
		private SmsAdapter $smsAdapter,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                       
   
                                                                             
                                                           
   
                                                   
   
                                 
   
                                                                         
                                                                            
   
                                                                    
    
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function whatsapp(string $providerId): JSONResponse {
		$rawBody = $this->readRawBody();
		$signature = (string)$this->request->getHeader('X-Hub-Signature-256');

		try {
			$result = $this->whatsAppAdapter->handleInboundWebhook(
				rawBody: $rawBody,
				signature: $signature,
				providerId: $providerId,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'MessagingWebhookController.whatsapp: processing failed',
				['providerId' => $providerId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'processingFailed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return $this->respondForResult(result: $result);
	}                

	   
                                                  
   
                                                   
   
                                 
   
                                                                    
    
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function sms(string $providerId): JSONResponse {
		$rawBody = $this->readRawBody();
		$signatureRaw = $this->request->getHeader('X-Twilio-Signature');
		if ($signatureRaw === '') {
			$signatureRaw = $this->request->getHeader('messagebird-signature');
		}

		if ($signatureRaw === '') {
			$signatureRaw = $this->request->getHeader('X-Cmcom-Signature');
		}

		$signature = (string)$signatureRaw;

		try {
			$result = $this->smsAdapter->handleInboundWebhook(
				rawBody: $rawBody,
				signature: $signature,
				providerId: $providerId,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'MessagingWebhookController.sms: processing failed',
				['providerId' => $providerId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'processingFailed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return $this->respondForResult(result: $result);
	}           

	   
                                                      
   
                          
                                                               
                                      
                                                               
                                                 
   
                                                       
   
                                  
    
	private function respondForResult(array $result): JSONResponse {
		$status = (string)($result['status'] ?? '');
		if ($status === 'received') {
			return new JSONResponse($result, Http::STATUS_OK);
		}

		if ($status === 'invalidSignature') {
			return new JSONResponse(['error' => 'invalidSignature'], Http::STATUS_BAD_REQUEST);
		}

		if ($status === 'serviceUnavailable') {
			                                                                
			                                                     
			return new JSONResponse(['error' => 'serviceUnavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse($result, Http::STATUS_UNPROCESSABLE_ENTITY);
	}                        

	   
                                                
   
                                     
    
	private function readRawBody(): string {
		$body = file_get_contents('php://input');
		if ($body === false) {
			return '';
		}

		return $body;
	}                   
}           
