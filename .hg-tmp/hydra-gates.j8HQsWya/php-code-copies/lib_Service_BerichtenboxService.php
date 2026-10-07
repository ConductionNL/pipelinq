<?php

   
                                
  
                                                               
                                                           
                                                                 
                                                                 
                                        
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                                                                       
                                                                                                      
                                                                                                       
                                                                                                      
                                                                                                      
                                                                                                    
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                          
  
                                                                            
                                                                         
                                                                     
                                                   
                                                                           
                                                                          
                                                                                
                                                                                
                                 
  
                                                                                                       
   
class BerichtenboxService {
	   
                                                    
   
            
    
	public const MAX_RETRIES = 5;

	   
                                                                   
   
                        
    
	public const RETRY_BACKOFF_SECONDS = [60, 300, 900, 3600, 14400];

	   
                                              
   
               
    
	public const DEFAULT_TENANT_ID = 'default';

	   
                
   
                                                      
                                            
                                                           
                                                                
                                                             
                                                      
                                                                    
                                                         
                                                                    
                                                                               
                                             
                                                                                         
   
                                                                                                        
   
                                                                                  
                                                                                 
                       
    
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly EncryptionService $encryption,
		private readonly TemplateRenderer $templateRenderer,
		private readonly MailboxResolver $mailboxResolver,
		private readonly LogiusConnector $logius,
		private readonly EmailFallbackSender $emailFallback,
		private readonly DeliveryAuditLogger $auditLogger,
		private readonly DutchHolidayCalendar $holidayCalendar,
		private readonly TicketService $ticketService,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                                           
   
                                           
                                                                         
                                          
                                                                   
                                                                              
                                                                        
                                                              
                                                           
                                                          
                                                          
                                                     
   
                                                           
   
                                                                 
   
                                                                                                        
    
	public function queueOutboundMessage(
		string $caseId,
		?string $interactionId,
		string $status,
		string $bsn,
		?array $templateOverride = null,
		array $extraVariables = [],
		array $attachments = [],
	): array {
		$tenantId = $this->resolveTenantId();
		$template = ($templateOverride ?? $this->findTemplate(
			caseType: (string)($extraVariables['caseType'] ?? ''),
			status: $status,
			language: (string)($extraVariables['language'] ?? 'nl')
		));
		$template = ($template ?? $this->fallbackTemplate());

		$variables = array_merge(
			[
				'caseId' => $caseId,
				'status' => $status,
				'gemeente' => $this->appConfig->getValueString(
					Application::APP_ID,
					'tenant_display_name',
					'Gemeente'
				),
				'deadline' => '',
				'messageId' => $this->logius->newUuidV4(),
			],
			$extraVariables
		);

		$rendered = $this->templateRenderer->render($template, $variables);

		$messageUuid = $variables['messageId'];
		$bsnHash = $this->encryption->hashBsn($bsn, $tenantId);

		$payload = [
			'uuid' => $messageUuid,
			'bsn' => $this->encryption->encrypt($bsn, $tenantId),
			'bsnHash' => $bsnHash,
			'interactionId' => ($interactionId ?? ''),
			'caseId' => $caseId,
			'subject' => $rendered['subject'],
			'body' => $rendered['body'],
			'templateId' => (string)($template['id'] ?? $template['uuid'] ?? 'inline'),
			'attachments' => $attachments,
			'deliveryStatus' => 'queued',
			'retryCount' => 0,
			'mailboxAvailable' => null,
		];

		$saved = $this->saveMessage(payload: $payload);
		$this->auditLogger->logQueued(
			messageId: $messageUuid,
			payloadHash: $this->auditLogger->hashPayload($rendered['body']),
			retentionUntil: $this->auditLogger->calculateRetentionUntil((string)($extraVariables['caseType'] ?? ''))
		);

		return $saved;
	}                            

	   
                                                                      
   
                                                             
   
                                             
                                                    
    
