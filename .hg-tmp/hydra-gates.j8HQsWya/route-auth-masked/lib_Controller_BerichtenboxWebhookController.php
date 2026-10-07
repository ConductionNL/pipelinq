<?php

   
                                          
  
                                                                        
                                                                            
                                                                     
                                                                   
                                                               
                                                   
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                                                                      
                                                                                                      
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\BerichtenboxService;
use OCA\Pipelinq\Service\LogiusConnector;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                             
  
                                                   
   
class BerichtenboxWebhookController extends Controller {
	   
                
   
                                     
                                                                  
                                                              
                                                        
                                          
    
	public function __construct(
		IRequest $request,
		private readonly BerichtenboxService $berichtenbox,
		private readonly LogiusConnector $logius,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                                      
   
               
       
                                    
                                      
       
   
                                                                           
                                         
   
                                                                  
                                                  
   
                                                    
    
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function readReceipt(): JSONResponse {
		$rawBody = $this->readRawBody();
		if ($this->logius->handleWebhookSignature($this->request, $rawBody) === false) {
			$this->logger->warning(
				'Berichtenbox readReceipt webhook: invalid signature.',
				['ip' => $this->request->getRemoteAddress()]
			);
			return new JSONResponse(
				['error' => 'Invalid webhook signature'],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$payload = json_decode($rawBody, true);
		if (is_array($payload) === false) {
			return new JSONResponse(['error' => 'Invalid payload'], Http::STATUS_BAD_REQUEST);
		}

		$logiusMessageId = (string)($payload['logiusMessageId'] ?? '');
		$readAt = (string)($payload['readAt'] ?? '');
		if ($logiusMessageId === '' || $readAt === '') {
			return new JSONResponse(
				['error' => 'logiusMessageId and readAt are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$updated = $this->berichtenbox->handleReadReceipt($logiusMessageId, $readAt);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Berichtenbox readReceipt handler failed.',
				['exception' => $e->getMessage(), 'logiusMessageId' => $logiusMessageId]
			);
			return new JSONResponse(['ok' => false, 'deferred' => true]);
		}

		return new JSONResponse(['ok' => true, 'updated' => $updated]);
	}                   

	   
                                                                        
   
               
       
                                    
                                   
                          
                                                                          
       
   
                                                                     
                                                                     
                                                                                                       
    
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function inboundReply(): JSONResponse {
		$rawBody = $this->readRawBody();
		if ($this->logius->handleWebhookSignature($this->request, $rawBody) === false) {
			$this->logger->warning(
				'Berichtenbox inboundReply webhook: invalid signature.',
				['ip' => $this->request->getRemoteAddress()]
			);
			return new JSONResponse(
				['error' => 'Invalid webhook signature'],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$payload = json_decode($rawBody, true);
		if (is_array($payload) === false) {
			return new JSONResponse(['error' => 'Invalid payload'], Http::STATUS_BAD_REQUEST);
		}

		$parentMessageId = (string)($payload['parentMessageId'] ?? '');
		$logiusReplyId = (string)($payload['logiusReplyId'] ?? '');
		$bodyText = (string)($payload['bodyText'] ?? '');
		if ($parentMessageId === '' || $logiusReplyId === '' || $bodyText === '') {
			return new JSONResponse(
				['error' => 'parentMessageId, logiusReplyId and bodyText are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$attachments = ($payload['attachments'] ?? []);
		if (is_array($attachments) === false) {
			$attachments = [];
		}

		try {
			$reply = $this->berichtenbox->handleInboundReply(
				parentMessageId: $parentMessageId,
				logiusReplyId: $logiusReplyId,
				bodyText: $bodyText,
				attachments: $attachments
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Berichtenbox inboundReply handler failed.',
				['exception' => $e->getMessage(), 'parentMessageId' => $parentMessageId]
			);
			return new JSONResponse(
				['error' => 'Reply processing failed: ' . $e->getMessage()],
				Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(
			[
				'ok' => true,
				'interactionId' => (string)($reply['createdInteractionId'] ?? ''),
				'replyId' => (string)($reply['uuid'] ?? ''),
			]
		);
	}                    

	   
                              
   
                  
    
	protected function readRawBody(): string {
		$body = file_get_contents('php://input');
		if ($body !== false && $body !== '') {
			return $body;
		}

		                                       
		$params = $this->request->getParams();
		$encoded = json_encode($params);
		if ($encoded === false) {
			return '';
		}

		return $encoded;
	}                   
}           
