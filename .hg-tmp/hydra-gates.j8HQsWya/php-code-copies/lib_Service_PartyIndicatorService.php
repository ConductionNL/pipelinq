<?php

   
                                  
  
                                                                               
                                                                               
                                                                  
  
                                                                          
  
                                                                        
                                                                        
                                                                            
                                                                              
                                                                              
                                                                               
  
                                                                               
                                                                            
                                                                       
                                                                              
                                                            
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                            
  
                                                                                                                                                                                                
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                      
  
                                                                                                                                                                                                             
   
class PartyIndicatorService {
	   
                                                                   
   
                              
    
	public const ACTS = [
		'send' => 'blocksOutbound',
		'publishAddress' => 'blocksAddressPublication',
		'handle' => 'requiresAcknowledgement',
	];

	   
                                                 
   
            
    
	private const VALUE_LIMIT = 200;

	   
                
   
                                                                        
                                                                               
                                                                                     
                                              
    
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}                   

	   
                                                 
   
                                                                         
                                                                         
                         
   
                                                                  
                                                                                  
   
                                             
   
                                                                                                                                                                                                 
    
	public function applies(array $value, ?DateTimeImmutable $onDay = null): bool {
		$onDay = ($onDay ?? new DateTimeImmutable('today'));

		$from = $this->day(value: ($value['validFrom'] ?? null));
		if ($from !== null && $from > $onDay) {
			return false;
		}

		$until = $this->day(value: ($value['validUntil'] ?? null));

		return ($until === null || $until >= $onDay);
	}               

	   
                                                         
   
                                                   
                                                                                 
   
                                                                          
                                                                         
   
                                                                                                                                                                                                              
    
	public function resolve(string $partyId, ?DateTimeImmutable $onDay = null): array {
		$partyId = trim($partyId);
		if ($partyId === '') {
			return [];
		}

		$vocabulary = $this->vocabulary();
		$resolved = [];

		foreach ($this->valuesFor(partyId: $partyId) as $value) {
			if ($this->applies(value: $value, onDay: $onDay) === false) {
				continue;
			}

			$code = (string)($value['indicator'] ?? '');
			$declared = ($vocabulary[$code] ?? null);
			if ($declared === null) {
				                                                            
				                                                             
				                                                          
				$this->logger->warning(
					'PartyIndicatorService: an indicator value names an undeclared indicator',
					['party' => $partyId, 'indicator' => $code]
				);
				$declared = ['code' => $code, 'label' => $code, 'severity' => 'warning'];
			}

			$resolved[] = [
				'id' => (string)($value['id'] ?? $value['uuid'] ?? ''),
				'code' => $code,
				'label' => (string)($declared['label'] ?? $code),
				'severity' => (string)($declared['severity'] ?? 'warning'),
				'blocksOutbound' => (bool)($declared['blocksOutbound'] ?? false),
				'blocksAddressPublication' => (bool)($declared['blocksAddressPublication'] ?? false),
				'requiresAcknowledgement' => (bool)($declared['requiresAcknowledgement'] ?? false),
				'validFrom' => (string)($value['validFrom'] ?? ''),
				'validUntil' => (string)($value['validUntil'] ?? ''),
				'source' => (string)($value['source'] ?? ''),
				'acknowledgedBy' => (string)($value['acknowledgedBy'] ?? ''),
				'acknowledgedAt' => (string)($value['acknowledgedAt'] ?? ''),
			];
		}

		return $resolved;
	}               

	   
                                                                 
   
                                                                           
                                                                       
   
                                                   
                                            
                                                                               
   
                                                                                           
                 
   
                                                                                  
   
                                                                                                                                                                                                                       
    
	public function isBlocked(string $partyId, string $act, ?DateTimeImmutable $onDay = null): array {
		$effect = (self::ACTS[$act] ?? null);
		if ($effect === null) {
			throw new InvalidArgumentException(
				"Unknown act {$act}; this service answers about " . implode(', ', array_keys(self::ACTS)) . '.'
			);
		}

		$blocking = array_values(
			array_filter(
				$this->resolve(partyId: $partyId, onDay: $onDay),
				static fn (array $indicator): bool => ($indicator[$effect] ?? false) === true
			)
		);

		return [
			'blocked' => ($blocking !== []),
			'act' => $act,
			'indicators' => $blocking,
		];
	}                 

	   
                                                                
   
                                                      
   
                                                                                  
   
                                                                                                                                                                                 
    
	public function acknowledge(string $valueId): array {
		$valueId = trim($valueId);
		$schema = $this->schemaId(key: 'partyIndicatorValue_schema');
		if ($valueId === '' || $schema === '') {
			return ['status' => 400, 'error' => 'An indicator value is required.'];
		}

		try {
			$entity = $this->objectService->find(id: $valueId);
		} catch (Throwable $e) {
			$this->logger->debug(
				'PartyIndicatorService: could not read the indicator value',
				['uuid' => $valueId, 'exception' => $e->getMessage()]
			);
			$entity = null;
		}

		if ($entity === null) {
			return ['status' => 404, 'error' => 'That indicator value could not be read.'];
		}

		$data = $entity->jsonSerialize();
		if (is_array($data) === false) {
			return ['status' => 404, 'error' => 'That indicator value could not be read.'];
		}

		$data['acknowledgedBy'] = ($this->userSession->getUser()?->getUID() ?? '');
		$data['acknowledgedAt'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

		try {
			$this->objectService->saveObject(
				object: $data,
				register: $this->registerId(),
				schema: $schema,
				uuid: $valueId,
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'PartyIndicatorService: the acknowledgement could not be saved',
				['uuid' => $valueId, 'exception' => $e->getMessage()]
			);

			return ['status' => 500, 'error' => 'The acknowledgement could not be saved.'];
		}

		return ['status' => 200, 'indicatorValue' => $data];
	}                   

	   
                                                         
   
                                                                          
                                                                           
                                                                       
                                                                     
                                                       
   
                                                                                  
                                                                              
   
                                                                           
   
                                                                                                                                                                                                 
    
	public function unionForMerge(array $winner, array $loser): array {
		$union = [];

		foreach (array_merge($winner, $loser) as $value) {
			$code = trim((string)($value['indicator'] ?? $value['code'] ?? ''));
			if ($code === '') {
				continue;
			}

			if (isset($union[$code]) === false) {
				$union[$code] = $value;
				continue;
			}

			                                                                
			                                                                 
			                                                                
			$union[$code] = $this->widerPeriod(a: $union[$code], b: $value);
		}

		return array_values($union);
	}                     

	   
                                                             
   
                                             
                                             
   
                                                      
    
	private function widerPeriod(array $a, array $b): array {
		$untilA = $this->day(value: ($a['validUntil'] ?? null));
		$untilB = $this->day(value: ($b['validUntil'] ?? null));

		if ($untilA === null) {
			return $a;
		}

		if ($untilB === null) {
			return $b;
		}

		if ($untilB > $untilA) {
			return $b;
		}

		return $a;
	}                   

	   
                                           
   
                                                               
    
	private function vocabulary(): array {
		$rows = $this->read(schemaKey: 'partyIndicator_schema', filters: []);

		$byCode = [];
		foreach ($rows as $row) {
			$code = trim((string)($row['code'] ?? ''));
			if ($code !== '') {
				$byCode[$code] = $row;
			}
		}

		return $byCode;
	}                  

	   
                                              
   
                                                   
   
                                                        
    
	private function valuesFor(string $partyId): array {
		return $this->read(
			schemaKey: 'partyIndicatorValue_schema',
			filters: ['party' => $partyId],
		);
	}                 

	   
                                            
   
                                                                          
                                                                      
                                                 
   
                                                                      
                                                       
   
                                                      
    
	private function read(string $schemaKey, array $filters): array {
		$register = $this->registerId();
		$schema = $this->schemaId(key: $schemaKey);
		if ($register === '' || $schema === '') {
			$this->logger->debug(
				'PartyIndicatorService: the indicator surface is not configured',
				['schemaKey' => $schemaKey]
			);

			return [];
		}

		try {
			$rows = $this->objectService->findAll(
				[
					'filters' => array_merge(['register' => $register, 'schema' => $schema], $filters),
					'limit' => self::VALUE_LIMIT,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'PartyIndicatorService: the indicator read failed',
				['schemaKey' => $schemaKey, 'exception' => $e->getMessage()]
			);

			return [];
		}

		$out = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$out[] = $row;
				continue;
			}

			if (($row instanceof \JsonSerializable) === true) {
				$data = $row->jsonSerialize();
				if (is_array($data) === true) {
					$out[] = $data;
				}
			}
		}

		return $out;
	}            

	   
                             
   
                                                                     
                                                                        
                                                                        
                                                                        
                                                                     
   
                                                          
    
	private function registerId(): string {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($registerId === '') {
			$this->logger->warning(
				'PartyIndicatorService: app-config "register" is not configured; indicator reads and writes are refused, not run unscoped'
			);
		}

		return $registerId;
	}                  

	   
                                      
   
                                          
   
                                                        
    
	private function schemaId(string $key): string {
		return $this->appConfig->getValueString(Application::APP_ID, $key, '');
	}                

	   
                                                              
   
                                         
   
                                           
    
	private function day(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable((new DateTimeImmutable($value))->format('Y-m-d'));
		} catch (Throwable $e) {
			return null;
		}
	}           
}           