	public function dispatchQueuedMessages(int $limit = 100): int {
		$messages = $this->findDispatchableMessages(limit: $limit);
		$processed = 0;
		foreach ($messages as $message) {
			try {
				$this->dispatchOne(message: $message);
			} catch (\Throwable $e) {
				$this->logger->error(
					'BerichtenboxService dispatch failed.',
					[
						'exception' => $e->getMessage(),
						'messageId' => (string)($message['uuid'] ?? ($message['id'] ?? '')),
					]
				);
			}

			$processed++;
		}

		return $processed;
	}                              

	   
                                     
   
                                               
   
                
   
                                                                                                        
    
	public function dispatchOne(array $message): void {
		$tenantId = $this->resolveTenantId();
		$messageId = (string)($message['uuid'] ?? $message['id'] ?? '');
		$bsnPlain = $this->decryptBsn(row: $message, tenantId: $tenantId);
		$bodyHash = $this->auditLogger->hashPayload((string)($message['body'] ?? ''));

		$resolution = $this->mailboxResolver->resolve($bsnPlain, $tenantId);

		if ($resolution['mailboxAvailable'] === true && $resolution['optedOut'] === false) {
			$this->dispatchToBerichtenbox(
				message: $message,
				messageId: $messageId,
				bsnPlain: $bsnPlain,
				bodyHash: $bodyHash
			);
			return;
		}

		                                           
		$this->dispatchFallback(
			message: $message,
			messageId: $messageId,
			bodyHash: $bodyHash,
			optedOut: $resolution['optedOut']
		);
	}                   

	   
                                                                     
   
                                                      
                                                       
   
                                                                   
                                                    
    
	public function handleReadReceipt(string $logiusMessageId, string $readAtIso): bool {
		$message = $this->findMessageByLogiusId(logiusMessageId: $logiusMessageId);
		if ($message === null) {
			$this->logger->warning(
				'Berichtenbox readReceipt for unknown logiusMessageId.',
				['logiusMessageId' => $logiusMessageId]
			);
			return false;
		}

		$message['readAt'] = $readAtIso;
		$message['deliveryStatus'] = 'read';
		$this->saveMessage(payload: $message);

		$this->auditLogger->logRead(
			messageId: (string)($message['uuid'] ?? $message['id'] ?? ''),
			payloadHash: $this->auditLogger->hashPayload((string)($message['body'] ?? ''))
		);
		return true;
	}                         

	   
                                              
   
                                               
                                                    
    
