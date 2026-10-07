<?php

   
                       
  
                                                                               
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Cti\AdapterRegistry;
use OCA\Pipelinq\Service\Cti\Result\CtiWebhookResult;
use OCA\Pipelinq\Service\Cti\Result\OriginateResult;
use OCA\Pipelinq\Service\Cti\Result\ScreenPopResult;
use InvalidArgumentException;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

   
                                  
  
                                                                             
                                                                              
                                        
  
                                                                      
                                                                                
                                                              
                                                                            
                   
  
                                                                      
                                                 
  
                                                                                    
                                                                                 
                                                                                                                                 
                                                                                                                                   
                                                                                                                           
                                                                                                                     
  
                                                                 
                                                                                                                               
   
class CtiService {
	   
                
   
                                                                     
                                                
                                                             
                                                             
                                                             
                                                                         
                                                                
                                          
    
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private AdapterRegistry $adapterRegistry,
		private PhoneNormaliser $phoneNormaliser,
		private CtiContactMatcher $contactMatcher,
		private CtiDispositionService $dispositionService,
		private TicketService $ticketService,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                      
   
                                                                           
                          
                                                                
                                                                   
                                                  
   
                                                
                                                             
                                                                 
                                                                    
   
                                                                        
   
                                                                  
    
	public function handleWebhook(
		string $platform,
		array $payload,
		?string $rawBody = null,
		?string $signature = null,
	): array {
		$adapter = $this->adapterRegistry->get($platform);
		$rawForSig = ($rawBody ?? json_encode($payload));
		$rawForSigString = '';
		if ($rawForSig !== false) {
			$rawForSigString = (string)$rawForSig;
		}

		                                                        
		  
		                                                                        
		                                                                    
		                                                                     
		                                                                         
		                                           
		  
		                                                                   
		                                                                
		                                                                    
		                                                            
		  
		                                                                    
		                                                                        
		                                                                      
		                                                                      
		                 
		$valid = $adapter->verifyWebhookSignature($rawForSigString, ($signature ?? ''));

		                                                             
		  
		                                                                      
		                                                                   
		                                                                        
		                                                                  
		                                                                     
		                              
		  
		                                                                        
		                                                          
		try {
			$normalised = $adapter->handleInboundWebhook($payload);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CTI webhook: adapter could not parse the payload',
				['platform' => $platform, 'error' => $e->getMessage()]
			);
			throw new InvalidArgumentException('The webhook payload could not be parsed.', 0, $e);
		}
		$interactionId = null;
		$processingError = null;

		if ($valid === false) {
			$processingError = 'Invalid signature';
		}

		if ($valid === true) {
			try {
				$interactionId = $this->dispatchEvent(platform: $platform, event: $normalised);
			} catch (\Throwable $e) {
				$this->logger->error(
					'CTI webhook dispatch failed',
					['exception' => $e->getMessage(), 'platform' => $platform]
				);
				$processingError = $e->getMessage();
			}
		}

		$this->logEvent(
			platform: $platform,
			event: $normalised,
			signatureValid: $valid,
			processingError: $processingError,
		);

