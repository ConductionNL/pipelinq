<?php

   
                                    
  
                                                                   
                                                                        
                                                                         
                                                                        
                                                                   
                                                                      
                                                       
  
                  
                                                                   
                                                                                            
                                                                      
                                                                          
                                                           
                                                                                
                                                                             
                                                          
  
                                                                      
                                                                        
                                                              
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use Closure;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                        
                     
  
                  
                                                                             
                                                                       
                                                               
                                                                     
                                                               
  
                                                                        
                                                              
                                                                               
                                                                               
                                                                                    
  
                                                                                                 
   
class WebhookProcessorService {
	   
                                                           
    
	private const DEFAULT_REGISTER_SLUG = 'pipelinq';

	   
                                      
    
	private const DEFAULT_BLAST_DELIVERY_SCHEMA_SLUG = 'blastDelivery';

	   
                                                                 
    
	private const APP_CONFIG_SOFT_BOUNCE_THRESHOLD = 'blast.soft_bounce_threshold';

	   
                                                                    
                             
    
	private const DEFAULT_SOFT_BOUNCE_THRESHOLD = 5;

	   
                                                               
    
	private const SOFT_BOUNCE_KEY_PREFIX = 'blast.soft_bounce_count.';

	   
                
   
                                                            
                                                                 
                                                          
                                                     
                                                                        
                                                                         
                                                                 
                                          
   
                                                                                                  
    
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private ComplianceService $complianceService,
		private AttributionService $attributionService,
		private BlastService $blastService,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                          
   
                                                                         
                                                                      
                                                                
   
                                                                
                                                            
                                      
   
                                                           
                                                 
   
                                                                                                  
    
	public function processEvent(array $event, string $provider): bool {
		$eventType = strtolower((string)($event['eventType'] ?? $event['event'] ?? ''));
		if ($eventType === '') {
			$this->logger->info(
				'WebhookProcessorService.processEvent: missing eventType',
				['provider' => $provider]
			);
			return false;
		}

		$handler = $this->resolveEventHandler(eventType: $eventType);
		if ($handler === null) {
			$this->logger->info(
				'WebhookProcessorService.processEvent: unhandled eventType',
				['provider' => $provider, 'eventType' => $eventType]
			);
			return true;
		}

		try {
			$handler($event);
		} catch (Throwable $e) {
			$this->logger->warning(
				'WebhookProcessorService.processEvent: handler failed',
				['provider' => $provider, 'eventType' => $eventType, 'exception' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}                    

	   
                                                                     
   
                                                                        
                                                                         
                                                     
   
                                                            
   
                                                                                            
    
	private function resolveEventHandler(string $eventType): ?Closure {
		$handlers = [
			'delivered' => $this->processDelivered(...),
			'bounce' => $this->processBounce(...),
			'bounced' => $this->processBounce(...),
			'open' => $this->processOpen(...),
			'opened' => $this->processOpen(...),
			'click' => $this->processClick(...),
			'clicked' => $this->processClick(...),
			'unsubscribe' => $this->processUnsubscribe(...),
			'unsubscribed' => $this->processUnsubscribe(...),
			'group_unsubscribe' => $this->processUnsubscribe(...),
			'spamreport' => $this->processComplaint(...),
			'spam_report' => $this->processComplaint(...),
			'complaint' => $this->processComplaint(...),
			'complained' => $this->processComplaint(...),
		];

		return $handlers[$eventType] ?? null;
	}                           

	   
                                                                     
   
                                                                       
   
                                        
   
                                                                                                  
    
	public function processSendGridEvent(array $events): int {
		$handled = 0;
		foreach ($events as $raw) {
			$normalised = $this->normaliseSendGrid(event: $raw);
			if ($this->processEvent(event: $normalised, provider: 'sendgrid') === true) {
				$handled++;
			}
		}

		return $handled;
	}                            

	   
                                                                        
   
                                                        
   
                
   
                                                                                                  
    
	public function processDelivered(array $event): void {
		$delivery = $this->locateDelivery(event: $event);
		if ($delivery === null) {
			return;
		}

		$payload = $delivery;
		if (empty($payload['deliveredAt']) === true) {
			$payload['deliveredAt'] = $this->extractTimestamp(event: $event);
		}

		$current = (string)($payload['status'] ?? '');
		if (in_array($current, ['queued', 'sent'], true) === true) {
			$payload['status'] = 'delivered';
		}

		$this->saveDelivery(payload: $payload, id: $this->extractId(payload: $delivery));
	}                        

	   
                            
   
                                                                          
                                                                    
                                                                         
                                                                    
                                                    
   
                                                        
   
                
   
                                                                                                                
    
	public function processBounce(array $event): void {
		$delivery = $this->locateDelivery(event: $event);
		if ($delivery === null) {
			return;
		}

		$bounceType = strtolower((string)($event['bounceType'] ?? $event['type'] ?? ''));
		if ($bounceType === '') {
			$bounceType = 'hard';
		}

		$payload = $delivery;
		$payload['status'] = 'bounced';
		$payload['bounceType'] = $bounceType;
		$payload['bouncedAt'] = $this->extractTimestamp(event: $event);
		$bounceReason = (string)($event['reason'] ?? $event['bounceReason'] ?? '');
		if ($bounceReason !== '') {
			$payload['bounceReason'] = $bounceReason;
		}

		$this->saveDelivery(payload: $payload, id: $this->extractId(payload: $delivery));

		$contactId = (string)($delivery['contactId'] ?? '');
		$channel = (string)($delivery['channel'] ?? 'email');
		$blastId = (string)($delivery['blastId'] ?? '');
		if ($contactId === '') {
			return;
		}

		if ($bounceType === 'hard') {
			$this->complianceService->recordConsentWithdrawal(
				contactId: $contactId,
				channel: $channel,
				reason: 'bounce-hard',
				sourceBlastId: $this->blastIdOrNull(blastId: $blastId),
			);
			$this->clearSoftBounceCounter(contactId: $contactId, channel: $channel);
			return;
		}

		                                                                      
		$threshold = $this->resolveSoftBounceThreshold();
		$next = $this->incrementSoftBounceCounter(contactId: $contactId, channel: $channel);
		if ($next >= $threshold) {
			$this->complianceService->recordConsentWithdrawal(
				contactId: $contactId,
				channel: $channel,
				reason: 'bounce-soft-x' . $threshold,
				sourceBlastId: $this->blastIdOrNull(blastId: $blastId),
			);
			$this->clearSoftBounceCounter(contactId: $contactId, channel: $channel);
		}
	}                     

	   
                                                                 
   
                                                        
   
                
   
                                                                                                  
    
	public function processOpen(array $event): void {
		$delivery = $this->locateDelivery(event: $event);
		if ($delivery === null) {
			return;
		}

		$payload = $delivery;
		if (empty($payload['openedAt']) === true) {
			$payload['openedAt'] = $this->extractTimestamp(event: $event);
		}

		$current = (string)($payload['status'] ?? '');
		if (in_array($current, ['delivered', 'sent'], true) === true) {
			$payload['status'] = 'opened';
		}

		$this->saveDelivery(payload: $payload, id: $this->extractId(payload: $delivery));
	}                   

	   
                                                                       
                                                                      
   
                                                        
   
                
   
                                                                                                           
    
	public function processClick(array $event): void {
		$delivery = $this->locateDelivery(event: $event);
		if ($delivery === null) {
			return;
		}

		$url = (string)($event['url'] ?? '');
		$timestamp = $this->extractTimestamp(event: $event);
		$utm = $this->extractUtmCampaign(url: $url);

		$clickPayload = [
			'url' => $url,
			'timestamp' => $timestamp,
			'utmCampaign' => $utm,
		];

		$deliveryId = $this->extractId(payload: $delivery);
		if ($deliveryId === '') {
			return;
		}

		$this->attributionService->recordClick(blastDeliveryId: $deliveryId, clickEvent: $clickPayload);
	}                    

	   
                                                                
                        
   
                                                        
   
                
   
                                                                                                           
    
	public function processUnsubscribe(array $event): void {
		$delivery = $this->locateDelivery(event: $event);
		if ($delivery === null) {
			return;
		}

		$payload = $delivery;
		$payload['status'] = 'unsubscribed';
		$payload['unsubscribedAt'] = $this->extractTimestamp(event: $event);
		$this->saveDelivery(payload: $payload, id: $this->extractId(payload: $delivery));

		$contactId = (string)($delivery['contactId'] ?? '');
		$channel = (string)($delivery['channel'] ?? 'email');
		$blastId = (string)($delivery['blastId'] ?? '');
		if ($contactId === '') {
			return;
		}

		$this->complianceService->recordConsentWithdrawal(
			contactId: $contactId,
			channel: $channel,
			reason: 'user-unsubscribed',
			sourceBlastId: $this->blastIdOrNull(blastId: $blastId),
		);
	}                          

	   
                                                                     
                                                                   
                                                                      
             
   
                                                        
   
                
   
                                                                                                  
    
	public function processComplaint(array $event): void {
		$delivery = $this->locateDelivery(event: $event);
		if ($delivery === null) {
			return;
		}

		$payload = $delivery;
		$payload['status'] = 'complained';
		$payload['complainedAt'] = $this->extractTimestamp(event: $event);
		$this->saveDelivery(payload: $payload, id: $this->extractId(payload: $delivery));

		$contactId = (string)($delivery['contactId'] ?? '');
		$channel = (string)($delivery['channel'] ?? 'email');
		$blastId = (string)($delivery['blastId'] ?? '');
		if ($contactId === '') {
			return;
		}

		$this->complianceService->recordConsentWithdrawal(
			contactId: $contactId,
			channel: $channel,
			reason: 'spam-complaint',
			sourceBlastId: $this->blastIdOrNull(blastId: $blastId),
		);
	}                        

	   
                                                       
   
                                                                         
                                                                      
                                                                   
   
                                                          
   
                                                  
   
                                                                                                  
    
	public function normaliseSendGrid(array $event): array {
		$type = strtolower((string)($event['event'] ?? ''));
		if ($type === 'spamreport') {
			$type = 'spam_report';
		}

		$bounceType = strtolower((string)($event['type'] ?? ''));
		if ($bounceType === '' && $type === 'bounce') {
			$bounceType = 'hard';
		}

		return [
			'eventType' => $type,
			'providerId' => (string)($event['sg_message_id'] ?? $event['providerId'] ?? ''),
			'blastDeliveryId' => (string)($event['blastDeliveryId'] ?? ''),
			'contactId' => (string)($event['contactId'] ?? ''),
			'email' => (string)($event['email'] ?? ''),
			'timestamp' => $this->coerceTimestamp(raw: ($event['timestamp'] ?? null)),
			'url' => (string)($event['url'] ?? ''),
			'bounceType' => $bounceType,
			'reason' => (string)($event['reason'] ?? ''),
		];
	}                         

	   
                                                        
   
                 
                                                           
                                                                          
                                                    
   
                                                        
   
                                                           
    
	private function locateDelivery(array $event): ?array {
		$deliveryId = (string)($event['blastDeliveryId'] ?? '');
		if ($deliveryId !== '') {
			$row = $this->loadDeliveryById(id: $deliveryId);
			if ($row !== null) {
				return $row;
			}
		}

		$providerId = (string)($event['providerId'] ?? '');
		if ($providerId !== '') {
			$rows = $this->findDeliveries(filters: ['providerId' => $providerId]);
			if ($rows !== []) {
				return $rows[0];
			}
		}

		$contactId = (string)($event['contactId'] ?? '');
		$blastId = (string)($event['blastId'] ?? '');
		if ($contactId !== '' && $blastId !== '') {
			$rows = $this->findDeliveries(filters: ['contactId' => $contactId, 'blastId' => $blastId]);
			if ($rows !== []) {
				return $rows[0];
			}
		}

		return null;
	}                      

	   
                                       
                                                                     
                                                 
   
                                                   
   
                                         
    
	private function blastIdOrNull(string $blastId): ?string {
		if ($blastId === '') {
			return null;
		}

		return $blastId;
	}                     

	   
                                                     
   
                                                     
   
                                
    
	private function extractTimestamp(array $event): string {
		return $this->coerceTimestamp(raw: ($event['timestamp'] ?? null));
	}                        

	   
                                                                    
   
                                                          
   
                                    
   
                                 
    
	private function coerceTimestamp(mixed $raw): string {
		if ($raw === null || $raw === '') {
			return gmdate('Y-m-d\TH:i:s\Z');
		}

		if (is_int($raw) === true || (is_string($raw) === true && ctype_digit($raw) === true)) {
			return gmdate('Y-m-d\TH:i:s\Z', (int)$raw);
		}

		if (is_string($raw) === true) {
			$parsed = strtotime($raw);
			if ($parsed !== false) {
				return gmdate('Y-m-d\TH:i:s\Z', $parsed);
			}
		}

		return gmdate('Y-m-d\TH:i:s\Z');
	}                       

	   
                                                          
   
                                        
   
                                                           
    
	private function extractUtmCampaign(string $url): string {
		if ($url === '') {
			return '';
		}

		$query = parse_url($url, PHP_URL_QUERY);
		if (is_string($query) === false || $query === '') {
			return '';
		}

		parse_str($query, $params);
		return (string)($params['utm_campaign'] ?? '');
	}                          

	   
                                                                    
                                                                          
   
                                                  
                                                     
   
                                
    
	private function incrementSoftBounceCounter(string $contactId, string $channel): int {
		$key = $this->softBounceKey(contactId: $contactId, channel: $channel);
		$current = (int)$this->appConfig->getValueString(Application::APP_ID, $key, '0');
		$next = ($current + 1);
		$this->appConfig->setValueString(Application::APP_ID, $key, (string)$next);
		return $next;
	}                                  

	   
                                                             
   
                                                  
                                   
   
                
    
	private function clearSoftBounceCounter(string $contactId, string $channel): void {
		$key = $this->softBounceKey(contactId: $contactId, channel: $channel);
		try {
			$this->appConfig->deleteKey(Application::APP_ID, $key);
		} catch (Throwable $e) {
			$this->logger->info(
				'WebhookProcessorService.clearSoftBounceCounter: delete failed',
				['contactId' => $contactId, 'channel' => $channel, 'exception' => $e->getMessage()]
			);
		}
	}                              

	   
                                                             
   
                                                  
                                   
   
                                  
    
	private function softBounceKey(string $contactId, string $channel): string {
		return self::SOFT_BOUNCE_KEY_PREFIX . $contactId . '.' . $channel;
	}                     

	   
                                                                 
   
                                
    
	private function resolveSoftBounceThreshold(): int {
		$raw = $this->appConfig->getValueString(
			Application::APP_ID,
			self::APP_CONFIG_SOFT_BOUNCE_THRESHOLD,
			(string)self::DEFAULT_SOFT_BOUNCE_THRESHOLD,
		);
		if ($raw === '' || is_numeric($raw) === false) {
			return self::DEFAULT_SOFT_BOUNCE_THRESHOLD;
		}

		$value = (int)$raw;
		if ($value < 1) {
			return self::DEFAULT_SOFT_BOUNCE_THRESHOLD;
		}

		return $value;
	}                                  

	   
                                     
   
                                                 
   
                                                           
    
	private function loadDeliveryById(string $id): ?array {
		$register = $this->getRegisterSlug();
		$schema = $this->getBlastDeliverySchemaSlug();
		if ($register === '' || $schema === '') {
			return null;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$entity = $objectService->find(id: $id, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->info(
				'WebhookProcessorService.loadDeliveryById: not found',
				['id' => $id, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $this->toArray(value: $entity);
	}                        

	   
                                                  
   
                                                    
   
                                                           
    
	private function findDeliveries(array $filters): array {
		$register = $this->getRegisterSlug();
		$schema = $this->getBlastDeliverySchemaSlug();
		if ($register === '' || $schema === '') {
			return [];
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		try {
			                                                                        
			                                                                           
			                                                                          
			                                                                       
			                                                                       
			                                                                          
			$rows = $objectService->findAll(
				config: [
					'filters' => array_merge(
						$filters,
						[
							'register' => $register,
							'schema' => $schema,
						]
					),
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'WebhookProcessorService.findDeliveries: findAll failed',
				['filters' => $filters, 'exception' => $e->getMessage()]
			);
			return [];
		}         

		$out = [];
		foreach (($rows ?? []) as $row) {
			$out[] = $this->toArray(value: $row);
		}

		return $out;
	}                      

	   
                                                      
   
                                                          
                                  
   
                
    
	private function saveDelivery(array $payload, string $id): void {
		$register = $this->getRegisterSlug();
		$schema = $this->getBlastDeliverySchemaSlug();
		if ($register === '' || $schema === '' || $id === '') {
			return;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return;
		}

		try {
			$objectService->saveObject(
				object: $payload,
				register: $register,
				schema: $schema,
				uuid: $id,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'WebhookProcessorService.saveDelivery: save failed',
				['id' => $id, 'exception' => $e->getMessage()]
			);
		}

		$blastId = (string)($payload['blastId'] ?? '');
		if ($blastId !== '') {
			try {
				$this->blastService->updateBlastTotals(blastId: $blastId);
			} catch (Throwable $e) {
				$this->logger->info(
					'WebhookProcessorService.saveDelivery: updateBlastTotals failed',
					['blastId' => $blastId, 'exception' => $e->getMessage()]
				);
			}
		}
	}                    

	   
                                               
   
                                                 
   
                                                  
    
	private function extractId(array $payload): string {
		$searchSpaces = [$payload];
		$self = ($payload['@self'] ?? null);
		if (is_array($self) === true) {
			$searchSpaces[] = $self;
		}

		foreach ($searchSpaces as $space) {
			foreach (['uuid', 'id', 'slug'] as $key) {
				$value = ($space[$key] ?? null);
				if (is_scalar($value) === true && (string)$value !== '') {
					return (string)$value;
				}
			}
		}

		return '';
	}                 

	   
                                              
   
                        
    
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_REGISTER_SLUG;
	}                       

	   
                                                          
   
                        
    
	private function getBlastDeliverySchemaSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'blastDelivery_schema', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_BLAST_DELIVERY_SCHEMA_SLUG;
	}                                  

	   
                                              
   
                                                                     
    
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'WebhookProcessorService.getObjectService: OpenRegister unavailable',
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
}           