	public function processFallbackQueue(): int {
		$unread = $this->findUnreadSentMessages();
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$sent = 0;
		foreach ($unread as $message) {
			$sentToBerichtenboxAt = (string)($message['sentToBerichtenboxAt'] ?? '');
			if ($sentToBerichtenboxAt === '') {
				continue;
			}

			try {
				$sentDate = new DateTimeImmutable($sentToBerichtenboxAt);
			} catch (\Throwable) {
				continue;
			}

			$eligibleAt = $this->holidayCalendar->addWorkingDays($sentDate, 5);
			if ($eligibleAt > $now) {
				continue;
			}

			$burgerEmail = (string)($message['burgerEmail'] ?? '');
			if ($burgerEmail === '') {
				$this->logger->info(
					'Berichtenbox 5-day fallback skipped: no burgerEmail.',
					['messageId' => (string)($message['uuid'] ?? '')]
				);
				continue;
			}

			try {
				$accepted = $this->emailFallback->send($message, $burgerEmail, true);
				if ($accepted === false && $this->emailFallback->lastRefusal() !== null) {
					                                                           
					                                                 
					continue;
				}
			} catch (\Throwable $e) {
				$this->logger->warning(
					'5-day fallback email send failed.',
					['exception' => $e->getMessage()]
				);
				continue;
			}

			$message['deliveryStatus'] = 'fallback-emailed';
			$message['fallbackTriggeredAt'] = $now->format(DATE_ATOM);
			$message['fallbackEmail'] = $burgerEmail;
			$message['fallbackSentAt'] = $now->format(DATE_ATOM);
			$this->saveMessage(payload: $message);

			$this->auditLogger->logFallback(
				messageId: (string)($message['uuid'] ?? ''),
				reason: '5-day-unread',
				payloadHash: $this->auditLogger->hashPayload((string)($message['body'] ?? ''))
			);
			$sent++;
		}             

		return $sent;
	}                            

	   
                                                                     
                                                             
                  
   
                                                                   
                                                 
                                             
                                                          
   
                                                        
   
                                                           
                                                                                                        
    
	public function handleInboundReply(
		string $parentMessageId,
		string $logiusReplyId,
		string $bodyText,
		array $attachments = [],
	): array {
		$parent = $this->findMessageByUuid(uuid: $parentMessageId);
		if ($parent === null) {
			throw new RuntimeException(
				'Berichtenbox inbound reply: parent message not found: ' . $parentMessageId
			);
		}

		$tenantId = $this->resolveTenantId();
		$bsnPlain = $this->decryptBsn(row: $parent, tenantId: $tenantId);
		$bsnHash = $this->encryption->hashBsn($bsnPlain, $tenantId);
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

		$reply = [
			'uuid' => $this->logius->newUuidV4(),
			'parentMessageId' => $parentMessageId,
			'logiusReplyId' => $logiusReplyId,
			'receivedAt' => $now->format(DATE_ATOM),
			'bsn' => $this->encryption->encrypt($bsnPlain, $tenantId),
			'bsnHash' => $bsnHash,
			'bodyText' => $bodyText,
			'attachments' => $attachments,
		];

		try {
			$reply = $this->saveReply(payload: $reply);
		} catch (\Throwable $e) {
			$this->auditLogger->logProcessingError(
				messageId: $reply['uuid'],
				reason: $e->getMessage(),
				payloadHash: $this->auditLogger->hashPayload($bodyText)
			);
			throw $e;
		}

		$interactionId = null;
		try {
			$interactionId = $this->createContactmomentFromReply(parent: $parent, reply: $reply);
			$reply['createdInteractionId'] = $interactionId;
			$reply['processedAt'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
			$this->saveReply(payload: $reply);
		} catch (\Throwable $e) {
			$reply['processingError'] = $e->getMessage();
			$this->saveReply(payload: $reply);
			$this->auditLogger->logProcessingError(
				messageId: $reply['uuid'],
				reason: $e->getMessage(),
				payloadHash: $this->auditLogger->hashPayload($bodyText)
			);
			throw $e;
		}

		$this->auditLogger->logReplyReceived(
			replyId: $reply['uuid'],
			payloadHash: $this->auditLogger->hashPayload($bodyText)
		);

		return $reply;
	}                          

	   
                                                                     
                                                           
   
                                     
                                      
   
                                                           
                                                                                                        
    
	public function cryptoShred(string $bsn, string $tenantId): int {
		$bsnHash = $this->encryption->hashBsn($bsn, $tenantId);
		                                                                  
		                                                                   
		$shredBlob = base64_encode(random_bytes(64));

		$total = 0;
		foreach (['berichtenboxMessage_schema', 'berichtenboxReply_schema', 'mailboxResolution_schema'] as $schemaKey) {
			$schemaId = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
			$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
			if ($schemaId === '' || $registerId === '') {
				continue;
			}

			try {
				$rows = $this->getObjectService()->findAll(
					config: [
						'filters' => [
							'register' => $registerId,
							'schema' => $schemaId,
							'bsnHash' => $bsnHash,
						],
					]
				);
			} catch (\Throwable $e) {
				$this->logger->warning(
					'cryptoShred lookup failed.',
					['exception' => $e->getMessage(), 'schemaKey' => $schemaKey]
				);
				continue;
			}

			foreach ($rows as $row) {
				$data = $this->toArray(row: $row);
				if ($data === null) {
					continue;
				}

				$data['bsn'] = $shredBlob;
				try {
					$this->getObjectService()->saveObject(
						object: $data,
						extend: [],
						register: $registerId,
						schema: $schemaId,
						uuid: ($data['uuid'] ?? null)
					);
					$total++;
				} catch (\Throwable $e) {
					$this->logger->warning(
						'cryptoShred save failed.',
						['exception' => $e->getMessage(), 'schemaKey' => $schemaKey]
					);
				}
			}             
		}             

		return $total;
	}                   

	                                                                          

	   
                                        
   
                                          
                                  
                                
                                            
   
                
    
	private function dispatchToBerichtenbox(
		array $message,
		string $messageId,
		string $bsnPlain,
		string $bodyHash,
	): void {
		                                                                  
		  
		                                                                        
		                                                                        
		                                                                       
		                                                                 
		                                                                    
		                                                                        
		                                                             
		$cert = $this->appConfig->getValueString(Application::APP_ID, 'pki_cert', '');
		$key = $this->appConfig->getValueString(Application::APP_ID, 'pki_key', '');
		if ($cert === '' || $key === '') {
			$this->logger->error(
				'Pipelinq Berichtenbox: refusing to dispatch — tenant PKI-overheid cert/key is not configured, '
				. 'so the Logius request would be sent with an unsigned placeholder signature',
				['messageId' => $messageId]
			);
			$this->markFailedOrRetry(
				message: $message,
				reason: 'Tenant PKI-overheid certificate or key is not configured.',
				bodyHash: $bodyHash
			);
			return;
		}

		try {
			$message['bsnPlain'] = $bsnPlain;
			$response = $this->logius->sendMessage($message, $cert, $key);
		} catch (\Throwable $e) {
			$this->markFailedOrRetry(message: $message, reason: $e->getMessage(), bodyHash: $bodyHash);
			return;
		}

		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		unset($message['bsnPlain']);
		$message['sentToBerichtenboxAt'] = $now->format(DATE_ATOM);
		$message['logiusMessageId'] = $response['logiusMessageId'];
		$message['deliveryStatus'] = 'sent';
		$message['mailboxAvailable'] = true;
		$message['failureReason'] = '';
		$this->saveMessage(payload: $message);

		$this->auditLogger->logSent(
			messageId: $messageId,
			logiusMessageId: $response['logiusMessageId'],
			payloadHash: $bodyHash
		);
	}                              

	   
                                                              
   
                                          
                                  
                                            
                                                       
   
                
    
	private function dispatchFallback(
		array $message,
		string $messageId,
		string $bodyHash,
		bool $optedOut,
	): void {
		$reason = 'no-mailbox';
		if ($optedOut === true) {
			$reason = 'opted-out';
		}

		$burgerEmail = (string)($message['burgerEmail'] ?? '');

		if ($burgerEmail === '') {
			$this->logger->warning(
				'Berichtenbox fallback skipped: no email available.',
				['messageId' => $messageId, 'reason' => $reason]
			);
			if ($optedOut === true) {
				$message['deliveryStatus'] = 'opted-out';
				$this->saveMessage(payload: $message);
				$this->auditLogger->logOptedOut(
					messageId: $messageId,
					payloadHash: $bodyHash
				);
			}

			return;
		}

		try {
			$accepted = $this->emailFallback->send($message, $burgerEmail, false);
			if ($accepted === false && $this->emailFallback->lastRefusal() !== null) {
				                                                               
				return;
			}
		} catch (\Throwable $e) {
			$this->markFailedOrRetry(message: $message, reason: $e->getMessage(), bodyHash: $bodyHash);
			return;
		}

		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$message['deliveryStatus'] = 'fallback-emailed';
		$message['fallbackTriggeredAt'] = $now->format(DATE_ATOM);
		$message['fallbackEmail'] = $burgerEmail;
		$message['fallbackSentAt'] = $now->format(DATE_ATOM);
		$this->saveMessage(payload: $message);

		$this->auditLogger->logFallback(
			messageId: $messageId,
			reason: $reason,
			payloadHash: $bodyHash
		);
	}                        

	   
                                                                       
                                  
   
                                          
                                         
                                            
   
                
    
	private function markFailedOrRetry(array $message, string $reason, string $bodyHash): void {
		$retryCount = (int)($message['retryCount'] ?? 0);
		$messageId = (string)($message['uuid'] ?? '');
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

		if ($retryCount >= self::MAX_RETRIES) {
			$message['deliveryStatus'] = 'failed';
			$message['failureReason'] = $reason;
			$this->saveMessage(payload: $message);
			$this->auditLogger->logFailed(
				messageId: $messageId,
				reason: $reason,
				payloadHash: $bodyHash
			);
			return;
		}

		$delay = (self::RETRY_BACKOFF_SECONDS[$retryCount] ?? 14400);
		$message['retryCount'] = ($retryCount + 1);
		$message['deliveryStatus'] = 'queued';
		$message['failureReason'] = $reason;
		$message['nextRetryAt'] = $now->modify('+' . $delay . ' seconds')->format(DATE_ATOM);
		$this->saveMessage(payload: $message);

		$this->auditLogger->logFailed(
			messageId: $messageId,
			reason: $reason . ' (retry ' . $message['retryCount'] . '/' . self::MAX_RETRIES . ')',
			payloadHash: $bodyHash
		);
	}                         

	   
                                               
   
                                   
   
                             
    
	private function findDispatchableMessages(int $limit): array {
		[$register, $schema] = $this->messageConfig();
		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'deliveryStatus' => 'queued',
					],
					'limit' => $limit,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'BerichtenboxService findDispatchable failed.',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$result = [];
		foreach ($rows as $row) {
			$data = $this->toArray(row: $row);
			if ($data === null) {
				continue;
			}

			$nextRetryAt = ($data['nextRetryAt'] ?? '');
			if (is_string($nextRetryAt) === true && $nextRetryAt !== '') {
				try {
					if (new DateTimeImmutable($nextRetryAt) > $now) {
						continue;
					}
				} catch (\Throwable) {
					                                 
				}
			}

			$result[] = $data;
		}

		return $result;
	}                                

	   
                                                          
   
                             
    
	private function findUnreadSentMessages(): array {
		[$register, $schema] = $this->messageConfig();
		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'deliveryStatus' => 'sent',
					],
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'BerichtenboxService findUnreadSent failed.',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		$unread = [];
		foreach ($rows as $row) {
			$data = $this->toArray(row: $row);
			if ($data === null) {
				continue;
			}

			if (($data['readAt'] ?? '') !== '' && $data['readAt'] !== null) {
				continue;
			}

			$unread[] = $data;
		}

