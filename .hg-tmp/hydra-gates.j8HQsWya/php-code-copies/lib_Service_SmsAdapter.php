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

   
                                                              
  
                       
                                                               
                                        
                                                           
                                          
  
                                                                    
                                                       
                                                                                
                                                                          
  
                                                                   
   
class SmsAdapter {
	use WritesAsMessagingAccount;

	   
                                                                                                  
   
                            
    
	private const LOOKUP_SCOPE = ['_rbac' => false, '_multitenancy' => false];

	   
                                   
    
	private const DEFAULT_REGISTER_SLUG = 'pipelinq';

	   
                                
    
	private const DEFAULT_MESSAGE_SCHEMA_SLUG = 'channelMessage';

	   
                                     
    
	private const DEFAULT_CONVERSATION_SCHEMA_SLUG = 'channelConversation';

	   
                                                              
                                                         
                         
    
	private const DEFAULT_CONTACT_SCHEMA_SLUG = 'contact';

	   
                                      
    
	public const STATUS_SENT = 'sent';

	   
                                                     
    
	public const STATUS_CONSENT_MISSING = 'consentMissing';

	   
                                                    
    
	public const STATUS_BUDGET_EXCEEDED = 'budgetExceeded';

	   
                                               
    
	public const STATUS_NO_PROVIDER = 'noProviderAvailable';

	   
                                                                  
    
	public const STATUS_FAILED = 'failed';

	   
                
   
                                                      
                                            
                                                                      
                                                                     
                                                       
                                                    
                                                                        
                                          
                                                                                  
   
                                                                    
    
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private ChannelProviderRepository $providerRepo,
		private SmsProviderFactory $providerFactory,
		private ConsentService $consentService,
		private BudgetService $budgetService,
		private NotificationService $notificationService,
		private LoggerInterface $logger,
		private PhoneNormaliser $phoneNormaliser,
	) {
	}                   

	   
                                                                  
   
                                                          
                                                          
                                                       
                                     
                                                          
                                                       
                                               
                                                                  
                                                           
                                                               
   
                  
                       
                           
                                   
                            
                        
                      
                       
   
                                                                    
                                                                                                                    
    
	public function send(array $contact, string $body, ?string $providerHint = null, array $context = []): array {
		$contactId = $this->extractId(payload: $contact);
		$toNumber = $this->extractPhone(contact: $contact);

		if ($toNumber === '') {
			return ['status' => self::STATUS_FAILED, 'error' => 'no recipient phone number'];
		}

		                                                                        
		                                                                      
		                                                                   
		$inReplyTo = $this->verifiedInbound(contactId: $contactId, messageId: (string)($context['inReplyTo'] ?? ''));
		if ($this->consentService->canSend(contactId: $contactId, channel: 'sms', address: $toNumber, inReplyTo: $inReplyTo) === false) {
			return ['status' => self::STATUS_CONSENT_MISSING];
		}

		$tenantId = $this->getTenantId();
		$ordered = $this->orderedProvidersForSend(providerHint: $providerHint);
		if ($ordered === []) {
			return ['status' => self::STATUS_NO_PROVIDER];
		}

		$allowFailover = ($providerHint === null);

		foreach ($ordered as $row) {
			$outcome = $this->attemptProviderSend(
				row: $row,
				toNumber: $toNumber,
				body: $body,
				contactId: $contactId,
				tenantId: $tenantId,
				allowFailover: $allowFailover,
				context: $context,
			);
			if ($outcome !== null) {
				return $outcome;
			}
		}

		                                                                    
		return $this->persistFailureAndAlert(
			contactId: $contactId,
			providerId: '',
			body: $body,
			error: 'all SMS providers returned transient errors',
		);
	}            

	   
                                          
   
                                                                  
                                                                   
                                                                   
                                             
   
                                                  
                                                   
                                     
                                          
                                      
                                                                                
                                                                             
   
                                                                            
    
	private function attemptProviderSend(
		array $row,
		string $toNumber,
		string $body,
		string $contactId,
		string $tenantId,
		bool $allowFailover,
		array $context = [],
	): ?array {
		$providerId = $this->extractId(payload: $row);

		if ($this->budgetService->canSend(tenantId: $tenantId, providerId: $providerId) === false) {
			if ($allowFailover === false) {
				return ['status' => self::STATUS_BUDGET_EXCEEDED, 'providerId' => $providerId];
			}

			return null;
		}

		$client = $this->providerFactory->create(channelProvider: $row);
		if ($client === null) {
			return null;
		}

		try {
			$result = $client->send(toNumber: $toNumber, body: $body);
		} catch (TransientSmsProviderException $e) {
			$this->logger->warning(
				'SmsAdapter.send: transient — failing over',
				['providerId' => $providerId, 'vendor' => $client->getVendor(), 'message' => $e->getMessage()]
			);
			if ($allowFailover === false) {
				return $this->persistFailureAndAlert(
					contactId: $contactId,
					providerId: $providerId,
					body: $body,
					error: $e->getMessage(),
				);
			}

			return null;
		} catch (PermanentSmsProviderException $e) {
			$this->logger->warning(
				'SmsAdapter.send: permanent provider failure',
				['providerId' => $providerId, 'vendor' => $client->getVendor(), 'message' => $e->getMessage()]
			);
			return $this->persistFailureAndAlert(
				contactId: $contactId,
				providerId: $providerId,
				body: $body,
				error: $e->getMessage(),
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SmsAdapter.send: unexpected exception — failing over',
				['providerId' => $providerId, 'vendor' => $client->getVendor(), 'message' => $e->getMessage()]
			);
			if ($allowFailover === false) {
				return $this->persistFailureAndAlert(
					contactId: $contactId,
					providerId: $providerId,
					body: $body,
					error: $e->getMessage(),
				);
			}

			return null;
		}         

		$persisted = $this->persistOutbound(
			contactId: $contactId,
			providerId: $providerId,
			body: $body,
			externalMessageId: $result['externalMessageId'],
		);

		$this->budgetService->recordSend(tenantId: $tenantId, providerId: $providerId);

		$messageId = $this->extractId(payload: ($persisted ?? []));
		$this->auditOutbound(
			contactId: $contactId,
			messageId: $messageId,
			conversationId: (string)(($persisted ?? [])['conversationId'] ?? ''),
			body: $body,
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
			channel: 'sms',
			subject: 'Outbound SMS',
			summary: $body,
			channelMetadata: [
				'platform' => 'sms',
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

	   
                                  
   
                                                                   
                                                                 
                                                                  
   
                                            
                                                    
                                                   
   
                  
                       
                           
                                
                             
                                 
                              
                      
                       
   
                                                                           
                                                                       
                                   
   
                                                                    
                                                                                                       
    
	public function handleInboundWebhook(string $rawBody, string $signature, string $providerId): array {
		                                                                                   
		                                                                     
		$row = $this->providerRepo->findByIdForWebhook(id: $providerId);
		if ($row === null) {
			return ['status' => 'providerUnknown'];
		}

		$client = $this->providerFactory->create(channelProvider: $row);
		if ($client === null) {
			return ['status' => 'vendorUnsupported'];
		}

		if ($client->verifySignature(rawBody: $rawBody, signature: $signature) === false) {
			$this->logger->warning(
				'SmsAdapter.handleInboundWebhook: invalid signature',
				['providerId' => $providerId]
			);
			return ['status' => 'invalidSignature'];
		}

		$decoded = $this->decodeBody(rawBody: $rawBody);
		$body = (string)($decoded['body'] ?? ($decoded['Body'] ?? ''));
		$from = $this->phoneNormaliser->normaliseInbound(
			rawNumber: (string)($decoded['from'] ?? ($decoded['From'] ?? ''))
		);

		if ($from === '' || $body === '') {
			return ['status' => 'invalidPayload'];
		}

		return $this->asMessagingAccount(
			writes: fn (): array => $this->ingestInbound(providerId: $providerId, from: $from, body: $body)
		);
	}                            

	   
                                                                           
                                                   
                                              
                                           
   
                                                  
    
	private function ingestInbound(string $providerId, string $from, string $body): array {
		$contactId = $this->findContactByPhone(phone: $from);
		$unknownSender = ($contactId === '');

		                                                                                        
		$conversationId = '';
		if ($unknownSender === false) {
			$conversationId = $this->findOrOpenConversation(
				contactId: $contactId,
				providerId: $providerId,
				channel: 'sms',
				scope: self::LOOKUP_SCOPE,
			);
		}

		$persisted = $this->persistInbound(
			contactId: $contactId,
			providerId: $providerId,
			conversationId: $conversationId,
			body: $body,
		);

		return $this->actOnInbound(
			result: [
				'status' => 'received',
				'messageId' => $this->extractId(payload: $persisted ?? []),
				'conversationId' => $conversationId,
				'unknownSender' => $unknownSender,
				'optOutRecorded' => false,
			],
			contactId: $contactId,
			from: $from,
			body: $body,
		);
	}                     

	   
                                                                       
                                                                     
                                                                   
   
                                                                
                                                                       
                                        
                                     
   
                                                        
    
	private function actOnInbound(array $result, string $contactId, string $from, string $body): array {
		if ($this->consentService->isOptOutKeyword(body: $body) === true) {
			$result['optOutRecorded'] = $this->consentService->recordOptOut(
				contactId: $contactId,
				channel: 'sms',
				source: 'keyword-stop',
				evidence: sprintf('Inbound SMS body "%s" matched STOP keyword', $body),
				address: $from,
			) !== null;
			return $result;
		}

		if ($contactId === '') {
			$result['contactMomentId'] = (string)$this->contactmoments()?->recordInboundFromUnknownNumber(
				platform: 'sms',
				phone: $from,
				body: $body,
				messageId: (string)$result['messageId'],
			);
			return $result;
		}

		if ($this->consentService->isOptInKeyword(body: $body) === true) {
			$this->consentService->recordOptIn(
				contactId: $contactId,
				channel: 'sms',
				source: 'chat-reply',
				evidence: sprintf('Inbound SMS body "%s" matched opt-in keyword', $body),
				address: $from,
			);
		}

		return $result;
	}                    

	   
                                                               
   
                                                           
   
                                                           
    
	private function orderedProvidersForSend(?string $providerHint): array {
		if ($providerHint !== null && $providerHint !== '') {
			$row = $this->providerRepo->findByVendor(kind: 'sms', vendor: $providerHint);
			if ($row === null) {
				return [];
			}

			return [$row];
		}

		return $this->providerRepo->listActive(kind: 'sms');
	}                               

	   
                                                             
                            
   
                                          
                                                                    
                                     
                                           
   
                                                                              
    
	private function persistFailureAndAlert(
		string $contactId,
		string $providerId,
		string $body,
		string $error,
	): array {
		$conversationId = $this->findOrOpenConversation(
			contactId: $contactId,
			providerId: $providerId,
			channel: 'sms',
		);

		$this->persistMessage(
			payload: [
				'contactId' => $contactId,
				'conversationId' => $conversationId,
				'channel' => 'sms',
				'direction' => 'outbound',
				'body' => $body,
				'providerId' => $providerId,
				'deliveryStatus' => 'failed',
				'sentAt' => $this->nowIso(),
				'metadata' => ['error' => $error],
			],
		);

		try {
			$this->notificationService->sendNotification(
				userId: 'admin',
				subject: 'messaging_sms_send_failed',
				parameters: ['contactId' => $contactId, 'providerId' => $providerId, 'error' => $error],
				objectType: 'message',
				objectId: $contactId,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SmsAdapter.persistFailureAndAlert: notification failed',
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
	): ?array {
		$conversationId = $this->findOrOpenConversation(
			contactId: $contactId,
			providerId: $providerId,
			channel: 'sms',
		);

		return $this->persistMessage(
			payload: [
				'contactId' => $contactId,
				'conversationId' => $conversationId,
				'channel' => 'sms',
				'direction' => 'outbound',
				'body' => $body,
				'providerId' => $providerId,
				'externalMessageId' => $externalMessageId,
				'deliveryStatus' => 'queued',
				'sentAt' => $this->nowIso(),
			],
		);
	}                       

	   
                                   
   
                                          
                                            
                                                    
                             
   
                                                                             
   
                                                
    
	private function persistInbound(
		string $contactId,
		string $providerId,
		string $conversationId,
		string $body,
	): ?array {
		return $this->persistMessage(
			payload: [
				'contactId' => $contactId,
				'conversationId' => $conversationId,
				'channel' => 'sms',
				'direction' => 'inbound',
				'body' => $body,
				'providerId' => $providerId,
				'deliveryStatus' => 'delivered',
				'sentAt' => $this->nowIso(),
			],
		);
	}                      

	   
                                                                  
   
                                          
                                                                                
   
                                                                  
   
                                                                                                                                                                   
    
	private function verifiedInbound(string $contactId, string $messageId): ?string {
		$objectService = $this->getObjectService();
		if ($contactId === '' || $messageId === '' || $objectService === null) {
			return null;
		}

		try {
			$found = $objectService->find(
				id: $messageId,
				register: $this->getRegisterSlug(),
				schema: $this->resolveSchemaSlug(key: 'message_schema', default: self::DEFAULT_MESSAGE_SCHEMA_SLUG)
			);
		} catch (Throwable $e) {
			return null;
		}

		$row = $this->toArray(value: $found);
		$isOwnInbound = ((string)($row['contactId'] ?? '') === $contactId
			&& (string)($row['channel'] ?? '') === 'sms'
			&& (string)($row['direction'] ?? '') === 'inbound');
		if ($isOwnInbound === false) {
			return null;
		}

		return $messageId;
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
				'SmsAdapter.persistMessage: save failed',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		return $this->toArray(value: $saved);
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
			$this->logger->warning(
				'SmsAdapter.findOrOpenConversation: findAll failed',
				['exception' => $e->getMessage()]
			);
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
					'lastInboundAt' => $this->nowIso(),
				],
				register: $this->getRegisterSlug(),
				schema: $schema,
				uuid: null,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SmsAdapter.findOrOpenConversation: open failed',
				['exception' => $e->getMessage()]
			);
			return '';
		}

		return $this->extractId(payload: $this->toArray(value: $saved));
	}                              

	   
                                                                  
   
                                    
   
                                                 
    
	private function decodeBody(string $rawBody): array {
		$trimmed = trim($rawBody);
		if ($trimmed === '') {
			return [];
		}

		if ($trimmed[0] === '{' || $trimmed[0] === '[') {
			$decoded = json_decode($trimmed, true);
			if (is_array($decoded) === true) {
				return $decoded;
			}

			return [];
		}

		$parsed = [];
		parse_str($trimmed, $parsed);
		return $parsed;
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
				'SmsAdapter.getObjectService: OpenRegister unavailable',
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
		$id = $this->firstScalarValue(source: $payload, keys: ['uuid', 'id', 'slug']);
		if ($id !== '') {
			return $id;
		}

		if (isset($payload['@self']) === true && is_array($payload['@self']) === true) {
			return $this->firstScalarValue(source: $payload['@self'], keys: ['uuid', 'id', 'slug']);
		}

		return '';
	}                 

	   
                                                                              
   
                                                     
                                                          
   
                                                                     
    
	private function firstScalarValue(array $source, array $keys): string {
		foreach ($keys as $key) {
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
