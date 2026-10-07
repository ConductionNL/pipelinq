<?php

   
                                  
  
                                                                       
                                                                       
                                                         
  
                                               
                                    
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                      
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                                            
  
                                                                         
                                                                            
  
                                                                        
                                                       
                                                            
                                                                     
                                                                
  
                                                                                    
                                                                                     
                                                                                   
                                                                                 
  
                                                      
   
class EntityActivityService {
	   
                                                                         
                                                  
    
	private const ALLOWED_ENTITY_TYPES = [
		'client',
		'contact',
		'lead',
		'request',
	];

	   
                                                      
    
	private const ALLOWED_TYPES = [
		'all',
		'notes',
		'contactmomenten',
	];

	   
                      
    
	private const DEFAULT_LIMIT = 20;

	   
                                                              
    
	private const MAX_LIMIT = 100;

	   
                                                                   
                                                                     
    
	private const PER_SCHEMA_CEILING = 500;

	   
                
   
                                                
                                              
                                                                         
                                                                          
                                                                           
                                                                             
                                                                                         
    
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly TicketService $ticketService,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                                          
   
                                                                     
                              
   
                                                          
   
                                               
   
                                                       
    
	public function isAllowedEntityType(string $entityType): bool {
		return in_array($entityType, self::ALLOWED_ENTITY_TYPES, true);
	}                           

	   
                                          
   
                                             
                                                              
                                               
                                           
                                                      
                                         
                                           
                                       
   
                                                                                      
   
                                                                     
   
                                                       
    
	public function getActivity(
		string $entityType,
		string $entityId,
		string $type,
		int $page,
		int $limit,
	): array {
		if ($this->isAllowedEntityType(entityType: $entityType) === false) {
			throw new InvalidArgumentException('Invalid entity type');
		}

		$filterType = $this->normaliseTypeFilter(rawType: $type);

		$page = max(1, $page);
		$limit = $this->normaliseLimit(rawLimit: $limit);

		$registerId = $this->appConfig->getValueString(
			Application::APP_ID,
			'register',
			''
		);
		if ($registerId === '') {
			return $this->emptyResult(page: $page);
		}

		$items = [];

		if ($filterType === 'all' || $filterType === 'contactmomenten') {
			$items = array_merge(
				$items,
				$this->collectContactmomenten(
					registerId: $registerId,
					entityType: $entityType,
					entityId: $entityId
				)
			);
		}

		if ($filterType === 'all' || $filterType === 'notes') {
			$items = array_merge(
				$items,
				$this->collectNotes(
					registerId: $registerId,
					entityType: $entityType,
					entityId: $entityId
				)
			);
		}

		usort(
			$items,
			static function (array $left, array $right): int {
				return strcmp(
					(string)($right['timestamp'] ?? ''),
					(string)($left['timestamp'] ?? '')
				);
			}
		);

		return $this->paginate(items: $items, page: $page, limit: $limit);
	}                   

	   
                                              
   
                                                                     
                                                             
   
                                                            
   
                                                      
    
	private function normaliseTypeFilter(string $rawType): string {
		$candidate = strtolower(trim($rawType));
		if (in_array($candidate, self::ALLOWED_TYPES, true) === false) {
			return 'all';
		}

		return $candidate;
	}                           

	   
                                                                
   
                                                            
   
                                  
    
	private function normaliseLimit(int $rawLimit): int {
		if ($rawLimit <= 0) {
			return self::DEFAULT_LIMIT;
		}

		if ($rawLimit > self::MAX_LIMIT) {
			return self::MAX_LIMIT;
		}

		return $rawLimit;
	}                      

	   
                                                                
   
                                                            
                                                                              
                                                                            
                                                        
   
                                                         
                                                        
                                               
   
                                                                     
    
	private function collectContactmomenten(
		string $registerId,
		string $entityType,
		string $entityId,
	): array {
		$schemaId = $this->ticketService->getSchemaId();
		if ($schemaId === '') {
			return [];
		}

		$linkField = $this->contactmomentLinkField(entityType: $entityType);
		if ($linkField === null) {
			                                                             
			                                             
			return [];
		}

		$objects = $this->queryObjects(
			registerId: $registerId,
			schemaId: $schemaId,
			filters: [
				'ticketType' => TicketService::TYPE_CONTACTMOMENT,
				$linkField => $entityId,
			]
		);

		$items = [];
		foreach ($objects as $object) {
			$items[] = [
				'type' => 'interaction',
				'id' => (string)($object['id'] ?? $object['uuid'] ?? ''),
				'subject' => (string)($object['title'] ?? ''),
				'channel' => (string)($object['channel'] ?? ''),
				                                                                
				                                                                 
				                                               
				'direction' => (string)($object['direction'] ?? ''),
				'agent' => (string)($object['assignee'] ?? ''),
				'timestamp' => (string)($object['occurredAt'] ?? ''),
				'summary' => (string)($object['description'] ?? ''),
			];
		}

		return $items;
	}                              

	   
                                                                             
                                                                         
                                                                            
   
                                                                           
                           
   
                                                        
   
                                                          
    
