<?php

   
                                           
  
                                                                    
                                                                          
                                                                          
                                                                           
                                                                            
                                                        
  
                                                                              
                                                                             
                                                                               
                                                                            
                                            
  
                    
                                 
  
                                                              
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                                                 
  
                                                                                                                    
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceItem;
use OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceSourceProvider;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

   
                                                                 
  
                                                                                                                    
   
class PipelinqEvidenceSourceProvider implements EvidenceSourceProvider {
	   
                                                      
   
               
    
	public const SOURCE_ID = 'pipelinq-crm';

	   
                                                                   
                                                                           
                                                                             
                                                         
   
                                                                                
                                                           
   
                                          
    
	private const SUBJECT_LINKS = [
		'client' => ['@self'],
		'contact' => ['@self', 'client'],
		'lead' => ['client', 'contact'],
		'ticket_request' => ['client', 'contact'],
		'ticket_contactmoment' => ['client', 'contact'],
	];

	   
                                                                             
   
                              
    
	private const SOURCE_SCHEMA_KEYS = [
		'client' => 'client_schema',
		'contact' => 'contact_schema',
		'lead' => 'lead_schema',
	];

	   
                                                                    
                                                                           
                                                                              
                                     
   
                              
    
	private const SOURCE_TICKET_TYPES = [
		'ticket_request' => TicketService::TYPE_REQUEST,
		'ticket_contactmoment' => TicketService::TYPE_CONTACTMOMENT,
	];

	   
                
   
                                                                  
                                                                                      
                                                                                
                                                                                    
    
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly TicketService $ticketService,
	) {
	}                   

	   
                                  
   
                                  
   
                                                                                                                     
    
	public function getSourceId(): string {
		return self::SOURCE_ID;
	}                   

	   
                                                                            
                                                  
   
                                                  
   
                                                                                                                     
    
	public function isEnabled(): bool {
		return $this->resolveContext() !== null;
	}                 

	   
                                                                       
   
                                                 
                                                                                         
   
                                                                                  
   
                                                                                                                     
    
	public function harvest(string $caseUuid, array $case): array {
		$subjectId = (string)($case['subjectId'] ?? '');
		if ($subjectId === '') {
			return [];
		}

		$context = $this->resolveContext();
		if ($context === null) {
			return [];
		}

		[$objectService, $registerId, $schemaIds] = $context;

		$items = [];
		foreach (self::SUBJECT_LINKS as $sourceKey => $linkFields) {
			$schemaId = $schemaIds[$sourceKey];

			$rows = $this->safeFindAll(
				objectService: $objectService,
				registerId: $registerId,
				schemaId: $schemaId,
				ticketType: (self::SOURCE_TICKET_TYPES[$sourceKey] ?? null)
			);

			foreach ($rows as $row) {
				$data = $this->rowToArray(row: $row);
				if ($data === null) {
					continue;
				}

				if ($this->matchesSubject(data: $data, subjectId: $subjectId, linkFields: $linkFields) === false) {
					continue;
				}

				$items[] = new EvidenceItem(
					self::SOURCE_ID,
					$this->contentHash(sourceKey: $sourceKey, data: $data),
					EvidenceItem::STATUS_COLLECTED,
					[
						'schema' => $sourceKey,
						'objectId' => (string)($data['id'] ?? ($data['uuid'] ?? '')),
						'caseUuid' => $caseUuid,
					]
				);
			}             
		}             

		$this->logger->info(
			'Pipelinq DSAR evidence harvest complete',
			['case' => $caseUuid, 'items' => count($items)]
		);

		return $items;
	}               

	   
                                                                           
   
                                                      
                                                         
                                                                                
   
                                                             
    
	private function matchesSubject(array $data, string $subjectId, array $linkFields): bool {
		foreach ($linkFields as $field) {
			if ($field === '@self') {
				$ownId = (string)($data['id'] ?? ($data['uuid'] ?? ''));
				$contactsUid = (string)($data['contactsUid'] ?? '');
				if ($ownId === $subjectId || ($contactsUid !== '' && $contactsUid === $subjectId)) {
					return true;
				}

				continue;
			}

			if ((string)($data[$field] ?? '') === $subjectId) {
				return true;
			}
		}

		return false;
	}                      

	   
                                                                       
   
                                                      
                                                      
   
                                             
    
	private function contentHash(string $sourceKey, array $data): string {
		$objectId = (string)($data['id'] ?? ($data['uuid'] ?? ''));
		return 'sha256:' . hash('sha256', $sourceKey . ':' . $objectId);
	}                   

	   
                                                       
   
                                                                                
                                                               
   
                                            
   
                                                           
    
	private function resolveSourceSchemaId(string $sourceKey): string {
		if (isset(self::SOURCE_TICKET_TYPES[$sourceKey]) === true) {
			return $this->ticketService->getSchemaId();
		}

		$configKey = (self::SOURCE_SCHEMA_KEYS[$sourceKey] ?? '');
		if ($configKey === '') {
			return '';
		}

		return $this->appConfig->getValueString(Application::APP_ID, $configKey, '');
	}                             

	   
                                                                  
   
                                                                             
                                                                         
   
                                                                      
                                                                                                                    
    
	private function resolveContext(): ?array {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($registerId === '') {
			return null;
		}

		$schemaIds = [];
		foreach (array_keys(self::SUBJECT_LINKS) as $sourceKey) {
			$schemaId = $this->resolveSourceSchemaId(sourceKey: $sourceKey);
			if ($schemaId === '') {
				return null;
			}

			$schemaIds[$sourceKey] = $schemaId;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			return null;
		}

		return [$objectService, $registerId, $schemaIds];
	}                      

	   
                                                            
   
                                                                       
                                                                                
   
                                                                
                                          
                                      
                                                                                  
   
                                                          
    
	private function safeFindAll(
		object $objectService,
		string $registerId,
		string $schemaId,
		?string $ticketType = null,
	): array {
		$filters = [
			'register' => $registerId,
			'schema' => $schemaId,
		];
		if ($ticketType !== null) {
			$filters['ticketType'] = $ticketType;
		}

		try {
			$rows = $objectService->findAll(
				[
					'filters' => $filters,
					'limit' => 5000,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq DSAR evidence harvest: schema read failed',
				['schema' => $schemaId, 'exception' => $e->getMessage()]
			);
			return [];
		}

		if (is_array($rows) === true) {
			return $rows;
		}

		return [];
	}                   

	   
                                                                         
   
                              
   
                                                                        
    
	private function rowToArray(mixed $row): ?array {
		if (is_array($row) === true) {
			return $row;
		}

		if ($row instanceof \JsonSerializable === true) {
			$data = $row->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return null;
	}                  
}           
