<?php

   
                                     
  
                                                                               
                                                                          
                                       
  
                                                                            
                                                                            
                                                                               
                                                                             
              
  
                                                                           
                                                                               
                                                                           
                                                             
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                            
  
                                                                                                                                                                        
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                               
  
                                                                                                                                                                                         
   
class PartyKindRegistryService {
	   
                                              
   
            
    
	private const LIMIT = 500;

	   
                
   
                                                                        
                                                                               
                                              
    
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}                   

	   
                                       
   
                                                                         
                                                                        
                                           
   
                                                               
   
                                                                                                                                                                         
    
	public function vocabulary(): array {
		$byCode = [];
		foreach ($this->read(schemaKey: 'partyKind_schema', filters: []) as $row) {
			$code = trim((string)($row['code'] ?? ''));
			if ($code !== '') {
				$byCode[$code] = $row;
			}
		}

		return $byCode;
	}                  

	   
                                                                     
   
                                                                            
                                                                   
                                                                          
                            
   
                                                                  
   
                                                                         
   
                                                                                                                                                                                            
    
	public function kindsFor(string $recordType): array {
		$vocabulary = $this->vocabulary();
		$declared = $this->acceptanceFor(recordType: $recordType);

		if ($declared === null) {
			return array_values(
				array_filter(
					$vocabulary,
					static fn (array $kind): bool => ($kind['active'] ?? true) !== false
				)
			);
		}

		$offered = [];
		foreach ($declared as $code) {
			$kind = ($vocabulary[$code] ?? null);
			if ($kind === null) {
				$this->logger->warning(
					'PartyKindRegistryService: an acceptance names a kind nobody declares',
					['recordType' => $recordType, 'kind' => $code]
				);
				continue;
			}

			$offered[] = $kind;
		}

		return $offered;
	}                

	   
                                                                            
   
                                                                           
                                                                          
                                                  
   
                                                                  
   
                                                                
    
	public function acceptanceFor(string $recordType): ?array {
		$recordType = trim($recordType);
		if ($recordType === '') {
			return null;
		}

		                                                                     
		                                                    
		$rows = $this->read(
			schemaKey: 'partyKindAcceptance_schema',
			filters: ['recordType' => $recordType],
		);

		foreach ($rows as $row) {
			if ((string)($row['recordType'] ?? '') !== $recordType) {
				continue;
			}

			$kinds = ($row['kinds'] ?? []);
			if (is_array($kinds) === false) {
				return [];
			}

			return array_values(array_map(static fn (mixed $k): string => trim((string)$k), $kinds));
		}

		return null;
	}                     

	   
                                                           
   
                                                                            
                                                        
   
                                                                  
                                                                    
                                            
                                                
   
                                                                            
                      
   
                                                                                                                                                                                          
    
	public function mayLink(string $recordType, string $recordId, string $kind, string $party = ''): array {
		$recordType = trim($recordType);
		$kind = trim($kind);

		if ($kind === '') {
			return ['allowed' => false, 'reason' => 'A party kind is required.'];
		}

		$declared = $this->acceptanceFor(recordType: $recordType);
		if ($declared !== null && in_array($kind, $declared, true) === false) {
			return [
				'allowed' => false,
				'reason' => "{$recordType} does not accept a party of kind {$kind}.",
			];
		}

		$vocabulary = $this->vocabulary();
		$declaredKind = ($vocabulary[$kind] ?? null);
		$maxPerRecord = (int)($declaredKind['maxPerRecord'] ?? 0);
		if ($maxPerRecord !== 1) {
			return ['allowed' => true, 'reason' => ''];
		}

		foreach ($this->linksOn(recordType: $recordType, recordId: $recordId) as $link) {
			if ((string)($link['kind'] ?? '') !== $kind) {
				continue;
			}

			if (trim((string)($link['validUntil'] ?? '')) !== '') {
				                                                        
				                                                               
				continue;
			}

			$holder = (string)($link['party'] ?? '');
			if ($holder === $party) {
				continue;
			}

			return [
				'allowed' => false,
				'reason' => "This record already holds a {$kind}: party {$holder}.",
			];
		}

		return ['allowed' => true, 'reason' => ''];
	}               

	   
                                                                     
   
                                                                          
                                                                          
                                                
   
                                                                  
                                       
                                                                                
   
                                                                                                          
                          
   
                                                                                                                                                                                          
    
	public function judgeBatch(string $recordType, string $recordId, array $rows): array {
		$accepted = [];
		$refused = [];
		$taken = [];

		foreach ($rows as $row) {
			$kind = trim((string)($row['kind'] ?? ''));
			$party = trim((string)($row['party'] ?? ''));

			$answer = $this->mayLink(
				recordType: $recordType,
				recordId: $recordId,
				kind: $kind,
				party: $party,
			);

			                                                                
			                                                                 
			if ($answer['allowed'] === true && isset($taken[$kind]) === true) {
				$vocabulary = $this->vocabulary();
				if ((int)($vocabulary[$kind]['maxPerRecord'] ?? 0) === 1) {
					$answer = [
						'allowed' => false,
						'reason' => "This record already holds a {$kind}: party {$taken[$kind]}.",
					];
				}
			}

			if ($answer['allowed'] === true) {
				$accepted[] = ['party' => $party, 'kind' => $kind];
				$taken[$kind] = $party;
				continue;
			}

			$refused[] = ['party' => $party, 'kind' => $kind, 'reason' => $answer['reason']];
		}

		return ['accepted' => $accepted, 'refused' => $refused];
	}                  

	   
                                              
   
                                                                  
                                       
   
                                                       
    
	public function linksOn(string $recordType, string $recordId): array {
		$recordId = trim($recordId);
		if ($recordId === '') {
			return [];
		}

		return $this->read(
			schemaKey: 'partyLink_schema',
			filters: ['recordType' => trim($recordType), 'recordId' => $recordId],
		);
	}               

	   
                                            
   
                                                                      
                                                       
   
                                                      
    
	private function read(string $schemaKey, array $filters): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
		if ($register === '' || $schema === '') {
			$this->logger->debug(
				'PartyKindRegistryService: the party kind surface is not configured',
				['schemaKey' => $schemaKey]
			);

			return [];
		}

		try {
			$rows = $this->objectService->findAll(
				[
					'filters' => array_merge(['register' => $register, 'schema' => $schema], $filters),
					'limit' => self::LIMIT,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'PartyKindRegistryService: the read failed',
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
}           