		return $unread;
	}                              

	   
                                      
   
                                             
   
                      
    
	private function findMessageByLogiusId(string $logiusMessageId): ?array {
		[$register, $schema] = $this->messageConfig();
		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'logiusMessageId' => $logiusMessageId,
					],
					'limit' => 1,
				]
			);
		} catch (\Throwable $e) {
			return null;
		}

		foreach ($rows as $row) {
			return $this->toArray(row: $row);
		}

		return null;
	}                             

	   
                           
   
                                     
   
                      
    
	private function findMessageByUuid(string $uuid): ?array {
		[$register, $schema] = $this->messageConfig();
		try {
			$row = $this->getObjectService()->find(id: $uuid, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			return null;
		}

		return $this->toArray(row: $row);
	}                         

	   
                                                              
   
                                          
                                 
                                     
   
                      
    
	private function findTemplate(string $caseType, string $status, string $language): ?array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(
			Application::APP_ID,
			'berichtenboxTemplate_schema',
			''
		);
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'caseType' => $caseType,
						'status' => $status,
						'language' => $language,
					],
					'limit' => 1,
				]
			);
		} catch (\Throwable $e) {
			return null;
		}

		foreach ($rows as $row) {
			return $this->toArray(row: $row);
		}

		return null;
	}                    

	   
                                                                  
   
                                                                         
                                                                               
                                                                                
   
                 
    
	private function fallbackTemplate(): array {
		return [
			'id' => 'fallback',
			'subject' => 'Status update voor zaak {{zaakId}}',
			'body' => '<p>Geachte burger,</p>'
				. '<p>De status van uw zaak {{zaakId}} is nu: <strong>{{status}}</strong>.</p>'
				. '<p>Met vriendelijke groet,<br/>{{gemeente}}</p>',
			'requiresDeepLink' => false,
		];
	}                        

	   
                                                       
   
                                        
                                   
   
                                 
    
	private function decryptBsn(array $row, string $tenantId): string {
		$ciphertext = (string)($row['bsn'] ?? '');
		if ($ciphertext === '') {
			return '';
		}

		return $this->encryption->decrypt($ciphertext, $tenantId);
	}                  

	   
                                                                
                  
   
                                                                    
                                                                            
                                                                        
                                                             
                                               
   
                                                    
                                      
   
                                      
   
                                                                                
    
	private function createContactmomentFromReply(array $parent, array $reply): string {
		if ($this->ticketService->isConfigured() === false) {
			throw new RuntimeException('Contactmoment register or schema not configured.');
		}

		$payload = [
			'title' => 'Re: ' . ((string)($parent['subject'] ?? '')),
			'description' => (string)($reply['bodyText'] ?? ''),
			'channel' => 'berichtenbox',
			                                                            
			                                                             
			                                                        
			'direction' => 'inbound',
			'outcome' => 'opvolging-nodig',
			'caseId' => (string)($parent['caseId'] ?? ''),
			'parentMessageId' => (string)($parent['uuid'] ?? ''),
			'berichtenboxReplyId' => (string)($reply['uuid'] ?? ''),
		];

		$saved = $this->ticketService->save(
			ticketType: TicketService::TYPE_CONTACTMOMENT,
			payload: $payload,
			uuid: null
		);
		$savedArray = $this->toArray(row: $saved);
		$uuid = (string)($savedArray['uuid'] ?? $savedArray['id'] ?? '');

		                                                                       
		try {
			if ($this->container->has('OCA\\Pipelinq\\Service\\QueueRoutingService') === true) {
				$router = $this->container->get('OCA\\Pipelinq\\Service\\QueueRoutingService');
				if (method_exists($router, 'route') === true) {
					$router->route($savedArray);
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Berichtenbox reply routing failed.',
				['exception' => $e->getMessage()]
			);
		}

		return $uuid;
	}                                    

	   
                                  
   
                                  
   
                 
    
	private function saveMessage(array $payload): array {
		[$register, $schema] = $this->messageConfig();
		$saved = $this->getObjectService()->saveObject(
			object: $payload,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: ($payload['uuid'] ?? null)
		);
		return ($this->toArray(row: $saved) ?? $payload);
	}                   

	   
                                
   
                                  
   
                 
    
	private function saveReply(array $payload): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(
			Application::APP_ID,
			'berichtenboxReply_schema',
			''
		);
		if ($register === '' || $schema === '') {
			throw new RuntimeException('BerichtenboxReply register or schema not configured.');
		}

		$saved = $this->getObjectService()->saveObject(
			object: $payload,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: ($payload['uuid'] ?? null)
		);
		return ($this->toArray(row: $saved) ?? $payload);
	}                 

	   
                                             
   
                                       
    
	private function messageConfig(): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(
			Application::APP_ID,
			'berichtenboxMessage_schema',
			''
		);
		if ($register === '' || $schema === '') {
			throw new RuntimeException('BerichtenboxMessage register or schema not configured.');
		}

		return [$register, $schema];
	}                     

	   
                                          
   
                  
    
	private function resolveTenantId(): string {
		$tenant = $this->appConfig->getValueString(Application::APP_ID, 'tenant_id', '');
		if ($tenant === '') {
			return self::DEFAULT_TENANT_ID;
		}

		return $tenant;
	}                       

	   
                         
   
                                                             
    
	private function getObjectService(): \OCA\OpenRegister\Contract\ObjectServiceInterface {
		                                                                 
		                                                              
		return $this->objectService;
	}                        

	   
                                 
   
                          
   
                      
    
	private function toArray(mixed $row): ?array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true) {
			if (method_exists($row, 'jsonSerialize') === true) {
				$serialized = $row->jsonSerialize();
				if (is_array($serialized) === true) {
					return $serialized;
				}
			}

			if (method_exists($row, 'getObject') === true) {
				$inner = $row->getObject();
				if (is_array($inner) === true) {
					return $inner;
				}
			}
		}

		return null;
	}               
}           