	private function contactmomentLinkField(string $entityType): ?string {
		return match ($entityType) {
			'client' => 'client',
			'request' => 'parentTicket',
			default => null,
		};
	}                              

	   
                                                                        
                                                           
   
                                                         
                                                        
                                               
   
                                                                     
    
	private function collectNotes(
		string $registerId,
		string $entityType,
		string $entityId,
	): array {
		$schemaId = $this->entitySchemaId(entityType: $entityType);
		if ($schemaId === '') {
			return [];
		}

		$object = $this->findObject(
			entityId: $entityId,
			registerId: $registerId,
			schemaId: $schemaId
		);
		if ($object === null) {
			return [];
		}

		$rawNotes = ($object['notes'] ?? null);
		if (is_array($rawNotes) === false) {
			return [];
		}

		$items = [];
		foreach ($rawNotes as $note) {
			if (is_array($note) === false) {
				continue;
			}

			$items[] = [
				'type' => 'note',
				'id' => (string)($note['id'] ?? ''),
				'subject' => (string)($note['title'] ?? ''),
				'agent' => (string)(
					$note['author'] ?? $note['user'] ?? ''
				),
				'timestamp' => (string)(
					$note['createdAt'] ?? $note['timestamp'] ?? ''
				),
				'summary' => (string)(
					$note['message'] ?? $note['content'] ?? $note['text'] ?? ''
				),
			];
		}             

		return $items;
	}                    

	   
                                                               
   
                                                                              
                                                               
                                                                         
   
                                                        
   
                                                          
    
	private function entitySchemaId(string $entityType): string {
		if ($entityType === 'request') {
			return $this->ticketService->getSchemaId();
		}

		return $this->appConfig->getValueString(
			Application::APP_ID,
			$entityType . '_schema',
			''
		);
	}                      

	   
                                                           
                         
   
                                              
                                          
                                                         
   
                                                                 
    
	private function queryObjects(
		string $registerId,
		string $schemaId,
		array $filters,
	): array {
		try {
			$objectService = $this->getObjectService();
			$params = [
				'filters' => array_merge(
					[
						'register' => $registerId,
						'schema' => $schemaId,
					],
					$filters
				),
				'limit' => self::PER_SCHEMA_CEILING,
			];

			$results = $objectService->findAll($params);
			return $this->normaliseResults(results: $results);
		} catch (Throwable $e) {
			$this->logger->error(
				'EntityActivityService: failed to query schema',
				[
					'exception' => $e->getMessage(),
					'schemaId' => $schemaId,
				]
			);
			return [];
		}         
	}                    

	   
                                                                      
   
                                               
                                              
                                          
   
                                                                 
    
	private function findObject(
		string $entityId,
		string $registerId,
		string $schemaId,
	): ?array {
		try {
			$objectService = $this->getObjectService();
			$entity = $objectService->find(
				id: $entityId,
				register: $registerId,
				schema: $schemaId
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'EntityActivityService: failed to load entity for notes',
				[
					'exception' => $e->getMessage(),
					'entityId' => $entityId,
				]
			);
			return null;
		}

		if ($entity === null) {
			return null;
		}

		if (is_array($entity) === true) {
			return $entity;
		}

		if (is_object($entity) === true
			&& method_exists($entity, 'getObject') === true
		) {
			$payload = $entity->getObject();
			if (is_array($payload) === true) {
				return $payload;
			}
		}

		return null;
	}                  

	   
                                                                         
   
                                                       
   
                                                            
    
	private function normaliseResults(mixed $results): array {
		if (is_array($results) === false) {
			return [];
		}

		$output = [];
		foreach ($results as $item) {
			if (is_array($item) === true) {
				$output[] = $item;
				continue;
			}

			if (is_object($item) === false) {
				continue;
			}

			if (method_exists($item, 'getObject') === true) {
				$payload = $item->getObject();
				if (is_array($payload) === true) {
					if (isset($payload['id']) === false
						&& method_exists($item, 'getUuid') === true
					) {
						$payload['id'] = $item->getUuid();
					}

					$output[] = $payload;
					continue;
				}
			}

			$output[] = (array)$item;
		}             

		return $output;
	}                        

	   
                                                                 
   
                                                                           
                                         
                                
   
                                                                                      
    
	private function paginate(array $items, int $page, int $limit): array {
		$total = count($items);

		$pages = 1;
		if ($total !== 0) {
			$pages = (int)ceil($total / $limit);
		}

		$page = min($page, $pages);
		$start = (($page - 1) * $limit);
		if ($start < 0) {
			$start = 0;
		}

		return [
			'total' => $total,
			'page' => $page,
			'pages' => $pages,
			'results' => array_values(array_slice($items, $start, $limit)),
		];
	}                

	   
                                                                 
                                
   
                                                          
   
                                                                                      
    
	private function emptyResult(int $page): array {
		return [
			'total' => 0,
			'page' => max(1, $page),
			'pages' => 1,
			'results' => [],
		];
	}                   

	   
                                                                      
   
                                              
    
	private function getObjectService(): object {
		                                                                 
		                                                              
		return $this->objectService;
	}                        
}           
