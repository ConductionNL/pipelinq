<?php

   
                                   
  
                                                                
                                                                   
                                                                    
                                                                    
                                                                       
                                                    
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                 
  
                                                                    
                                    
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\BackgroundJob\BlastSendJob;
use OCA\Pipelinq\Service\WebhookProcessorService;
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

   
                                                                   
  
                                                                      
                                                                 
                                                                   
                        
                                    
                               
                                  
                                 
                                   
                                   
                                    
  
                                                                     
                                                                   
                                                                        
                                                                            
                                                                           
                                                                           
                                                                             
  
                                                                        
                                                                    
                                          
  
                                                                      
                                  
                                                                           
                                                                            
                                                                            
                                                            
  
                                                                                                 
   
class BlastWebhookController extends Controller {
	   
                                                           
    
	private const SECRET_KEY_PREFIX = 'blast.webhook_secret.';

	   
                
   
                                     
                                                              
                                                                       
                                                                                  
                                          
   
                                                                                                  
    
	public function __construct(
		IRequest $request,
		private IAppConfig $appConfig,
		private BlastSendJob $blastSendJob,
		private WebhookProcessorService $webhookProcessor,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                                    
   
                                                                  
                                                                 
                                                                       
                                                                    
                                                                   
                                                
   
                                                                              
                                                                              
                                                                
   
                                                            
   
                                                                                                  
    
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function sendgrid(): JSONResponse {
		$rawBody = $this->readRawBody();
		$signature = $this->extractSignatureHeader(fallback: 'X-Twilio-Email-Event-Webhook-Signature');

		if ($this->verifySignature(provider: 'sendgrid', rawBody: $rawBody, signature: $signature) === false) {
			$this->logger->warning(
				'BlastWebhookController.sendgrid: invalid signature',
				['ip' => $this->request->getRemoteAddress()]
			);
			                                                               
			                                                               
			                                                             
			                                                                  
			return new JSONResponse(
				['error' => 'Invalid webhook signature'],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$events = json_decode($rawBody, true);
		if (is_array($events) === false) {
			return new JSONResponse(['error' => 'Invalid payload'], Http::STATUS_BAD_REQUEST);
		}

		$accepted = $this->enqueueSendGridBatch(events: $events);

		return new JSONResponse(['ok' => true, 'accepted' => $accepted]);
	}                

	   
                                                                    
   
                                                                    
                                                                   
                                                                
   
                                         
   
                                                                                                  
    
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function ses(): JSONResponse {
		$rawBody = $this->readRawBody();
		$signature = $this->extractSignatureHeader(fallback: 'X-Amz-Sns-Message-Signature');

		if ($this->verifySignature(provider: 'ses', rawBody: $rawBody, signature: $signature) === false) {
			$this->logger->warning(
				'BlastWebhookController.ses: invalid signature',
				['ip' => $this->request->getRemoteAddress()]
			);
			                                                               
			                                                               
			                                                             
			                                                                  
			return new JSONResponse(
				['error' => 'Invalid webhook signature'],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$envelope = json_decode($rawBody, true);
		if (is_array($envelope) === false) {
			return new JSONResponse(['error' => 'Invalid payload'], Http::STATUS_BAD_REQUEST);
		}

		$event = $this->extractSesEvent(envelope: $envelope);
		$this->blastSendJob->enqueueWebhookEvent(provider: 'ses', event: $event);

		return new JSONResponse(['ok' => true, 'accepted' => 1]);
	}           

	   
                                                                
   
                                                                    
                                                                     
                                                                    
                                                                  
   
                                         
   
                                                                                                  
    
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function twilio(): JSONResponse {
		$rawBody = $this->readRawBody();
		$signature = $this->extractSignatureHeader(fallback: 'X-Twilio-Signature');

		if ($this->verifySignature(provider: 'twilio', rawBody: $rawBody, signature: $signature) === false) {
			$this->logger->warning(
				'BlastWebhookController.twilio: invalid signature',
				['ip' => $this->request->getRemoteAddress()]
			);
			                                                               
			                                                               
			                                                             
			                                                                  
			return new JSONResponse(
				['error' => 'Invalid webhook signature'],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$params = $this->request->getParams();
		unset($params['_route']);
		$event = $this->normaliseTwilioEvent(params: $params);

		$this->blastSendJob->enqueueWebhookEvent(provider: 'twilio', event: $event);

		return new JSONResponse(['ok' => true, 'accepted' => 1]);
	}              

	   
                                                                     
                 
   
                                                                       
                                                              
   
                                                            
   
                                                                                                                                                                            
    
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function brevo(): JSONResponse {
		$rawBody = $this->readRawBody();
		$signature = $this->extractSignatureHeader(fallback: 'X-Sib-Signature');

		if ($this->verifySignature(provider: 'brevo', rawBody: $rawBody, signature: $signature) === false) {
			$this->logger->warning('BlastWebhookController.brevo: invalid signature', ['ip' => $this->request->getRemoteAddress()]);
			return new JSONResponse(['error' => 'Invalid webhook signature'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$decoded = json_decode($rawBody, true);
		if (is_array($decoded) === false) {
			return new JSONResponse(['error' => 'Invalid payload'], Http::STATUS_BAD_REQUEST);
		}

		$events = $this->asEventList(decoded: $decoded);
		$accepted = 0;
		foreach ($events as $event) {
			$this->blastSendJob->enqueueWebhookEvent(provider: 'brevo', event: $this->normaliseBrevoEvent(event: $event));
			$accepted++;
		}

		return new JSONResponse(['ok' => true, 'accepted' => $accepted]);
	}             

	   
                                                            
   
                                                          
   
                                                            
   
                                                                                                                                                                            
    
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function mailjet(): JSONResponse {
		$rawBody = $this->readRawBody();
		$signature = $this->extractSignatureHeader(fallback: 'X-Mailjet-Signature');

		if ($this->verifySignature(provider: 'mailjet', rawBody: $rawBody, signature: $signature) === false) {
			$this->logger->warning('BlastWebhookController.mailjet: invalid signature', ['ip' => $this->request->getRemoteAddress()]);
			return new JSONResponse(['error' => 'Invalid webhook signature'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$decoded = json_decode($rawBody, true);
		if (is_array($decoded) === false) {
			return new JSONResponse(['error' => 'Invalid payload'], Http::STATUS_BAD_REQUEST);
		}

		$events = $this->asEventList(decoded: $decoded);
		$accepted = 0;
		foreach ($events as $event) {
			$this->blastSendJob->enqueueWebhookEvent(provider: 'mailjet', event: $this->normaliseMailjetEvent(event: $event));
			$accepted++;
		}

		return new JSONResponse(['ok' => true, 'accepted' => $accepted]);
	}               

	   
                                                            
   
                                                      
                                                                        
                                                                       
                                                                       
                                                  
   
                                         
   
                                                                                                                                                                            
    
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function mailgun(): JSONResponse {
		$rawBody = $this->readRawBody();
		$signature = $this->extractSignatureHeader(fallback: 'X-Mailgun-Signature-256');

		if ($this->verifySignature(provider: 'mailgun', rawBody: $rawBody, signature: $signature) === false) {
			$this->logger->warning('BlastWebhookController.mailgun: invalid signature', ['ip' => $this->request->getRemoteAddress()]);
			return new JSONResponse(['error' => 'Invalid webhook signature'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$envelope = json_decode($rawBody, true);
		if (is_array($envelope) === false) {
			return new JSONResponse(['error' => 'Invalid payload'], Http::STATUS_BAD_REQUEST);
		}

		$eventData = ($envelope['event-data'] ?? $envelope);
		if (is_array($eventData) === false) {
			$eventData = [];
		}

		$this->blastSendJob->enqueueWebhookEvent(provider: 'mailgun', event: $this->normaliseMailgunEvent(event: $eventData));

		return new JSONResponse(['ok' => true, 'accepted' => 1]);
	}               

	   
                                                              
   
                                                                         
                                                                      
                                                                       
                                            
   
                                         
   
                                                                                                                                                                            
    
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function postmark(): JSONResponse {
		$rawBody = $this->readRawBody();
		$signature = $this->extractSignatureHeader(fallback: 'X-Pipelinq-Signature');

		if ($this->verifySignature(provider: 'postmark', rawBody: $rawBody, signature: $signature) === false) {
			$this->logger->warning('BlastWebhookController.postmark: invalid signature', ['ip' => $this->request->getRemoteAddress()]);
			return new JSONResponse(['error' => 'Invalid webhook signature'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$event = json_decode($rawBody, true);
		if (is_array($event) === false) {
			return new JSONResponse(['error' => 'Invalid payload'], Http::STATUS_BAD_REQUEST);
		}

		$this->blastSendJob->enqueueWebhookEvent(provider: 'postmark', event: $this->normalisePostmarkEvent(event: $event));

		return new JSONResponse(['ok' => true, 'accepted' => 1]);
	}                

	   
                                                                  
                                                                   
   
                                                               
   
                                                                  
    
	private function asEventList(array $decoded): array {
		if (array_is_list($decoded) === true) {
			return array_values(array_filter($decoded, static fn ($item) => is_array($item) === true));
		}

		return [$decoded];
	}                   

	   
                                                          
   
                                                       
   
                                                  
    
	private function normaliseBrevoEvent(array $event): array {
		$nativeType = strtolower((string)($event['event'] ?? ''));
		$map = [
			'delivered' => 'delivered',
			'hard_bounce' => 'bounce',
			'soft_bounce' => 'bounce',
			'blocked' => 'bounce',
			'invalid_email' => 'bounce',
			'spam' => 'complaint',
			'unsubscribed' => 'unsubscribe',
			'opened' => 'open',
			'click' => 'click',
		];
		$eventType = ($map[$nativeType] ?? $nativeType);

		$bounceTypeOut = 'soft';
		if ($nativeType === 'hard_bounce' || $nativeType === 'blocked' || $nativeType === 'invalid_email') {
			$bounceTypeOut = 'hard';
		}

		return [
			'eventType' => $eventType,
			'bounceType' => $bounceTypeOut,
			'providerId' => (string)($event['message-id'] ?? $event['id'] ?? ''),
			'email' => (string)($event['email'] ?? ''),
			'timestamp' => (string)($event['date'] ?? ''),
			'reason' => (string)($event['reason'] ?? ''),
		];
	}                           

	   
                                                            
   
                                                         
   
                                                  
    
	private function normaliseMailjetEvent(array $event): array {
		$nativeType = strtolower((string)($event['event'] ?? ''));
		$map = [
			'sent' => 'delivered',
			'open' => 'open',
			'click' => 'click',
			'bounce' => 'bounce',
			'blocked' => 'bounce',
			'spam' => 'complaint',
			'unsub' => 'unsubscribe',
		];
		$eventType = ($map[$nativeType] ?? $nativeType);

		$bounceTypeOut = 'soft';
		if ((bool)($event['hard_bounce'] ?? false) === true || $nativeType === 'blocked') {
			$bounceTypeOut = 'hard';
		}

		$timestamp = ($event['time'] ?? '');
		if (is_numeric($timestamp) === true) {
			$timestamp = gmdate('Y-m-d\TH:i:s\Z', (int)$timestamp);
		}

		return [
			'eventType' => $eventType,
			'bounceType' => $bounceTypeOut,
			'providerId' => (string)($event['MessageID'] ?? ''),
			'email' => (string)($event['email'] ?? ''),
			'timestamp' => (string)$timestamp,
			'reason' => (string)($event['error_related_to'] ?? $event['error'] ?? ''),
		];
	}                             

	   
                                                                          
   
                                                                
   
                                                  
    
	private function normaliseMailgunEvent(array $event): array {
		$nativeType = strtolower((string)($event['event'] ?? ''));
		$map = [
			'delivered' => 'delivered',
			'failed' => 'bounce',
			'opened' => 'open',
			'clicked' => 'click',
			'unsubscribed' => 'unsubscribe',
			'complained' => 'complaint',
		];
		$eventType = ($map[$nativeType] ?? $nativeType);

		$severity = strtolower((string)($event['severity'] ?? ''));
		$bounceTypeOut = 'soft';
		if ($severity === 'permanent') {
			$bounceTypeOut = 'hard';
		}

		$timestamp = ($event['timestamp'] ?? '');
		if (is_numeric($timestamp) === true) {
			$timestamp = gmdate('Y-m-d\TH:i:s\Z', (int)$timestamp);
		}

		return [
			'eventType' => $eventType,
			'bounceType' => $bounceTypeOut,
			'providerId' => (string)($event['message']['headers']['message-id'] ?? $event['id'] ?? ''),
			'email' => (string)($event['recipient'] ?? ''),
			'timestamp' => (string)$timestamp,
			'reason' => (string)($event['reason'] ?? $event['delivery-status']['description'] ?? ''),
		];
	}                             

	   
                                                             
   
                                                          
   
                                                  
    
	private function normalisePostmarkEvent(array $event): array {
		$recordType = (string)($event['RecordType'] ?? '');
		$map = [
			'Delivery' => 'delivered',
			'Bounce' => 'bounce',
			'SpamComplaint' => 'complaint',
			'Open' => 'open',
			'Click' => 'click',
		];
		$eventType = ($map[$recordType] ?? strtolower($recordType));
		if ($recordType === 'SubscriptionChange' && (bool)($event['SuppressSending'] ?? false) === true) {
			$eventType = 'unsubscribe';
		}

		$bounceType = strtolower((string)($event['Type'] ?? ''));
		$bounceTypeOut = 'soft';
		if (str_contains($bounceType, 'hard') === true || $bounceType === 'blocked') {
			$bounceTypeOut = 'hard';
		}

		return [
			'eventType' => $eventType,
			'bounceType' => $bounceTypeOut,
			'providerId' => (string)($event['MessageID'] ?? ''),
			'email' => (string)($event['Email'] ?? $event['Recipient'] ?? ''),
			'timestamp' => (string)($event['DeliveredAt'] ?? $event['BouncedAt'] ?? $event['ReceivedAt'] ?? ''),
			'reason' => (string)($event['Description'] ?? ''),
		];
	}                              

	   
                                               
   
                                                               
   
                               
    
	private function enqueueSendGridBatch(array $events): int {
		$accepted = 0;
		foreach ($events as $event) {
			$normalised = $this->webhookProcessor->normaliseSendGrid(event: $event);
			try {
				$this->blastSendJob->enqueueWebhookEvent(provider: 'sendgrid', event: $normalised);
				$accepted++;
			} catch (Throwable $e) {
				$this->logger->warning(
					'BlastWebhookController.enqueueSendGridBatch: enqueue failed',
					['exception' => $e->getMessage()]
				);
			}
		}

		return $accepted;
	}                            

	   
                                                                    
                                  
   
                            
    
	protected function readRawBody(): string {
		$body = file_get_contents('php://input');
		if ($body === false) {
			return '';
		}

		return $body;
	}                   

	   
                                        
   
                                                                   
                                                                     
                                                              
   
                                         
                                            
                                                                
   
                                 
    
	private function verifySignature(string $provider, string $rawBody, string $signature): bool {
		if ($signature === '') {
			return false;
		}

		$secret = $this->appConfig->getValueString(
			Application::APP_ID,
			self::SECRET_KEY_PREFIX . $provider,
			'',
		);
		if ($secret === '') {
			return false;
		}

		$expected = hash_hmac('sha256', $rawBody, $secret);
		return hash_equals($expected, $signature);
	}                       

	   
                                                                  
   
                                                                     
                                                                  
   
                                                          
   
                                                  
    
	private function extractSesEvent(array $envelope): array {
		$message = ($envelope['Message'] ?? null);
		if (is_string($message) === true) {
			$decoded = json_decode($message, true);
			if (is_array($decoded) === true) {
				return $this->normaliseSesEvent(event: $decoded);
			}
		}

		return $this->normaliseSesEvent(event: $envelope);
	}                       

	   
                                                                   
   
                                                 
   
                                                  
    
	private function normaliseSesEvent(array $event): array {
		$type = strtolower((string)($event['notificationType'] ?? $event['eventType'] ?? ''));
		if ($type === 'bounce') {
			$bounce = ($event['bounce'] ?? []);
			$bounceArray = [];
			if (is_array($bounce) === true) {
				$bounceArray = $bounce;
			}

			$bType = strtolower((string)($bounceArray['bounceType'] ?? ''));
			$bounceTypeOut = 'soft';
			if ($bType === 'permanent') {
				$bounceTypeOut = 'hard';
			}

			return [
				'eventType' => 'bounce',
				'bounceType' => $bounceTypeOut,
				'providerId' => (string)($event['mail']['messageId'] ?? ''),
				'email' => (string)($bounceArray['bouncedRecipients'][0]['emailAddress'] ?? ''),
				'timestamp' => (string)($event['mail']['timestamp'] ?? ''),
				'reason' => (string)($bounceArray['bouncedRecipients'][0]['diagnosticCode'] ?? ''),
			];
		}        

		if ($type === 'complaint') {
			$complaint = ($event['complaint'] ?? []);
			$complaintArray = [];
			if (is_array($complaint) === true) {
				$complaintArray = $complaint;
			}

			return [
				'eventType' => 'complaint',
				'providerId' => (string)($event['mail']['messageId'] ?? ''),
				'email' => (string)($complaintArray['complainedRecipients'][0]['emailAddress'] ?? ''),
				'timestamp' => (string)($event['mail']['timestamp'] ?? ''),
			];
		}

		if ($type === 'delivery') {
			return [
				'eventType' => 'delivered',
				'providerId' => (string)($event['mail']['messageId'] ?? ''),
				'timestamp' => (string)($event['mail']['timestamp'] ?? ''),
			];
		}

		return [
			'eventType' => $type,
			'providerId' => (string)($event['mail']['messageId'] ?? ''),
		];
	}                         

	   
                                                                
   
                                                                     
                                                                     
   
                                                            
   
                                                  
    
	private function normaliseTwilioEvent(array $params): array {
		$status = strtolower((string)($params['MessageStatus'] ?? ''));
		$type = $status;
		if ($status === 'failed' || $status === 'undelivered') {
			$type = 'bounce';
		}

		$bounceTypeOut = 'soft';
		if ($status === 'failed') {
			$bounceTypeOut = 'hard';
		}

		return [
			'eventType' => $type,
			'providerId' => (string)($params['MessageSid'] ?? ''),
			'email' => '',
			'channel' => 'sms',
			'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
			'reason' => (string)($params['ErrorMessage'] ?? ''),
			'bounceType' => $bounceTypeOut,
		];
	}                            

	   
                                                                        
                                                                        
                                                          
   
                                                                    
   
                                                   
    
	private function extractSignatureHeader(string $fallback): string {
		$sig = (string)$this->request->getHeader('X-Pipelinq-Signature');
		if ($sig !== '') {
			return $sig;
		}

		return (string)$this->request->getHeader($fallback);
	}                              
}           
