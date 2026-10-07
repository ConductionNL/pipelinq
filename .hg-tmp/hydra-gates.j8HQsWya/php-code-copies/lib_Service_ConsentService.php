<?php

   
                           
  
                                                                    
                                                                   
                                                              
                                                                 
                                                              
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                   
  
                       
                                                              
                   
                                                                  
                  
                                                                   
                   
                                                              
                                                           
                                                                    
                            
  
                                                                   
  
                                                                                                                                                 
                                                                                                                      
                                                                                                                     
   
class ConsentService {
	   
                                               
    
	private const DEFAULT_SCHEMA_SLUG = 'messagingConsentRecord';

	   
                                   
    
	private const DEFAULT_REGISTER_SLUG = 'pipelinq';

	   
                                                                      
   
                           
    
	private const OPT_OUT_KEYWORDS = ['stop', 'stopall', 'uitschrijven'];

	   
                                                                     
   
                           
    
	private const OPT_IN_KEYWORDS = ['ja', 'yes', 'start'];

	   
                
   
                                                      
                                            
                                          
                                                                                               
                                                                                     
   
                                                                    
    
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private IntegriqConsentClient $integriq,
		private ContactAddressLookup $addresses,
	) {
	}                   

	   
                                                                   
   
                                                                 
                                                                  
                                                   
                                                           
                       
   
                                                                            
                                                                       
   
                                          
                                               
                                                                                
                                                                                                
   
                                            
   
                                                                    
                                                                                                                                                               
    
	public function canSend(string $contactId, string $channel, string $address = '', ?string $inReplyTo = null): bool {
		if ($contactId === '' || $channel === '') {
			return false;
		}

		if ($this->integriq->isCutover() === true) {
			$category = IntegriqConsentClient::CATEGORY_SERVICE;
			if ($inReplyTo !== null && $inReplyTo !== '') {
				$category = IntegriqConsentClient::CATEGORY_REPLY;
			}

			return $this->ask(
				contactId: $contactId,
				channel: $channel,
				category: $category,
				requiresConsent: false,
				address: $address,
				inReplyTo: $inReplyTo
			)['send'];
		}

		$latest = $this->loadLatestRecord(contactId: $contactId, channel: $channel);
		if ($latest === null) {
			                                                          
			                                                          
			return true;
		}

		$state = (string)($latest['state'] ?? 'unknown');
		return ($state !== 'opted-out');
	}               

	   
                                                                    
   
                                                                        
                                                                  
                                                              
                                                                         
                                             
   
                                                              
   
                                          
                                               
                                                                                
   
                                                                
   
                                                                                                       
                                                                                                                                                               
    
	public function canSendBusinessInitiated(string $contactId, string $channel, string $address = ''): bool {
		if ($contactId === '' || $channel === '') {
			return false;
		}

		if ($this->integriq->isCutover() === true) {
			return $this->ask(
				contactId: $contactId,
				channel: $channel,
				category: IntegriqConsentClient::CATEGORY_SERVICE,
				requiresConsent: true,
				address: $address
			)['send'];
		}

		$latest = $this->loadLatestRecord(contactId: $contactId, channel: $channel);
		if ($latest === null) {
			return false;
		}

		return ((string)($latest['state'] ?? 'unknown')) === 'opted-in';
	}                                

	   
                                                               
   
                                                                    
                                                                             
                                                                
                                                         
   
                                          
                                               
   
                                                                           
                                                                        
                                                                      
                                  
   
                                                        
   
                                                                                                       
                                                                                                                                                        
                                                                                                                                            
    
	public function latestState(string $contactId, string $channel): string {
		if ($contactId === '' || $channel === '') {
			return 'unknown';
		}

		if ($this->integriq->isCutover() === true) {
			                                                               
			                                                 
			$decision = $this->integriq->probeOne(
				channel: $channel,
				category: IntegriqConsentClient::CATEGORY_SERVICE,
				requiresConsent: true,
				address: $this->addresses->addressFor(contactId: $contactId, channel: $channel),
				contactRef: $contactId
			);
			if ($decision['code'] === IntegriqConsentClient::CODE_OPTED_OUT) {
				return 'opted-out';
			}

			if ($decision['send'] === true && $decision['code'] === IntegriqConsentClient::CODE_ALLOWED) {
				return 'opted-in';
			}

			return 'unknown';
		}

		$latest = $this->loadLatestRecord(contactId: $contactId, channel: $channel);
		if ($latest === null) {
			return 'unknown';
		}

		$state = (string)($latest['state'] ?? 'unknown');
		if ($state === '') {
			return 'unknown';
		}

		return $state;
	}                   

	   
                                        
   
                                          
                                               
                                                                  
                                                           
                                                                                     
                                                                            
   
                                                                   
   
                                                                    
                                                                                                                                               
    
	public function recordOptIn(
		string $contactId,
		string $channel,
		string $source,
		string $evidence,
		string $legalBasis = 'consent',
		string $address = '',
	): ?array {
		if ($this->integriq->isCutover() === true) {
			$recorded = $this->recordInIntegriq(
				contactId: $contactId,
				channel: $channel,
				state: 'opted-in',
				source: $source,
				evidence: $evidence,
				legalBasis: $legalBasis,
				address: $address,
			);
			if ($recorded !== null) {
				return $recorded;
			}
		}

		return $this->appendRecord(
			contactId: $contactId,
			channel: $channel,
			state: 'opted-in',
			source: $source,
			evidence: $evidence,
			legalBasis: $legalBasis,
		);
	}                   

	   
                                         
   
                                          
                                               
                                                                           
                                                           
                                                                                     
                                                                            
   
                                                                   
   
                                                                    
                                                                                                                                               
    
	public function recordOptOut(
		string $contactId,
		string $channel,
		string $source,
		string $evidence,
		string $legalBasis = 'consent',
		string $address = '',
	): ?array {
		if ($this->integriq->isCutover() === true) {
			$recorded = $this->recordInIntegriq(
				contactId: $contactId,
				channel: $channel,
				state: 'opted-out',
				source: $source,
				evidence: $evidence,
				legalBasis: $legalBasis,
				address: $address,
			);
			if ($recorded !== null) {
				return $recorded;
			}
		}

		return $this->appendRecord(
			contactId: $contactId,
			channel: $channel,
			state: 'opted-out',
			source: $source,
			evidence: $evidence,
			legalBasis: $legalBasis,
		);
	}                    

	   
                                                       
   
                                             
   
                                                                           
   
                                                                    
    
	public function isOptOutKeyword(string $body): bool {
		$normalised = strtolower(trim($body));
		return in_array($normalised, self::OPT_OUT_KEYWORDS, true);
	}                       

	   
                                                      
   
                                             
   
                                                              
   
                                                                    
    
	public function isOptInKeyword(string $body): bool {
		$normalised = strtolower(trim($body));
		return in_array($normalised, self::OPT_IN_KEYWORDS, true);
	}                      

	   
                                                               
   
                                                                 
                                                            
   
                                          
   
                             
   
                                                                    
    
	public function deleteForContact(string $contactId): int {
		if ($contactId === '') {
			return 0;
		}

		$rows = $this->loadAllRecords(contactId: $contactId);
		if ($rows === []) {
			return 0;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return 0;
		}

		$register = $this->getRegisterSlug();
		$schema = $this->getSchemaSlug();
		$deleted = 0;

		foreach ($rows as $row) {
			$id = $this->extractId(payload: $row);
			if ($id === '') {
				continue;
			}

			try {
				if (method_exists($objectService, 'deleteObject') === true) {
					$objectService->deleteObject($id, $register, $schema);
					$deleted++;
				}
			} catch (Throwable $e) {
				$this->logger->warning(
					'ConsentService.deleteForContact: delete failed',
					['contactId' => $contactId, 'id' => $id, 'exception' => $e->getMessage()]
				);
			}
		}             

		return $deleted;
	}                        

	   
                                                   
   
                                                  
   
                                                                                                                                                                   
    
	public function allRecords(): array {
		return $this->loadRecords(filters: []);
	}                  

	   
                                                                         
   
                                                               
   
                                                                 
   
                                                                                                                                                                   
    
	public function latestPerPair(array $rows): array {
		$pairs = [];
		foreach ($rows as $row) {
			$key = (string)($row['contactId'] ?? '').'|'.(string)($row['channel'] ?? '');
			$pairs[$key][] = $row;
		}

		$latest = [];
		foreach ($pairs as $group) {
			$latest[] = $this->newestFirst(matching: $group)[0];
		}

		return $latest;
	}                     

	   
                                                    
   
                                                                    
                                                              
   
                                             
   
                                                                                                                                                                   
    
	public function changeRequestFor(array $row, string $address): array {
		$state = (string)($row['state'] ?? '');
		$request = [
			'address' => $address,
			'state' => $state,
			'scope' => 'channel',
			'channel' => (string)($row['channel'] ?? ''),
			'contactRef' => (string)($row['contactId'] ?? ''),
			'source' => (string)($row['source'] ?? ''),
			'evidence' => ['text' => (string)($row['evidence'] ?? ''), 'recordedAt' => (string)($row['recordedAt'] ?? '')],
			                                                                       
			                                              
			'legacyRef' => $this->extractId(payload: $row),
		];
		if ($state === 'opted-in') {
			$request['lawfulBasis'] = (string)($row['legalBasis'] ?? 'consent');
		}

		return $request;
	}                        

	   
                                                                      
   
                                                                        
                                                                       
                        
   
                                                                                   
   
                                                     
   
                                                                                                                                               
    
	public function replayPending(string $contactId = ''): array {
		$counts = ['replayed' => 0, 'failed' => 0];
		if ($this->integriq->isCutover() === false) {
			return $counts;
		}

		$filters = [];
		if ($contactId !== '') {
			$filters['contactId'] = $contactId;
		}

		$cutoverAt = (int)$this->appConfig->getValueString(Application::APP_ID, IntegriqConsentClient::CONFIG_CUTOVER_AT, '0');
		$pending = [];
		foreach ($this->loadRecords(filters: $filters) as $row) {
			$recordedAt = strtotime((string)($row['recordedAt'] ?? ''));
			if ($recordedAt !== false && $recordedAt >= $cutoverAt) {
				$pending[] = $row;
			}
		}

		foreach (array_reverse($this->newestFirst(matching: $pending)) as $row) {
			$address = $this->addresses->addressFor(contactId: (string)($row['contactId'] ?? ''), channel: (string)($row['channel'] ?? ''));
			$outcome = $this->integriq->record(request: $this->changeRequestFor(row: $row, address: $address));
			if ($outcome['recorded'] === true) {
				$counts['replayed']++;
				continue;
			}

			$counts['failed']++;
		}

		return $counts;
	}                     

	   
                                                  
   
                                                     
                                                    
                                                     
                                                                    
                                                                          
                                                                         
   
                                                                                                         
   
                                                                                              
    
	private function ask(
		string $contactId,
		string $channel,
		string $category,
		bool $requiresConsent,
		string $address = '',
		?string $inReplyTo = null,
	): array {
		if ($address === '') {
			$address = $this->addresses->addressFor(contactId: $contactId, channel: $channel);
		}

		return $this->integriq->decideOne(
			channel: $channel,
			category: $category,
			requiresConsent: $requiresConsent,
			address: $address,
			contactRef: $contactId,
			inReplyTo: $inReplyTo,
		);
	}           

	   
                                                                           
   
                                           
                                          
                                                        
                                          
                                                
                                                       
                                                                
   
                                                                                                 
    
	private function recordInIntegriq(
		string $contactId,
		string $channel,
		string $state,
		string $source,
		string $evidence,
		string $legalBasis,
		string $address,
	): ?array {
		                                                                             
		                                                                        
		if ($channel === '' || ($contactId === '' && $address === '')) {
			return null;
		}

		if ($address === '') {
			$address = $this->addresses->addressFor(contactId: $contactId, channel: $channel);
		}

		if ($contactId !== '') {
			$this->replayPending(contactId: $contactId);
		}

		$request = [
			'address' => $address,
			'state' => $state,
			'scope' => 'channel',
			'channel' => $channel,
			'contactRef' => $contactId,
			'source' => $source,
			'evidence' => ['text' => $evidence],
		];
		if ($state === 'opted-in') {
			$request['lawfulBasis'] = $legalBasis;
		}

		$outcome = $this->integriq->record(request: $request);
		if ($outcome['recorded'] === false) {
			return null;
		}

		return [
			'contactId' => $contactId,
			'channel' => $channel,
			'state' => $state,
			'source' => $source,
			'store' => IntegriqConsentClient::STORE_INTEGRIQ,
			'recordId' => $outcome['recordId'],
		];
	}                        

	   
                                                    
   
                                          
                                               
                                                              
                                     
                                           
                                               
   
                                                
    
	private function appendRecord(
		string $contactId,
		string $channel,
		string $state,
		string $source,
		string $evidence,
		string $legalBasis = 'consent',
	): ?array {
		if ($contactId === '' || $channel === '' || $state === '') {
			return null;
		}

		$basis = $legalBasis;
		if ($basis === '') {
			$basis = 'consent';
		}

		$payload = [
			'contactId' => $contactId,
			'channel' => $channel,
			'state' => $state,
			'source' => $source,
			'evidence' => $evidence,
			'recordedAt' => $this->nowIso(),
			'legalBasis' => $basis,
		];

		return $this->saveObject(payload: $payload);
	}                    

	   
                                                                 
   
                                          
                                   
   
                                                            
    
	private function loadLatestRecord(string $contactId, string $channel): ?array {
		$rows = $this->loadAllRecords(contactId: $contactId);
		if ($rows === []) {
			return null;
		}

		$matching = [];
		foreach ($rows as $row) {
			if ((string)($row['channel'] ?? '') === $channel) {
				$matching[] = $row;
			}
		}

		if ($matching === []) {
			return null;
		}

		return $this->newestFirst(matching: $matching)[0];
	}                        

	   
                                                 
   
                                                               
   
                                                                    
    
	private function newestFirst(array $matching): array {
		                                                                        
		                                                              
		                                                                    
		                                                                        
		                                                            
		usort(
			$matching,
			static function (array $a, array $b): int {
				$rawA = (string)($a['recordedAt'] ?? '');
				$rawB = (string)($b['recordedAt'] ?? '');
				$left = null;
				$right = null;
				try {
					$left = new DateTimeImmutable($rawA);
					$right = new DateTimeImmutable($rawB);
				} catch (Throwable $e) {
					unset($e);
				}

				                                                                
				                                                               
				if ($left !== null && $right !== null) {
					$cmp = ($right->format('U.u') <=> $left->format('U.u'));
					if ($cmp !== 0) {
						return $cmp;
					}
				}

				                                                             
				                                                     
				return strcmp($rawB, $rawA);
			}
		);

		return $matching;
	}                   

	   
                                                               
   
                                          
   
                                                  
    
	private function loadAllRecords(string $contactId): array {
		return $this->loadRecords(filters: ['contactId' => $contactId]);
	}                      

	   
                                 
   
                                                        
   
                                                  
    
	private function loadRecords(array $filters): array {
		$contactId = (string)($filters['contactId'] ?? '');
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
							'register' => $this->getRegisterSlug(),
							'schema' => $this->getSchemaSlug(),
						]
					),
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ConsentService.loadAllRecords: findAll failed',
				['contactId' => $contactId, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$out = [];
		foreach (($rows ?? []) as $row) {
			$out[] = $this->toArray(value: $row);
		}

		return $out;
	}                   

	   
                                       
   
                                                 
   
                                                        
    
	private function saveObject(array $payload): ?array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$saved = $objectService->saveObject(
				object: $payload,
				register: $this->getRegisterSlug(),
				schema: $this->getSchemaSlug(),
				uuid: null,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ConsentService.saveObject: save failed',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		return $this->toArray(value: $saved);
	}                  

	   
                                       
   
                                        
    
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'ConsentService.getObjectService: OpenRegister unavailable',
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
		foreach (['uuid', 'id', 'slug'] as $key) {
			if (isset($payload[$key]) === true && is_scalar($payload[$key]) === true && (string)$payload[$key] !== '') {
				return (string)$payload[$key];
			}
		}

		if (isset($payload['@self']) === true && is_array($payload['@self']) === true) {
			foreach (['uuid', 'id', 'slug'] as $key) {
				$value = ($payload['@self'][$key] ?? null);
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

	   
                                         
   
                        
    
	private function getSchemaSlug(): string {
		$slug = $this->appConfig->getValueString(
			Application::APP_ID,
			'messagingConsentRecord_schema',
			''
		);

		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_SCHEMA_SLUG;
	}                     

	   
                                                              
   
                                              
   
                                                                            
                                                                         
                                                                         
                                                                           
                                                                             
                                           
   
                                                                          
                                                        
   
                                                                         
                                                                            
                                                                         
   
                             
    
	private function nowIso(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
	}              
}           
