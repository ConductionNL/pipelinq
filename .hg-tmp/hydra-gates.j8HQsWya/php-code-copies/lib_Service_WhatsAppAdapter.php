<?php

   
                            
  
                                                                
                                                           
                                                                  
                                                                    
                                                             
                   
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Provider\PermanentSmsProviderException;
use OCA\Pipelinq\Service\Provider\TransientSmsProviderException;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                         
  
                       
                                                                
                                                
                                                           
                                          
                                                                
                               
  
                                                                    
                                                                  
                       
                                                                         
                                                                      
                                                             
                                                                   
                                                   
                                                                  
                                                                    
                                                                  
                           
                                                                      
                                                            
                                                                     
                                                                          
                                                                        
                                                                         
                           
  
                                                                   
   
class WhatsAppAdapter {
	use WritesAsMessagingAccount;

	   
                                                                 
                                                                        
                                                            
   
                            
    
	private const LOOKUP_SCOPE = ['_rbac' => false, '_multitenancy' => false];

	   
                          
    
	private const DEFAULT_REGISTER_SLUG = 'pipelinq';

	   
                                
    
	private const DEFAULT_MESSAGE_SCHEMA_SLUG = 'channelMessage';

	   
                                     
    
	private const DEFAULT_CONVERSATION_SCHEMA_SLUG = 'channelConversation';

	   
                                
    
	private const DEFAULT_CONTACT_SCHEMA_SLUG = 'contact';

	   
                                        
    
	private const DEFAULT_TEMPLATE_SCHEMA_SLUG = 'messageTemplate';

	   
                                                                 
    
	private const SESSION_WINDOW_SECONDS = 86400;

	   
                                  
    
	public const STATUS_SENT = 'sent';
	public const STATUS_CONSENT_MISSING = 'consentMissing';
	public const STATUS_BUDGET_EXCEEDED = 'budgetExceeded';
	public const STATUS_NO_PROVIDER = 'noProviderAvailable';
	public const STATUS_SESSION_WINDOW_EXPIRED = 'sessionWindowExpired';
	public const STATUS_TEMPLATE_NOT_FOUND = 'templateNotFound';
	public const STATUS_TEMPLATE_NOT_APPROVED = 'templateNotApproved';
	public const STATUS_TEMPLATE_MISMATCH = 'templateParameterMismatch';
	public const STATUS_FAILED = 'failed';

	   
                
   
                                                      
                                            
                                                                      
                                                                   
                                                       
                                                    
                                                                        
                                          
                                                                                  
   
                                                                    
    
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private ChannelProviderRepository $providerRepo,
		private WhatsAppProviderClient $providerClient,
		private ConsentService $consentService,
		private BudgetService $budgetService,
		private NotificationService $notificationService,
		private LoggerInterface $logger,
		private PhoneNormaliser $phoneNormaliser,
	) {
	}                   

	   
                            
   
                  
                    
                                             
                                                            
                                                                  
            
                                                               
                                                 
                                                                 
                                                          
   
                                                     
                                                                     
                                                                            
                                                                         
                                                                      
                                                                              
   
                  
                       
                           
                                   
                            
                        
                       
                    
                      
                   
   
                                                                    
                                                                                                       
    
	public function send(
		array $contact,
		string $body,
		?string $templateId = null,
		array $parameters = [],
		array $context = [],
	): array {
		$contactId = $this->extractId(payload: $contact);
		$toNumber = $this->extractPhone(contact: $contact);

		if ($toNumber === '') {
			return ['status' => self::STATUS_FAILED, 'error' => 'no recipient phone number'];
		}

		$isBusinessInitiated = (($templateId ?? '') !== '');
		$inReplyTo = null;
		if ($isBusinessInitiated === false) {
			                                                               
			                                                          
			                                                       
			$inReplyTo = $this->replyTarget(contactId: $contactId, explicit: (string)($context['inReplyTo'] ?? ''));
		}

		if ($this->consentForSend(contactId: $contactId, businessInitiated: $isBusinessInitiated, address: $toNumber, inReplyTo: $inReplyTo) === false) {
			return ['status' => self::STATUS_CONSENT_MISSING];
		}

		$language = (string)$this->appConfig->getValueString(Application::APP_ID, 'whatsapp.default_language', 'nl');
		$context = $this->resolveSendContext(
			templateId: $templateId,
			parameters: $parameters,
			language: $language,
			contactId: $contactId
		);
		if ($context['error'] !== null) {
			return $context['error'];
		}

		$template = $context['template'];
		$templateName = $context['templateName'];
		$language = $context['language'];

		$tenantId = $this->getTenantId();
		$ordered = $this->orderedProvidersForSend(templateProviderId: (string)($template['providerId'] ?? ''));

		if ($ordered === []) {
			return ['status' => self::STATUS_NO_PROVIDER];
		}

		foreach ($ordered as $row) {
			$outcome = $this->attemptProviderSend(
				row: $row,
				tenantId: $tenantId,
				contactId: $contactId,
				toNumber: $toNumber,
				body: $body,
				templateName: $templateName,
				language: $language,
				parameters: $parameters,
				template: $template,
				context: $context,
			);

			if ($outcome !== null) {
				return $outcome;
			}
		}             

		return $this->persistFailureAndAlert(
			contactId: $contactId,
			providerId: '',
			body: $this->bodyForSend(templateName: $templateName, body: $body),
			templateId: $this->templateIdForSend(template: $template),
			error: 'all WhatsApp providers returned transient errors',
		);
	}            

	   
                                                                         
                                                     
   
                                                                     
                                                                    
                                                       
   
                                                                            
                                                                         
                                                          
                                                                  
   
                  
                                         
                                             
                              
                         
                                                                    
    
	private function resolveSendContext(?string $templateId, array $parameters, string $language, string $contactId): array {
		$isTemplateSend = (($templateId ?? '') !== '');
		if ($isTemplateSend === true) {
			$resolved = $this->resolveTemplateForSend(
				templateId: (string)$templateId,
				parameters: $parameters,
				language: $language
			);
			if ($resolved['ok'] === false) {
				return ['error' => $resolved['error']];
			}

			return [
				'error' => null,
				'template' => $resolved['template'],
				'templateName' => $resolved['templateName'],
				'language' => $resolved['language'],
			];
		}

		                                           
		if ($this->isWithinSessionWindow(contactId: $contactId) === false) {
			return ['error' => ['status' => self::STATUS_SESSION_WINDOW_EXPIRED]];
		}

		return [
			'error' => null,
			'template' => null,
			'templateName' => '',
			'language' => $language,
		];
	}                          

	   
                                     
   
                                                                        
                                                                      
                                                                       
                                                                         
                                                       
   
                                          
                                                                                        
                                                          
                                                                               
   
                                                
   
                                                                                                       
                                                                                                                                                                   
    
	private function consentForSend(string $contactId, bool $businessInitiated, string $address = '', ?string $inReplyTo = null): bool {
		if ($businessInitiated === true) {
			return $this->consentService->canSendBusinessInitiated(contactId: $contactId, channel: 'whatsapp', address: $address);
		}

		return $this->consentService->canSend(contactId: $contactId, channel: 'whatsapp', address: $address, inReplyTo: $inReplyTo);
	}                      

	   
                                                                            
   
                                                                       
                                                                        
                                                  
   
                                          
                                                                                
   
                                                                          
   
                                                                                                                                                                   
    
	private function replyTarget(string $contactId, string $explicit): ?string {
		$inWindow = $this->inboundInWindow(contactId: $contactId);
		if ($explicit !== '') {
			if (isset($inWindow[$explicit]) === true) {
				return $explicit;
			}

			return null;
		}

		if ($inWindow === []) {
			return null;
		}

		arsort($inWindow);
		return (string)array_key_first($inWindow);
	}                   

	   
                                                                          
           
   
                                                                          
                                                                            
                                                                       
                                     
   
                                          
   
                                                          
   
                                                                                                       
    
	private function inboundInWindow(string $contactId): array {
		$objectService = $this->getObjectService();
		if ($contactId === '' || $objectService === null) {
			return [];
		}

		try {
			$rows = $objectService->findAll(
				config: [
					'filters' => [
						'contactId' => $contactId,
						'channel' => 'whatsapp',
						'direction' => 'inbound',
						'register' => $this->getRegisterSlug(),
						'schema' => $this->resolveSchemaSlug(key: 'message_schema', default: self::DEFAULT_MESSAGE_SCHEMA_SLUG),
					],
				]
			);
		} catch (Throwable $e) {
			return [];
		}

		$found = [];
		$lastStop = 0;
		foreach ((array)$rows as $raw) {
			$row = $this->toArray(value: $raw);
			$sentAt = (int)strtotime((string)($row['sentAt'] ?? ''));
			$messageId = $this->extractId(payload: $row);
			$isOwn = ((string)($row['contactId'] ?? $contactId) === $contactId);
			if ($messageId !== '' && $isOwn === true && (time() - $sentAt) < self::SESSION_WINDOW_SECONDS) {
				$found[$messageId] = $sentAt;
				if ($this->consentService->isOptOutKeyword(body: (string)($row['body'] ?? '')) === true) {
					$lastStop = max($lastStop, $sentAt);
				}
			}
		}

		return array_filter($found, static fn (int $sentAt): bool => $sentAt > $lastStop);
	}                       

	   
                                       
   
                                                                     
                                                                      
                               
   
                                                 
                                                                         
                                                      
                                                          
   
                  
                 
                                     
                                        
                              
                         
                         
    
	private function resolveTemplateForSend(string $templateId, array $parameters, string $language): array {
		$template = $this->loadTemplate(id: $templateId);
		if ($template === null) {
			return ['ok' => false, 'error' => ['status' => self::STATUS_TEMPLATE_NOT_FOUND]];
		}

		$status = (string)($template['status'] ?? '');
		if ($status !== 'approved') {
			return ['ok' => false, 'error' => ['status' => self::STATUS_TEMPLATE_NOT_APPROVED]];
		}

		$templateName = (string)($template['externalId'] ?? '');
		$language = (string)($template['language'] ?? $language);

		$expected = $this->parseTemplatePlaceholders(templateBody: (string)($template['body'] ?? ''));
		$given = count($parameters);
		if ($expected !== $given) {
			return [
				'ok' => false,
				'error' => [
					'status' => self::STATUS_TEMPLATE_MISMATCH,
					'expected' => $expected,
					'given' => $given,
				],
			];
		}

		return [
			'ok' => true,
			'template' => $template,
			'templateName' => $templateName,
			'language' => $language,
		];
	}                              

	   
                                            
   
                                                                          
                                                                          
                                                                        
                                                                           
                                                                  
                                    
   
                                                  
                                      
                                          
                                                   
                                       
                                                                                      
                                              
                                                                         
                                                                                            
                                                                             
   
                                                                                         
   
                                                                              
                                                                              
                                                                               
                                             
    
	private function attemptProviderSend(
		array $row,
		string $tenantId,
		string $contactId,
		string $toNumber,
		string $body,
		string $templateName,
		string $language,
		array $parameters,
		?array $template,
		array $context = [],
	): ?array {
		$providerId = $this->extractId(payload: $row);

		if ($this->budgetService->canSend(tenantId: $tenantId, providerId: $providerId) === false) {
			                                                               
			                 
			return null;
		}

		try {
			$result = match (true) {
				$templateName !== '' => $this->providerClient->sendTemplate(
					channelProvider: $row,
					phoneNumber: $toNumber,
					templateName: $templateName,
					language: $language,
					parameters: $parameters,
				),
				default => $this->providerClient->sendFreeForm(
					channelProvider: $row,
					phoneNumber: $toNumber,
					body: $body,
				),
			};
		} catch (TransientSmsProviderException $e) {
			$this->logger->warning(
				'WhatsAppAdapter.send: transient — failing over',
				['providerId' => $providerId, 'message' => $e->getMessage()]
			);
			return null;
		} catch (PermanentSmsProviderException $e) {
			$this->logger->warning(
				'WhatsAppAdapter.send: permanent provider failure',
				['providerId' => $providerId, 'message' => $e->getMessage()]
			);
			return $this->persistFailureAndAlert(
				contactId: $contactId,
				providerId: $providerId,
				body: $this->bodyForSend(templateName: $templateName, body: $body),
				templateId: $this->templateIdForSend(template: $template),
				error: $e->getMessage(),
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'WhatsAppAdapter.send: unexpected — failing over',
				['providerId' => $providerId, 'message' => $e->getMessage()]
			);
			return null;
		}         

		$persisted = $this->persistOutbound(
			contactId: $contactId,
			providerId: $providerId,
			body: $this->bodyForSend(templateName: $templateName, body: $body),
			externalMessageId: $result['externalMessageId'],
			templateId: $this->templateIdForSend(template: $template),
			templateParameters: $parameters,
		);

		$this->budgetService->recordSend(tenantId: $tenantId, providerId: $providerId);

		$messageId = $this->extractId(payload: ($persisted ?? []));
		$this->auditOutbound(
			contactId: $contactId,
			messageId: $messageId,
			conversationId: (string)(($persisted ?? [])['conversationId'] ?? ''),
			body: $this->bodyForSend(templateName: $templateName, body: $body),
			context: $context,
		);

		return [
			'status' => self::STATUS_SENT,
			'messageId' => $messageId,
			'externalMessageId' => $result['externalMessageId'],
			'providerId' => $providerId,
			'vendor' => $result['vendor'],
		];
	}                           

	   
                                                          
   
                                                                         
                                                                     
                                                                   
                                                                          
   
                                          
                                                    
                                                    
                                                       
                                                                            
   
                
   
                                                                                                                    
    
	private function auditOutbound(
		string $contactId,
		string $messageId,
		string $conversationId,
		string $body,
		array $context,
	): void {
		$auditor = $this->contactmoments();
		if ($auditor === null) {
			return;
		}

		$auditor->recordOutboundMessage(
			channel: 'chat',
			subject: 'Outbound WhatsApp',
			summary: $body,
			channelMetadata: [
				'platform' => 'whatsapp',
				'direction' => 'outbound',
				'messageId' => $messageId,
				'conversationId' => $conversationId,
				'contactId' => $contactId,
			],
			clientId: (string)($context['clientId'] ?? ''),
			agent: (string)($context['agent'] ?? ''),
		);
	}                     

	   
                                                                        
   
                                                          
    
	private function contactmoments(): ?ContactmomentService {
		try {
			$service = $this->container->get('OCA\\Pipelinq\\Service\\ContactmomentService');
		} catch (Throwable $e) {
			return null;
		}

		if (($service instanceof ContactmomentService) === false) {
			return null;
		}

		return $service;
	}                      

	   
                                                                         
                             
   
                                                                                      
                                       
   
                  
    
	private function bodyForSend(string $templateName, string $body): string {
		if ($templateName !== '') {
			return sprintf('[template:%s]', $templateName);
		}

		return $body;
	}                   

	   
                                                                            
   
                                                                                            
   
                  
    
	private function templateIdForSend(?array $template): string {
		if ($template !== null) {
			return $this->extractId(payload: $template);
		}

		return '';
	}                         

	   
                                             
   
                                                                    
                                                                      
                                                                       
                                                                         
                                                                        
   
                                            
                                                    
                                                   
   
                  
                       
                           
                                
                             
                                 
                              
                      
                       
   
                                                                    
                                                                                                       
    
	public function handleInboundWebhook(string $rawBody, string $signature, string $providerId): array {
		                                                                     
		                                                                      
		                                                           
		$row = $this->providerRepo->findByIdForWebhook(id: $providerId);
		if ($row === null) {
			return ['status' => 'providerUnknown'];
		}

		if ($this->providerClient->verifySignature(channelProvider: $row, rawBody: $rawBody, signature: $signature) === false) {
			$this->logger->warning(
				'WhatsAppAdapter.handleInboundWebhook: invalid signature',
				['providerId' => $providerId]
			);
			return ['status' => 'invalidSignature'];
		}

		$extracted = $this->extractMetaMessage(rawBody: $rawBody);
		$from = $this->phoneNormaliser->normaliseInbound(rawNumber: $extracted['from']);

		if ($from === '') {
			return ['status' => 'invalidPayload'];
		}

		return $this->asMessagingAccount(
			writes: fn (): array => $this->ingestInbound(providerRow: $row, providerId: $providerId, extracted: $extracted, from: $from)
		);
	}                            

	   
                                                                     
                      
   
                                                          
                                                                  
                                                                                
                                                             
   
                                                  
    
	private function ingestInbound(array $providerRow, string $providerId, array $extracted, string $from): array {
		$body = (string)$extracted['body'];
		$contactId = $this->findContactByPhone(phone: $from);

		                                                                      
		                                                 
		$conversationId = '';
		if ($contactId !== '') {
			$conversationId = $this->findOrOpenConversation(
				contactId: $contactId,
				providerId: $providerId,
				channel: 'whatsapp',
				scope: self::LOOKUP_SCOPE,
			);
		}

		$windowExpiresAt = gmdate('Y-m-d\TH:i:s\Z', (time() + self::SESSION_WINDOW_SECONDS));

		$persisted = $this->persistMessage(
			payload: [
				'contactId' => $contactId,
				'conversationId' => $conversationId,
				'channel' => 'whatsapp',
				'direction' => 'inbound',
				'body' => $body,
				'providerId' => $providerId,
				'deliveryStatus' => 'delivered',
				'sentAt' => $this->nowIso(),
				'windowExpiresAt' => $windowExpiresAt,
				'metadata' => ['raw' => $extracted],
			],
		);

		$this->touchConversationWindow(conversationId: $conversationId, windowExpiresAt: $windowExpiresAt);

		return $this->actOnInbound(
			result: [
				'status' => 'received',
				'messageId' => $this->extractId(payload: $persisted ?? []),
				'conversationId' => $conversationId,
				'unknownSender' => ($contactId === ''),
				'optOutRecorded' => false,
			],
			contactId: $contactId,
			from: $from,
			body: $body,
			providerRow: $providerRow,
		);
	}                     

	   
                                                                       
                                                                       
                                                                         
                         
   
                                                                
                                                                       
                                        
                                     
                                                          
   
                                                        
    
	private function actOnInbound(array $result, string $contactId, string $from, string $body, array $providerRow): array {
		if ($this->consentService->isOptOutKeyword(body: $body) === true) {
			$result['optOutRecorded'] = $this->consentService->recordOptOut(
				contactId: $contactId,
				channel: 'whatsapp',
				source: 'keyword-stop',
				evidence: sprintf('Inbound WhatsApp body "%s" matched STOP keyword', $body),
				address: $from,
			) !== null;
			if ($result['optOutRecorded'] === true) {
				                                                        
				                                                      
				                          
				$this->sendOptOutAcknowledgement(phone: $from, providerRow: $providerRow);
			}

			return $result;
		}

		if ($contactId === '') {
			$result['contactMomentId'] = (string)$this->contactmoments()?->recordInboundFromUnknownNumber(
				platform: 'whatsapp',
				phone: $from,
				body: $body,
				messageId: (string)$result['messageId'],
			);
			return $result;
		}

		if ($this->consentService->isOptInKeyword(body: $body) === true) {
			$this->consentService->recordOptIn(
				contactId: $contactId,
				channel: 'whatsapp',
				source: 'chat-reply',
				evidence: sprintf('Inbound WhatsApp body "%s" matched opt-in keyword', $body),
				address: $from,
			);
		}

		return $result;
	}                    

	   
                                                
   
                                              
   
                                               
   
                                                                    
    
	public function parseTemplatePlaceholders(string $templateBody): int {
		$matches = [];
		preg_match_all('/\{\{(\d+)\}\}/u', $templateBody, $matches);
		$unique = array_unique(array_map('intval', $matches[1]));
		return count($unique);
	}                                 

	   
                                                         
   
                                          
   
                                                               
   
                                                                    
    
	public function isWithinSessionWindow(string $contactId): bool {
		if ($contactId === '') {
			return false;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return false;
		}

		try {
			$rows = $objectService->findAll(
				config: [
					'filters' => [
						'contactId' => $contactId,
						'channel' => 'whatsapp',
						'direction' => 'inbound',
						'register' => $this->getRegisterSlug(),
						'schema' => $this->resolveSchemaSlug(
							key: 'message_schema',
							default: self::DEFAULT_MESSAGE_SCHEMA_SLUG
						),
					],
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'WhatsAppAdapter.isWithinSessionWindow: findAll failed',
				['contactId' => $contactId, 'exception' => $e->getMessage()]
			);
			return false;
		}         

		if (is_array($rows) === false || $rows === []) {
			return false;
		}

		$latestTimestamp = $this->latestInboundTimestamp(rows: $rows);
		if ($latestTimestamp === 0) {
			return false;
		}

		return ((time() - $latestTimestamp) < self::SESSION_WINDOW_SECONDS);
	}                             

	   
                                                                   
   
                                                                        
                                                                 
                                                
   
                                                        
   
                                                             
    
	private function latestInboundTimestamp(array $rows): int {
		$latestTimestamp = 0;
		foreach ($rows as $raw) {
			$arr = $this->toArray(value: $raw);
			$timestamp = strtotime((string)($arr['sentAt'] ?? ''));
			if ($timestamp === false) {
				$timestamp = 0;
			}

			if ($timestamp > $latestTimestamp) {
				$latestTimestamp = $timestamp;
			}
		}

		return $latestTimestamp;
	}                              

	   
                                       
   
                                                           
                                                               
                                                              
                                                              
   
                                                                
                                                          
   
                                                           
    
	private function orderedProvidersForSend(string $templateProviderId): array {
		$cloud = $this->providerRepo->listActive(kind: 'whatsapp-cloud-api');
		$bsp = $this->providerRepo->listActive(kind: 'whatsapp-bsp');
		$merged = array_merge($cloud, $bsp);

		if ($templateProviderId === '') {
			return $merged;
		}

		$pinned = [];
		$rest = [];
		foreach ($merged as $row) {
			if ($this->extractId(payload: $row) === $templateProviderId) {
				$pinned[] = $row;
				continue;
			}

			$rest[] = $row;
		}

		return array_merge($pinned, $rest);
	}                               

	   
                                    
   
                                          
                                            
                                                  
                                                     
                                           
   
                                                                              
    
	private function persistFailureAndAlert(
		string $contactId,
		string $providerId,
		string $body,
		string $templateId,
		string $error,
	): array {
		$conversationId = $this->findOrOpenConversation(
			contactId: $contactId,
			providerId: $providerId,
			channel: 'whatsapp',
		);

		$this->persistMessage(
			payload: [
				'contactId' => $contactId,
				'conversationId' => $conversationId,
				'channel' => 'whatsapp',
				'direction' => 'outbound',
				'body' => $body,
				'providerId' => $providerId,
				'templateId' => $templateId,
				'deliveryStatus' => 'failed',
				'sentAt' => $this->nowIso(),
				'metadata' => ['error' => $error],
			],
		);

		try {
			$this->notificationService->sendNotification(
				userId: 'admin',
				subject: 'messaging_whatsapp_send_failed',
				parameters: ['contactId' => $contactId, 'providerId' => $providerId, 'error' => $error],
				objectType: 'message',
				objectId: $contactId,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'WhatsAppAdapter.persistFailureAndAlert: notification failed',
				['exception' => $e->getMessage()]
			);
		}

		return [
			'status' => self::STATUS_FAILED,
			'error' => $error,
			'providerId' => $providerId,
		];
	}                              

	   
                                    
   
                                          
                                            
                             
                                                 
                                                     
                                                                                 
   
                                                
    
	private function persistOutbound(
		string $contactId,
		string $providerId,
		string $body,
		string $externalMessageId,
		string $templateId,
		array $templateParameters,
	): ?array {
		$conversationId = $this->findOrOpenConversation(
			contactId: $contactId,
			providerId: $providerId,
			channel: 'whatsapp',
		);

		return $this->persistMessage(
			payload: [
				'contactId' => $contactId,
				'conversationId' => $conversationId,
				'channel' => 'whatsapp',
				'direction' => 'outbound',
				'body' => $body,
				'providerId' => $providerId,
				'externalMessageId' => $externalMessageId,
				'templateId' => $templateId,
				'templateParameters' => array_values($templateParameters),
				'deliveryStatus' => 'queued',
				'sentAt' => $this->nowIso(),
			],
		);
	}                       

	   
                                                                      
   
                                                                        
                                                                            
                                       
   
                                         
                                                          
   
                
    
	private function sendOptOutAcknowledgement(string $phone, array $providerRow): void {
		try {
			$body = (string)$this->appConfig->getValueString(
				Application::APP_ID,
				'whatsapp.opt_out_ack_body',
				'Bedankt. U bent uitgeschreven en ontvangt geen verdere berichten via dit kanaal.'
			);

			                                                          
			                                                          
			                                         
			$this->providerClient->sendFreeForm(
				channelProvider: $providerRow,
				phoneNumber: $phone,
				body: $body,
			);
		} catch (Throwable $e) {
			$this->logger->info(
				'WhatsAppAdapter.sendOptOutAcknowledgement: best-effort failed',
				['exception' => $e->getMessage()]
			);
		}
	}                                 

	   
                                                                
                    
   
                                    
   
                                                                                   
    
	private function extractMetaMessage(string $rawBody): array {
		$decoded = json_decode($rawBody, true);
		if (is_array($decoded) === false) {
			return ['from' => '', 'body' => '', 'raw' => []];
		}

		                                                        
		$entry = ($decoded['entry'][0] ?? []);
		$change = ($entry['changes'][0] ?? []);
		$value = ($change['value'] ?? []);
		$message = ($value['messages'][0] ?? null);

		if (is_array($message) === false) {
			                                                 
			return [
				'from' => (string)($decoded['from'] ?? ''),
				'body' => (string)(($decoded['body'] ?? $decoded['text'] ?? '')),
				'raw' => $decoded,
			];
		}

		$body = (string)($message['text']['body'] ?? ($message['body'] ?? ''));
		return [
			'from' => (string)($message['from'] ?? ''),
			'body' => $body,
			'raw' => $decoded,
		];
	}                          

	   
                                                                     
   
                                          
                                            
                                   
                                                                                                                                            
   
                                     
    
	private function findOrOpenConversation(string $contactId, string $providerId, string $channel, array $scope = []): string {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return '';
		}

		$schema = $this->resolveSchemaSlug(key: 'conversation_schema', default: self::DEFAULT_CONVERSATION_SCHEMA_SLUG);

		try {
			$rows = $objectService->findAll(
				config: [
					'filters' => [
						'contactId' => $contactId,
						'providerId' => $providerId,
						'channel' => $channel,
						'status' => 'open',
						'register' => $this->getRegisterSlug(),
						'schema' => $schema,
					],
				],
				_rbac: ($scope['_rbac'] ?? true),
				_multitenancy: ($scope['_multitenancy'] ?? true),
			);
		} catch (Throwable $e) {
			$rows = [];
		}

		if (is_array($rows) === true && $rows !== []) {
			return $this->extractId(payload: $this->toArray(value: $rows[0]));
		}

		try {
			$saved = $objectService->saveObject(
				object: [
					'contactId' => $contactId,
					'providerId' => $providerId,
					'channel' => $channel,
					'status' => 'open',
				],
				register: $this->getRegisterSlug(),
				schema: $schema,
				uuid: null,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'WhatsAppAdapter.findOrOpenConversation: open failed',
				['exception' => $e->getMessage()]
			);
			return '';
		}

		return $this->extractId(payload: $this->toArray(value: $saved));
	}                              

	   
                                                               
   
                                                    
                                                   
   
                                                                            
                                                 
   
                
    
	private function touchConversationWindow(string $conversationId, string $windowExpiresAt): void {
		if ($conversationId === '') {
			return;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return;
		}

		$schema = $this->resolveSchemaSlug(key: 'conversation_schema', default: self::DEFAULT_CONVERSATION_SCHEMA_SLUG);

		try {
			$existing = $objectService->find(
				id: $conversationId,
				register: $this->getRegisterSlug(),
				schema: $schema,
				_rbac: self::LOOKUP_SCOPE['_rbac'],
				_multitenancy: self::LOOKUP_SCOPE['_multitenancy'],
			);
		} catch (Throwable $e) {
			return;
		}

		if ($existing === null) {
			return;
		}

		$payload = $this->toArray(value: $existing);
		$payload['lastInboundAt'] = $this->nowIso();
		$payload['windowExpiresAt'] = $windowExpiresAt;

		try {
			$objectService->saveObject(
				object: $payload,
				register: $this->getRegisterSlug(),
				schema: $schema,
				uuid: $conversationId,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'WhatsAppAdapter.touchConversationWindow: save failed',
				['conversationId' => $conversationId, 'exception' => $e->getMessage()]
			);
		}
	}                               

	   
                              
   
                                                 
   
                                                
    
	private function persistMessage(array $payload): ?array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$saved = $objectService->saveObject(
				object: $payload,
				register: $this->getRegisterSlug(),
				schema: $this->resolveSchemaSlug(key: 'message_schema', default: self::DEFAULT_MESSAGE_SCHEMA_SLUG),
				uuid: null,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'WhatsAppAdapter.persistMessage: save failed',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		return $this->toArray(value: $saved);
	}                      

	   
                                 
   
                                           
   
                                                  
    
	private function loadTemplate(string $id): ?array {
		$objectService = $this->getObjectService();
		if ($objectService === null || $id === '') {
			return null;
		}

		try {
			$entity = $objectService->find(
				id: $id,
				register: $this->getRegisterSlug(),
				schema: $this->resolveSchemaSlug(key: 'messageTemplate_schema', default: self::DEFAULT_TEMPLATE_SCHEMA_SLUG),
			);
		} catch (Throwable $e) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $this->toArray(value: $entity);
	}                    

	   
                                              
   
                                                     
   
                                         
    
	private function extractPhone(array $contact): string {
		foreach (['phoneNumber', 'mobile', 'telephone', 'phone'] as $key) {
			$value = ($contact[$key] ?? null);
			if (is_string($value) === true && $value !== '') {
				return $value;
			}
		}

		return '';
	}                    

	   
                                                  
   
                             
    
	private function getTenantId(): string {
		$tenantId = $this->appConfig->getValueString(Application::APP_ID, 'tenant_id', '');
		if ($tenantId !== '') {
			return $tenantId;
		}

		return 'default';
	}                   

	   
                                    
   
                                      
                                            
   
                        
    
	private function resolveSchemaSlug(string $key, string $default): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, $key, '');
		if ($slug !== '') {
			return $slug;
		}

		return $default;
	}                         

	   
                  
   
                        
    
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_REGISTER_SLUG;
	}                       

	   
                                       
   
                                        
    
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'WhatsAppAdapter.getObjectService: OpenRegister unavailable',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}                        

	   
                                       
   
                                        
   
                                               
    
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialised = $value->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($value) === true && method_exists($value, 'getObject') === true) {
			$payload = $value->getObject();
			if (is_array($payload) === true) {
				return $payload;
			}
		}

		return [];
	}               

	   
                                              
   
                                                 
   
                               
    
	private function extractId(array $payload): string {
		$id = $this->firstScalarId(source: $payload);
		if ($id !== '') {
			return $id;
		}

		if (isset($payload['@self']) === true && is_array($payload['@self']) === true) {
			return $this->firstScalarId(source: $payload['@self']);
		}

		return '';
	}                 

	   
                                                                   
   
                                                                       
                                                             
   
                                                              
   
                                                                      
    
	private function firstScalarId(array $source): string {
		foreach (['uuid', 'id', 'slug'] as $key) {
			$value = ($source[$key] ?? null);
			if (is_scalar($value) === true && (string)$value !== '') {
				return (string)$value;
			}
		}

		return '';
	}                     

	   
                                   
   
                             
    
	private function nowIso(): string {
		return gmdate('Y-m-d\TH:i:s\Z');
	}              
}           