		return [
			'logged' => true,
			'valid' => $valid,
			'interactionId' => $interactionId,
		];
	}                     

	   
                                                           
   
                                                       
                                                    
   
                                                                      
    
	private function dispatchEvent(string $platform, CtiWebhookResult $event): ?string {
		return match ($event->eventType) {
			'ringing', 'answered' => $this->upsertCallContactmoment(platform: $platform, event: $event),
			'ended' => $this->finaliseContactmoment(platform: $platform, event: $event),
			'abandoned' => $this->markAbandoned(platform: $platform, event: $event),
			'presence' => $this->syncPresenceFromEvent(platform: $platform, event: $event),
			'recording' => $this->attachRecordingFromEvent(platform: $platform, event: $event),
			default => null,
		};
	}                     

	   
                                                     
   
                                                      
                                                                 
   
                                                                   
                                                                                                                
    
	public function initiateScreenPop(string $fromNumber, ?string $orgId = null): ScreenPopResult {
		$normalised = $this->phoneNormaliser->normaliseForOrg($fromNumber, $orgId);
		$result = $this->contactMatcher->findByPhoneNumber($normalised['e164'], $orgId);

		$action = ScreenPopResult::ACTION_INTAKE;
		if ($result['totalMatches'] === 1) {
			$action = ScreenPopResult::ACTION_NAVIGATE;
		} elseif ($result['totalMatches'] > 1) {
			$action = ScreenPopResult::ACTION_CHOOSER;
		}

		return new ScreenPopResult(
			action: $action,
			matches: $result['matches'],
			e164: $normalised['e164'],
			rawNumber: $normalised['raw'],
		);
	}                         

	   
                                          
   
                                              
                                            
                                          
                                           
                                             
                                                               
   
                                                                             
   
                                                                                                                                
    
	public function createPendingContactmoment(
		string $direction,
		string $fromNumber,
		string $toNumber,
		string $userId,
		string $extension,
		array $extra = [],
	): ?string {
		if ($this->ticketService->isConfigured() === false) {
			$this->logger->warning('CTI: ticket register/schema not configured.');
			return null;
		}

		$fromE164 = $this->phoneNormaliser->normaliseForOrg($fromNumber)['e164'];
		$toE164 = $this->phoneNormaliser->normaliseForOrg($toNumber)['e164'];

		$payload = array_merge(
			[
				'channel' => 'telephony',
				'direction' => $direction,
				'from_number' => ($fromE164 ?? $fromNumber),
				'to_number' => ($toE164 ?? $toNumber),
				'cti_extension' => $extension,
				'assignee' => $userId,
				'started_at' => gmdate('Y-m-d\TH:i:s\Z'),
				'title' => 'CTI ' . $direction . ' call',
			],
			$extra
		);

		try {
			$saved = $this->ticketService->save(
				ticketType: TicketService::TYPE_CONTACTMOMENT,
				payload: $payload,
			);
			return $this->extractId(object: $saved);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CTI createPendingContactmoment failed',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}                                  

	   
                                                                          
   
                                                           
                                              
                                                              
                                                          
                                                      
   
                
   
                                                                  
                                                                                                                                
    
	public function completeContactmoment(
		string $interactionId,
		int $durationSeconds,
		string $outcome,
		string $dispositionSubject,
		string $dispositionNotes,
	): void {
		if ($this->ticketService->isConfigured() === false) {
			return;
		}

		try {
			$dispositionOutcome = 'resolved';
			if ($outcome !== '') {
				$dispositionOutcome = $outcome;
			}

			$this->ticketService->save(
				ticketType: TicketService::TYPE_CONTACTMOMENT,
				payload: [
					'duration_seconds' => $durationSeconds,
					'ended_at' => gmdate('Y-m-d\TH:i:s\Z'),
					'disposition_subject' => $dispositionSubject,
					'disposition_outcome' => $dispositionOutcome,
					'disposition_notes' => $dispositionNotes,
				],
				uuid: $interactionId,
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CTI completeContactmoment failed',
				['exception' => $e->getMessage(), 'interactionId' => $interactionId]
			);
		}         
	}                             

	   
                                                                  
   
                                                           
                                                     
                                                       
   
                                                                           
                                                           
   
                                                                                                                                
    
	public function attachRecording(string $interactionId, string $recordingUrl, string $expiresAt): bool {
		                                        
		  
		                                                                     
		                                                                     
		                                                                  
		                                                                      
		                                                               
		if ($this->ticketService->isConfigured() === false) {
			$this->logger->warning(
				'CTI attachRecording: ticket store is not configured',
				['interactionId' => $interactionId]
			);
			return false;
		}

		try {
			$this->ticketService->save(
				ticketType: TicketService::TYPE_CONTACTMOMENT,
				payload: [
					'recording_url' => $recordingUrl,
					'recording_retention_expires_at' => $expiresAt,
				],
				uuid: $interactionId,
			);
			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'CTI attachRecording failed',
				['exception' => $e->getMessage(), 'interactionId' => $interactionId]
			);
			return false;
		}
	}                       

	   
                                                      
   
                                           
                                                     
                                                                     
   
                                                                                                          
                                                                  
    
	public function originateCall(string $userId, string $extension, string $targetNumber): OriginateResult {
		$platform = $this->activePlatform();
		if ($platform === '') {
			return new OriginateResult(
				success: false,
				error: 'No CTI platform configured.',
			);
		}

		$config = $this->loadConfig();
		$callerId = (string)($config['default_outbound_caller_id'] ?? '');

		try {
			$adapter = $this->adapterRegistry->get($platform);
			$callResult = $adapter->originateCall(
				extension: $extension,
				targetNumber: $targetNumber,
				callerId: $callerId,
			);
		} catch (\Throwable $e) {
			return new OriginateResult(
				success: false,
				error: 'CTI adapter error: ' . $e->getMessage(),
				platform: $platform,
			);
		}

		$interactionId = null;
		if ($callResult->success === true) {
			$interactionId = $this->createPendingContactmoment(
				direction: 'outbound',
				fromNumber: $callerId,
				toNumber: $targetNumber,
				userId: $userId,
				extension: $extension,
				extra: [
					'telephony_platform' => $platform,
					'external_call_id' => ($callResult->externalCallId ?? ''),
				],
			);
		}

		return new OriginateResult(
			success: $callResult->success,
			externalCallId: $callResult->externalCallId,
			interactionId: $interactionId,
			error: $callResult->error,
			platform: $platform,
		);
	}                     

	   
                                                              
   
                                      
                                                                               
                                                  
                                                    
   
                
                                                                                                                
    
	public function syncPresence(string $userId, string $presenceState, ?string $extension = null, ?string $platform = null): void {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'ctiAgentPresence_schema', '');
		if ($register === '' || $schema === '') {
			return;
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$existing = $objectService->findAll(
				[
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'user_id' => $userId,
					],
					'limit' => 1,
				]
			);

			$uuid = null;
			if (is_array($existing) === true && count($existing) > 0) {
				$first = $existing[0];
				$uuid = $this->extractId(object: $first);
			}

			$objectService->saveObject(
				[
					'user_id' => $userId,
					'extension' => ($extension ?? ''),
					'presence_state' => $presenceState,
					'last_updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
					'platform' => ($platform ?? $this->activePlatform()),
				],
				[],
				$register,
				$schema,
				$uuid
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CTI syncPresence failed',
				['exception' => $e->getMessage(), 'userId' => $userId]
			);
		}         
	}                    

	   
                                           
   
                                                                   
                                                                                                                
    
	public function activePlatform(): string {
		$config = $this->loadConfig();
		return (string)($config['platform'] ?? '');
	}                      

	   
                                  
   
                               
                                                                                                                
    
	public function loadConfig(): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'ctiAdapterConfig_schema', '');
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$existing = $objectService->findAll(
				[
					'filters' => [
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 1,
				]
			);
			if (is_array($existing) === true && count($existing) > 0) {
				$first = $existing[0];
				return $this->toArray(object: $first);
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CTI loadConfig failed',
				['exception' => $e->getMessage()]
			);
		}         

		return [];
	}                  

	   
                                                         
   
                                                          
   
                               
                                                                                                                
    
	public function saveConfig(array $config): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'ctiAdapterConfig_schema', '');
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$existing = $objectService->findAll(
				[
					'filters' => [
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 1,
				]
			);

			$uuid = null;
			if (is_array($existing) === true && count($existing) > 0) {
				$uuid = $this->extractId(object: $existing[0]);
			}

			$saved = $objectService->saveObject($config, [], $register, $schema, $uuid);
			return $this->toArray(object: $saved);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CTI saveConfig failed',
				['exception' => $e->getMessage()]
			);
			return [];
		}         
	}                  

	   
                                                      
   
                                                              
                                                                                                                
    
	public function testConnection(): array {
		$platform = $this->activePlatform();
		if ($platform === '') {
			return [
				'ok' => false,
				'platform' => '',
				'message' => 'No CTI platform configured.',
			];
		}

		try {
			$this->adapterRegistry->get($platform);
			return [
				'ok' => true,
				'platform' => $platform,
				'message' => 'Adapter resolved and configuration present.',
			];
		} catch (\Throwable $e) {
			return [
				'ok' => false,
				'platform' => $platform,
				'message' => $e->getMessage(),
			];
		}
	}                      

	   
                                                                    
   
                                                                                       
                                
                                   
   
                                          
                                                                                                                
    
	public function listEventLog(array $filters = [], int $limit = 50, int $offset = 0): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'ctiEventLog_schema', '');
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$result = $objectService->findAll(
				[
					'filters' => array_merge(
						['register' => $register, 'schema' => $schema],
						array_filter(
							$filters,
							static fn ($v): bool => ($v !== null && $v !== '')
						)
					),
					'limit' => $limit,
					'offset' => $offset,
					'sort' => ['received_at' => 'desc'],
				]
			);

			if (is_array($result) === false) {
				return [];
			}

			return array_values(
				array_map(
					fn ($obj) => $this->toArray(object: $obj),
					$result
				)
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CTI listEventLog failed',
				['exception' => $e->getMessage()]
			);
			return [];
		}         
	}                    

	   
                                               
   
                                          
   
                                                                  
   
                                                                                                                                         
    
	public function purgeOldEventLog(): int {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'ctiEventLog_schema', '');
		if ($register === '' || $schema === '') {
			return 0;
		}

		$cutoff = gmdate('Y-m-d\TH:i:s\Z', (time() - (30 * 86400)));

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$oldEntries = $objectService->findAll(
				[
					'filters' => [
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 1000,
				]
			);

			if (is_array($oldEntries) === false) {
				return 0;
			}

			$deleted = 0;
			foreach ($oldEntries as $entry) {
				$data = $this->toArray(object: $entry);
				$receivedAt = (string)($data['received_at'] ?? '');
				if ($receivedAt === '' || $receivedAt >= $cutoff) {
					continue;
				}

				$id = $this->extractId(object: $entry);
				if ($id === null) {
					continue;
				}

				try {
					$objectService->deleteObject(register: $register, schema: $schema, uuid: $id);
					$deleted++;
				} catch (\Throwable $e) {
					$this->logger->warning(
						'CTI purgeOldEventLog: deleteObject failed',
						['exception' => $e->getMessage(), 'id' => $id]
					);
				}
			}             

			return $deleted;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CTI purgeOldEventLog failed',
				['exception' => $e->getMessage()]
			);
			return 0;
		}         
	}                        

	   
                                                 
   
                                                
                                                    
                                                                    
                                                                         
   
                
    
	private function logEvent(
		string $platform,
		CtiWebhookResult $event,
		bool $signatureValid,
		?string $processingError,
	): void {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'ctiEventLog_schema', '');
		if ($register === '' || $schema === '') {
			return;
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$objectService->saveObject(
				[
					'received_at' => gmdate('Y-m-d\TH:i:s\Z'),
					'platform' => $platform,
					'event_type' => $event->eventType,
					'external_call_id' => $event->externalCallId,
					'payload_json' => $event->raw,
					'processed_at' => gmdate('Y-m-d\TH:i:s\Z'),
					'processing_error' => $processingError,
					'signature_valid' => $signatureValid,
				],
				[],
				$register,
				$schema,
				null
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CTI logEvent failed',
				['exception' => $e->getMessage()]
			);
		}         
	}                

	   
                                                                   
   
                                             
                                                    
   
                                           
    
	private function upsertCallContactmoment(string $platform, CtiWebhookResult $event): ?string {
		$existing = $this->findContactmomentByCallId(externalCallId: $event->externalCallId);
		$extra = [
			'telephony_platform' => $platform,
			'external_call_id' => $event->externalCallId,
			'queue_name' => ($event->queueName ?? ''),
			'agent_skill' => ($event->agentSkill ?? ''),
		];
		if ($event->eventType === 'answered') {
			$extra['answered_at'] = gmdate('Y-m-d\TH:i:s\Z');
		}

		if ($existing === null) {
			return $this->createPendingContactmoment(
				direction: ($event->direction ?? 'inbound'),
				fromNumber: ($event->fromNumber ?? ''),
				toNumber: ($event->toNumber ?? ''),
				userId: ($event->userId ?? ''),
				extension: ($event->extension ?? ''),
				extra: $extra,
			);
		}

		$this->updateContactmoment(id: $existing, fields: $extra);
		return $existing;
	}                               

	   
                                              
   
                                             
                                                    
   
                                           
    
	private function finaliseContactmoment(string $platform, CtiWebhookResult $event): ?string {
		$id = $this->findContactmomentByCallId(externalCallId: $event->externalCallId);
		if ($id === null) {
			                                                                            
			$id = $this->createPendingContactmoment(
				direction: ($event->direction ?? 'inbound'),
				fromNumber: ($event->fromNumber ?? ''),
				toNumber: ($event->toNumber ?? ''),
				userId: ($event->userId ?? ''),
				extension: ($event->extension ?? ''),
				extra: [
					'telephony_platform' => $platform,
					'external_call_id' => $event->externalCallId,
				],
			);
		}

		if ($id === null) {
			return null;
		}

		$fields = [
			'ended_at' => gmdate('Y-m-d\TH:i:s\Z'),
			'duration_seconds' => ($event->durationSeconds ?? 0),
		];
		if ($event->recordingUrl !== null) {
			$fields['recording_url'] = $event->recordingUrl;
		}

		if ($event->recordingExpiresAt !== null) {
			$fields['recording_retention_expires_at'] = $event->recordingExpiresAt;
		}

		$this->updateContactmoment(id: $id, fields: $fields);
		return $id;
	}                             

	   
                                                                     
   
                                             
                                                    
   
                                           
    
	private function markAbandoned(string $platform, CtiWebhookResult $event): ?string {
		$id = $this->upsertCallContactmoment(platform: $platform, event: $event);
		if ($id === null) {
			return null;
		}

		$this->updateContactmoment(
			id: $id,
			fields: [
				'ended_at' => gmdate('Y-m-d\TH:i:s\Z'),
				'duration_seconds' => 0,
				'disposition_outcome' => 'abandoned',
			]
		);
		return $id;
	}                     

	   
                                                  
   
                                             
                                                    
   
                                                                     
    
	private function syncPresenceFromEvent(string $platform, CtiWebhookResult $event): null {
		if ($event->userId === null && $event->extension === null) {
			return null;
		}

		$this->syncPresence(
			userId: ($event->userId ?? ($event->extension ?? '')),
			presenceState: ($event->presenceState ?? 'available'),
			extension: $event->extension,
			platform: $platform,
		);
		return null;
	}                             

	   
                                                                
   
                                             
                                                    
   
                                           
   
                                                                                                                     
    
	private function attachRecordingFromEvent(string $platform, CtiWebhookResult $event): ?string {
		if ($event->recordingUrl === null) {
			return null;
		}

		$id = $this->findContactmomentByCallId(externalCallId: $event->externalCallId);
		if ($id === null) {
			return null;
		}

		$this->attachRecording(
			interactionId: $id,
			recordingUrl: $event->recordingUrl,
			expiresAt: ($event->recordingExpiresAt ?? ''),
		);
		return $id;
	}                                

	   
                                                       
   
                                                     
   
                                                                  
    
	private function findContactmomentByCallId(string $externalCallId): ?string {
		if ($externalCallId === '') {
			return null;
		}

		$found = $this->ticketService->findByType(
			ticketType: TicketService::TYPE_CONTACTMOMENT,
			extraFilters: ['external_call_id' => $externalCallId],
			limit: 1,
		);

		if (count($found) > 0) {
			return $this->extractId(object: $found[0]);
		}

		return null;
	}                                 

	   
                                                              
   
                                                
                                                       
   
                
   
                                                                                                                                
    
	private function updateContactmoment(string $id, array $fields): void {
		if ($this->ticketService->isConfigured() === false) {
			return;
		}

		try {
			$this->ticketService->save(
				ticketType: TicketService::TYPE_CONTACTMOMENT,
				payload: $fields,
				uuid: $id,
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CTI updateContactmoment failed',
				['exception' => $e->getMessage(), 'id' => $id]
			);
		}
	}                           

	   
                                                   
   
                                              
   
                                                    
                                               
                                        
                               
   
                                                                              
                                                                  
    
	public function processDisposition(string $interactionId, string $subject, string $outcome, string $notes): array {
		return $this->dispositionService->processDisposition(
			interactionId: $interactionId,
			subject: $subject,
			outcome: $outcome,
			notes: $notes,
		);
	}                          

	   
                                                 
   
                                                                
   
                       
   
                                                                                                                                               
    
	private function extractId(mixed $object): ?string {
		if (is_array($object) === true) {
			$id = ($object['id'] ?? ($object['uuid'] ?? null));
			if ($id !== null) {
				return (string)$id;
			}

			return null;
		}

		if (is_object($object) === true) {
			foreach (['getUuid', 'getId'] as $method) {
				if (method_exists($object, $method) === true) {
					$value = $object->$method();
					if ($value !== null) {
						return (string)$value;
					}
				}
			}

			if (method_exists($object, 'jsonSerialize') === true) {
				$serialised = $object->jsonSerialize();
				if (is_array($serialised) === true) {
					$id = ($serialised['id'] ?? ($serialised['uuid'] ?? null));
					if ($id !== null) {
						return (string)$id;
					}

					return null;
				}
			}
		}        

		return null;
	}                 

	   
                                                     
   
                                       
   
                               
    
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$value = $object->jsonSerialize();
			if (is_array($value) === true) {
				return $value;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$value = $object->getObject();
			if (is_array($value) === true) {
				return $value;
			}
		}

		return [];
	}               
}           
