<?php

   
                           
  
                                                                           
                                                                       
                                                                       
                                                                          
                                
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                               
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Marketing\SegmentSignalService;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

   
                                                               
  
                                                                         
                                                                       
                                                                         
                                                                            
                                                                          
                                                                 
                                               
                                                                        
                                                                           
                                                                        
                                                                
                                                                        
                                                                        
                                                                    
                                                            
                                                                           
                                                                          
                                                                      
                                                                     
                                                                     
                
  
                                                                                               
   
class SegmentService {
	   
                                                      
                                                           
    
	private const DEFAULT_ESTIMATE_TTL_SECONDS = 3600;

	   
                                                                 
                                                        
    
	private const DEFAULT_CONTACT_SCHEMA_SLUG = 'contact';

	   
                                                                 
                        
    
	private const DEFAULT_SEGMENT_SCHEMA_SLUG = 'segment';

	   
                                                                     
                                                                 
    
	private const DEFAULT_REGISTER_SLUG = 'pipelinq';

	   
                                                                 
                                                                  
                                       
    
	private const DEFAULT_CUSTOMER_SCHEMA_SLUG = 'client';

	   
                                                                    
                                                                   
                                           
   
                                                                  
                                                                    
                                                                
                                                                      
                                                                
                
   
                                          
    
	private const OPERATOR_TYPE_MATRIX = [
		'equals' => ['string', 'integer', 'number', 'boolean', 'array'],
		'notEquals' => ['string', 'integer', 'number', 'boolean', 'array'],
		'gt' => ['integer', 'number', 'string'],
		'gte' => ['integer', 'number', 'string'],
		'lt' => ['integer', 'number', 'string'],
		'lte' => ['integer', 'number', 'string'],
		'greaterThan' => ['integer', 'number', 'string'],
		'greaterThanOrEqual' => ['integer', 'number', 'string'],
		'lessThan' => ['integer', 'number', 'string'],
		'lessThanOrEqual' => ['integer', 'number', 'string'],
		'before' => ['string'],
		'after' => ['string'],
		'between' => ['integer', 'number', 'string'],
		'contains' => ['string', 'array'],
		'containsAny' => ['string', 'array'],
		'in' => ['string', 'integer', 'number', 'boolean'],
		'notIn' => ['string', 'integer', 'number', 'boolean'],
		'isNull' => ['string', 'integer', 'number', 'boolean', 'array'],
		'isNotNull' => ['string', 'integer', 'number', 'boolean', 'array'],
	];

	   
                
   
                                                      
                                                     
                                                              
                                                        
                                          
                                                                                
   
                                                                                                       
    
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private SchemaMapService $schemaMapService,
		private ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
		private SegmentSignalService $signals,
	) {
	}                   

	   
                                                                          
   
                                                                                                  
                                                                                           
   
                                                                                                                                                                                    
    
	public function signalCatalogue(): array {
		return $this->signals->catalogue();
	}                       

	   
                                                                    
   
                                                                          
   
                                                                                                                                                            
    
	public function signalAvailability(): array {
		return $this->signals->availability();
	}                          

	   
                                                                      
   
                                                                 
                                                                      
                                                                      
                                     
   
                                                  
                                                         
                                                                
                                                    
                                                   
                                               
   
                                                                      
                                                                     
                                                         
   
                                                                   
                                                      
   
                                                                   
   
                                                                                                       
    
	public function validateRules(array $rules, string $entityType): ?string {
		$properties = $this->resolveSchemaProperties(entityType: $entityType);
		if ($properties === null) {
			return sprintf('Unknown entityType "%s" (no schema mapping configured).', $entityType);
		}

		                                                             
		                                                                   
		                                                        
		$properties = array_merge($this->signals->schemaProperties(), $properties);

		return $this->validateNode(node: $rules, path: '$', properties: $properties);
	}                     

	   
                                                              
   
                                                        
                                                     
                                                            
                                                                     
                                                               
                                                                    
                                             
   
                                                                  
                                                                    
                                              
   
                                                      
                                                                   
   
                                                       
   
                                                                                                       
    
	public function evaluateRules(array $rules, array $entity): bool {
		return $this->evaluateNode(node: $rules, entity: $entity);
	}                     

	   
                                                                  
   
                                                                     
                                                                  
                                                                     
                                                                 
                                                          
                                                                  
                                                                    
                            
   
                                                                  
                                                      
   
                                                                  
   
                                                                                                       
    
	public function previewRulePayload(array $rules, string $entityType): array {
		$error = $this->validateRules(rules: $rules, entityType: $entityType);
		if ($error !== null) {
			return [
				'valid' => false,
				'error' => $error,
				'estimatedSize' => 0,
			];
		}

		$count = 0;
		$entities = $this->loadEntitiesForType(entityType: $entityType);
		foreach ($entities as $entity) {
			if ($this->evaluateRules(rules: $rules, entity: $entity) === true) {
				$count++;
			}
		}

		return [
			'valid' => true,
			'error' => null,
			'estimatedSize' => $count,
		];
	}                          

	   
                                                                     
   
                                                                     
                                                             
                                                                       
                                                                  
                                                                
                                                       
   
                                                  
   
                                                             
   
                                                                                                      
    
	public function getMembersForBlast(string $segmentId): array {
		$segment = $this->loadSegment(segmentId: $segmentId);
		if ($segment === null) {
			return [];
		}

		$rules = $this->extractRules(segment: $segment);
		$entityType = $this->extractEntityType(segment: $segment);
		if ($rules === null || $entityType === null) {
			return [];
		}

		$members = [];
		$entities = $this->loadEntitiesForType(entityType: $entityType);
		foreach ($entities as $entity) {
			if ($this->evaluateRules(rules: $rules, entity: $entity) !== true) {
				continue;
			}

			$members[] = $this->projectMember(entity: $entity);
		}

		return $members;
	}                          

	   
                                           
   
                                                                     
                                                                      
                                                                             
   
                                         
                                                 
   
                                                                                                                           
   
                                                                                                                            
    
	public function listSegments(int $page, int $limit): array {
		$page = max(1, $page);
		$limit = min(100, max(1, $limit));
		$all = $this->loadEntities(schemaSlug: $this->getSegmentSchemaSlug(), filters: []);
		$total = count($all);
		$slice = array_slice($all, (($page - 1) * $limit), $limit);
		return [
			'data' => $slice,
			'pagination' => [
				'page' => $page,
				'limit' => $limit,
				'total' => $total,
				'pages' => $this->computePages(total: $total, limit: $limit),
			],
		];
	}                    

	   
                                                         
   
                                                               
                                                             
   
                                      
                                
   
                                               
    
	private function computePages(int $total, int $limit): int {
		if ($total <= 0 || $limit <= 0) {
			return 0;
		}

		return (int)ceil($total / $limit);
	}                    

	   
                                                            
   
                                                                       
                                                           
                                                                    
                                                                
                            
   
                                                  
   
                                                                  
   
                                                                                                                            
    
	public function getSegmentById(string $segmentId): ?array {
		if ($segmentId === '') {
			return null;
		}

		return $this->loadSegment(segmentId: $segmentId);
	}                      

	   
                                                    
   
                                                                    
                                                               
                                                                     
                                                                     
                                    
   
                                                                 
                                                      
   
                                                                                      
   
                                                                                                                            
    
	public function createSegment(array $payload, string $createdByUid): array {
		$name = (string)($payload['name'] ?? '');
		if (trim($name) === '') {
			return ['error' => 'Invalid name'];
		}

		$entityType = strtolower((string)($payload['entityType'] ?? ''));
		if (in_array($entityType, ['contact', 'customer'], true) === false) {
			return ['error' => 'Invalid entityType'];
		}

		$rules = ($payload['rules'] ?? null);
		if (is_array($rules) === false) {
			return ['error' => 'Invalid rules'];
		}

		$error = $this->validateRules(rules: $rules, entityType: $entityType);
		if ($error !== null) {
			return ['error' => 'Invalid rule tree: ' . $error];
		}

		return $this->persistSegment(payload: $payload, rules: $rules, entityType: $entityType, createdByUid: $createdByUid);
	}                     

	   
                                                                 
   
                                                                         
                                                                         
                                                              
                                                                    
                                                                       
                                                                      
   
                                                           
                                                                           
   
                                                                                      
   
                                                                                                                            
    
	public function updateSegment(string $segmentId, array $payload): array {
		if ($segmentId === '') {
			return ['error' => 'Invalid id'];
		}

		$existing = $this->loadSegment(segmentId: $segmentId);
		if ($existing === null) {
			return ['error' => 'Segment not found'];
		}

		$name = (string)($payload['name'] ?? ($existing['name'] ?? ''));
		if (trim($name) === '') {
			return ['error' => 'Invalid name'];
		}

		$entityType = strtolower((string)($payload['entityType'] ?? $this->extractEntityType(segment: $existing) ?? ''));
		if (in_array($entityType, ['contact', 'customer'], true) === false) {
			return ['error' => 'Invalid entityType'];
		}

		$rules = ($payload['rules'] ?? $this->extractRules(segment: $existing));
		if (is_array($rules) === false) {
			return ['error' => 'Invalid rules'];
		}

		$error = $this->validateRules(rules: $rules, entityType: $entityType);
		if ($error !== null) {
			return ['error' => 'Invalid rule tree: ' . $error];
		}

		return $this->persistSegmentUpdate(
			segmentId: $segmentId,
			existing: $existing,
			name: $name,
			description: (string)($payload['description'] ?? ($existing['description'] ?? '')),
			rules: $rules,
			entityType: $entityType,
		);
	}                     

	   
                                                                      
                                       
   
                                                                
                      
   
                                                           
                                                                          
                                       
                                                              
                                                           
                                                      
   
                                                                                      
   
                                                                                                                            
    
	private function persistSegmentUpdate(
		string $segmentId,
		array $existing,
		string $name,
		string $description,
		array $rules,
		string $entityType,
	): array {
		                                                                    
		                                                               
		                                                               
		                                                                    
		                                                       
		                                                                  
		                                           
		$estimated = $this->countMatchingEntities(rules: $rules, entityType: $entityType);

		$object = $existing;
		$object['name'] = $name;
		$object['description'] = $description;
		$object['rules'] = $rules;
		$object['entityType'] = $entityType;
		$object['estimatedSize'] = $estimated;
		$object['updatedAt'] = gmdate('Y-m-d\TH:i:s\Z');

		$saved = $this->saveSegmentObject(payload: $object, id: $segmentId);
		if ($saved === null) {
			return ['error' => 'Could not update segment'];
		}

		                                                                  
		                                                                  
		              
		$cache = $this->getEstimateCache();
		if ($cache !== null) {
			$cache->set('estimate:' . $segmentId, $estimated, $this->getEstimateTtl());
		}

		return ['segment' => $saved, 'estimatedSize' => $estimated];
	}                            

	   
                                                                 
   
                                                         
                                                           
                                                      
                                                      
   
                                                                                      
    
	private function persistSegment(array $payload, array $rules, string $entityType, string $createdByUid): array {
		$now = gmdate('Y-m-d\TH:i:s\Z');
		$object = [
			'name' => (string)$payload['name'],
			'description' => (string)($payload['description'] ?? ''),
			'rules' => $rules,
			'entityType' => $entityType,
			'createdBy' => $createdByUid,
			'createdAt' => $now,
			'updatedAt' => $now,
		];

		$saved = $this->saveSegmentObject(payload: $object);
		if ($saved === null) {
			return ['error' => 'Could not create segment'];
		}

		$estimated = 0;
		$idForSize = $this->extractSegmentId(payload: $saved);
		if ($idForSize !== '') {
			$estimated = $this->estimateSize(segmentId: $idForSize);
			$saved['estimatedSize'] = $estimated;
		}

		return ['segment' => $saved, 'estimatedSize' => $estimated];
	}                      

	   
                                                                       
   
                                                                       
                                                                      
                                                                  
            
   
                                                  
                                                         
   
                                                             
   
                                                                                                                            
    
	public function previewSegmentMembers(string $segmentId, int $limit = 50): array {
		$limit = min(500, max(1, $limit));
		if ($segmentId === '') {
			return [];
		}

		$members = $this->getMembersForBlast(segmentId: $segmentId);
		return array_slice($members, 0, $limit);
	}                             

	   
                                                           
   
                                                                  
                                                                   
                                                                      
                                                          
   
                                                  
   
                                                                   
                                       
   
                                                                                                                            
    
	public function refreshSegmentSize(string $segmentId): int {
		if ($segmentId === '') {
			return 0;
		}

		$segment = $this->loadSegment(segmentId: $segmentId);
		if ($segment === null) {
			return 0;
		}

		                                                     
		  
		                                                                      
		                                                                   
		                                                                     
		                                                                      
		                                    
		$size = $this->recomputeSize(segmentId: $segmentId);
		$payload = $segment;
		$payload['estimatedSize'] = $size;
		$payload['updatedAt'] = gmdate('Y-m-d\TH:i:s\Z');

		                                                
		  
		                                                                   
		                                                                        
		                                                                
		                                                    
		$saved = $this->saveSegmentObject(
			payload: $payload,
			id: $this->extractSegmentId(payload: $segment)
		);
		if ($saved === null) {
			throw new RuntimeException('Pipelinq: the refreshed segment size could not be persisted.');
		}

		return $size;
	}                          

	   
                                                             
   
                                                         
                                                     
   
                                                                   
    
	private function saveSegmentObject(array $payload, ?string $id = null): ?array {
		$register = $this->getRegisterSlug();
		$schema = $this->getSegmentSchemaSlug();
		if ($register === '' || $schema === '') {
			return null;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$saved = $objectService->saveObject(
				object: $payload,
				register: $register,
				schema: $schema,
				uuid: $id,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SegmentService.saveSegmentObject: save failed',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		return $this->toArray(value: $saved);
	}                         

	   
                                                                   
   
                                          
                                                    
   
                                                            
    
	private function loadEntities(string $schemaSlug, array $filters): array {
		$register = $this->getRegisterSlug();
		if ($register === '' || $schemaSlug === '') {
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
							'schema' => $schemaSlug,
						]
					),
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SegmentService.loadEntities: findAll failed',
				['schema' => $schemaSlug, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$out = [];
		foreach (($rows ?? []) as $row) {
			$out[] = $this->toArray(value: $row);
		}

		return $out;
	}                    

	   
                                                             
   
                                                         
   
                                      
    
	private function extractSegmentId(array $payload): string {
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

	   
                                                                     
   
                                                                
                                           
                                                      
                                                                 
   
                                                       
   
                                                
    
	private function projectMember(array $entity): array {
		$id = $this->resolveMemberId(entity: $entity);
		$name = $this->resolveMemberName(entity: $entity);

		return [
			'contactId' => $id,
			'email' => (string)($entity['email'] ?? ''),
			'firstName' => $name['firstName'],
			'lastName' => $name['lastName'],
		];
	}                     

	   
                                                                       
   
                                                                
                                                                    
                                                               
                                                         
   
                                                       
   
                                      
    
	private function resolveMemberId(array $entity): string {
		$id = $this->firstScalarValue(source: $entity, keys: ['id', 'uuid', 'slug']);
		if ($id !== '') {
			return $id;
		}

		if (isset($entity['@self']) === true && is_array($entity['@self']) === true) {
			foreach (['uuid', 'id', 'slug'] as $key) {
				if (isset($entity['@self'][$key]) === true && is_scalar($entity['@self'][$key]) === true) {
					return (string)$entity['@self'][$key];
				}
			}
		}

		return '';
	}                       

	   
                                                                   
                                                             
   
                                                                
                                              
   
                                                       
   
                                                      
    
	private function resolveMemberName(array $entity): array {
		$firstName = (string)($entity['firstName'] ?? $entity['first_name'] ?? '');
		$lastName = (string)($entity['lastName'] ?? $entity['last_name'] ?? '');
		if ($firstName === '' && $lastName === '' && isset($entity['name']) === true && is_string($entity['name']) === true) {
			$parts = preg_split('/\s+/', trim($entity['name']));
			if (is_array($parts) === true && $parts !== []) {
				$firstName = (string)array_shift($parts);
				$lastName = trim(implode(' ', $parts));
			}
		}

		return ['firstName' => $firstName, 'lastName' => $lastName];
	}                         

	   
                                                                 
   
                                                                    
                                                                   
                                                                
                                                                    
                                                                 
                                                                 
                                            
   
                                                                   
                                                                    
                                                                 
                                       
   
                                                  
   
                                                         
   
                                                                                                      
    
	public function estimateSize(string $segmentId): int {
		$cached = $this->readEstimateCache(
			cache: $this->getEstimateCache(),
			cacheKey: ('estimate:' . $segmentId)
		);
		if ($cached !== null) {
			return $cached;
		}

		return $this->recomputeSize(segmentId: $segmentId);
	}                    

	   
                                                                          
   
                                                                            
                                                                             
                                                                           
                                                                       
   
                                                  
   
                                         
   
                                                                                                      
    
	public function recomputeSize(string $segmentId): int {
		$cache = $this->getEstimateCache();
		$cacheKey = 'estimate:' . $segmentId;

		$segment = $this->loadSegment(segmentId: $segmentId);
		if ($segment === null) {
			return 0;
		}

		$rules = $this->extractRules(segment: $segment);
		$entityType = $this->extractEntityType(segment: $segment);
		if ($rules === null || $entityType === null) {
			return 0;
		}

		$count = $this->countMatchingEntities(rules: $rules, entityType: $entityType);

		if ($cache !== null) {
			$ttl = $this->getEstimateTtl();
			$cache->set($cacheKey, $count, $ttl);
		}

		return $count;
	}                     

	   
                                                          
   
                                                               
                                              
   
                                                                       
                                          
   
                                                                                   
    
	private function readEstimateCache(?ICache $cache, string $cacheKey): ?int {
		if ($cache === null) {
			return null;
		}

		$cached = $cache->get($cacheKey);
		if (is_int($cached) === true) {
			return $cached;
		}

		if (is_numeric($cached) === true) {
			return (int)$cached;
		}

		return null;
	}                         

	   
                                                        
   
                                                               
                                              
   
                                                     
                                                     
   
                                           
    
	private function countMatchingEntities(array $rules, string $entityType): int {
		$count = 0;
		$entities = $this->loadEntitiesForType(entityType: $entityType);
		foreach ($entities as $entity) {
			if ($this->evaluateRules(rules: $rules, entity: $entity) === true) {
				$count++;
			}
		}

		return $count;
	}                             

	   
                                                   
   
                                                
                                                       
   
                                          
    
	private function evaluateNode(array $node, array $entity): bool {
		$type = $this->nodeType(node: $node);
		if ($type === 'AND') {
			return $this->evaluateAndChildren(children: ($node['children'] ?? []), entity: $entity);
		}

		if ($type === 'OR') {
			return $this->evaluateOrChildren(children: ($node['children'] ?? []), entity: $entity);
		}

		if ($type === 'NOT') {
			return $this->evaluateNotChild(node: $node, entity: $entity);
		}

		return $this->evaluateLeaf(leaf: $node, entity: $entity);
	}                    

	   
                                                                        
                                                                      
   
                                                               
                                              
   
                                                       
                                                       
   
                
    
	private function evaluateAndChildren(mixed $children, array $entity): bool {
		if (is_array($children) === false || $children === []) {
			return false;
		}

		foreach ($children as $child) {
			if (is_array($child) === false) {
				return false;
			}

			if ($this->evaluateNode(node: $child, entity: $entity) === false) {
				return false;
			}
		}

		return true;
	}                           

	   
                                                                     
                                                                    
                                                             
   
                                                               
                                              
   
                                                       
                                                       
   
                
    
	private function evaluateOrChildren(mixed $children, array $entity): bool {
		if (is_array($children) === false || $children === []) {
			return false;
		}

		foreach ($children as $child) {
			if (is_array($child) === false) {
				continue;
			}

			if ($this->evaluateNode(node: $child, entity: $entity) === true) {
				return true;
			}
		}

		return false;
	}                          

	   
                                                        
   
                                                               
                                              
   
                                                
                                                       
   
                
    
	private function evaluateNotChild(array $node, array $entity): bool {
		$children = ($node['children'] ?? []);
		if (is_array($children) === false || isset($children[0]) === false || is_array($children[0]) === false) {
			return false;
		}

		return ($this->evaluateNode(node: $children[0], entity: $entity) === false);
	}                        

	   
                                                   
   
                                                     
                                                       
   
                             
    
	private function evaluateLeaf(array $leaf, array $entity): bool {
		$field = ($leaf['field'] ?? null);
		if (is_string($field) === false || $field === '') {
			return false;
		}

		$operator = (string)($leaf['operator'] ?? 'equals');
		$value = ($leaf['value'] ?? null);

		$handler = ($this->operatorHandlers()[$operator] ?? null);
		if ($handler === null) {
			return false;
		}

		if ($this->signals->isSignalField(field: $field) === true) {
			return $this->evaluateSignalLeaf(field: $field, operator: $operator, handler: $handler, value: $value, entity: $entity);
		}

		if (str_contains($field, '.') === true) {
			return $this->evaluateProjectedLeaf(entity: $entity, field: $field, handler: $handler, value: $value);
		}

		$actual = ($entity[$field] ?? null);
		return $handler($actual, $value);
	}                    

	   
                                                                 
                    
   
                                                                     
                                                                    
                                                                  
                                                                        
                                                                      
                                                                      
                                                                         
                                  
   
                                          
                                                          
                                                                  
                                   
                                                       
   
                                            
   
                                                                                                                                                            
    
	private function evaluateSignalLeaf(string $field, string $operator, callable $handler, mixed $value, array $entity): bool {
		$actual = $this->signals->valueFor(field: $field, entity: $entity);
		if ($actual === null) {
			return ($operator === 'isNull');
		}

		return $handler($actual, $value);
	}                          

	   
                                                                   
                                                                        
                                                                    
                                           
   
                                                       
                                                                 
                                                                  
                                   
   
                                             
   
                                                                                                                                                     
    
	private function evaluateProjectedLeaf(array $entity, string $field, callable $handler, mixed $value): bool {
		[$parent, $child] = explode('.', $field, 2);
		$parentValue = ($entity[$parent] ?? null);
		if (is_array($parentValue) === false) {
			return false;
		}

		foreach ($parentValue as $item) {
			if (is_array($item) === false || array_key_exists($child, $item) === false) {
				continue;
			}

			if ($handler($item[$child], $value) === true) {
				return true;
			}
		}

		return false;
	}                             

	   
                                                                        
   
                                                                     
                                                                
                                                                     
                                                                        
                                                                     
               
   
                                                                          
    
	private function operatorHandlers(): array {
		return [
			'equals' => fn (mixed $actual, mixed $value): bool => $this->ruleEquals(actual: $actual, value: $value),
			'notEquals' => fn (mixed $actual, mixed $value): bool => ($this->ruleEquals(actual: $actual, value: $value) === false),
			'gt' => fn (mixed $actual, mixed $value): bool => ($this->compareNumeric(left: $actual, right: $value) === 1),
			'greaterThan' => fn (mixed $actual, mixed $value): bool => ($this->compareNumeric(left: $actual, right: $value) === 1),
			'gte' => fn (mixed $actual, mixed $value): bool => ($this->compareNumeric(left: $actual, right: $value) >= 0),
			'greaterThanOrEqual' => fn (mixed $actual, mixed $value): bool => ($this->compareNumeric(left: $actual, right: $value) >= 0),
			'lt' => fn (mixed $actual, mixed $value): bool => ($this->compareNumeric(left: $actual, right: $value) === -1),
			'lessThan' => fn (mixed $actual, mixed $value): bool => ($this->compareNumeric(left: $actual, right: $value) === -1),
			'lte' => fn (mixed $actual, mixed $value): bool => $this->evaluateLessThanOrEqual(actual: $actual, value: $value),
			'lessThanOrEqual' => fn (mixed $actual, mixed $value): bool => $this->evaluateLessThanOrEqual(actual: $actual, value: $value),
			'before' => fn (mixed $actual, mixed $value): bool => ($this->compareDates(left: $actual, right: $value) === -1),
			'after' => fn (mixed $actual, mixed $value): bool => ($this->compareDates(left: $actual, right: $value) === 1),
			'between' => fn (mixed $actual, mixed $value): bool => $this->evaluateBetween(actual: $actual, value: $value),
			'contains' => fn (mixed $actual, mixed $value): bool => $this->valueContains(haystack: $actual, needle: $value),
			'containsAny' => fn (mixed $actual, mixed $value): bool => $this->evaluateContainsAny(actual: $actual, value: $value),
			'in' => fn (mixed $actual, mixed $value): bool => $this->evaluateIn(actual: $actual, value: $value),
			'notIn' => fn (mixed $actual, mixed $value): bool => $this->evaluateNotIn(actual: $actual, value: $value),
			'isNull' => fn (mixed $actual): bool => ($actual === null),
			'isNotNull' => fn (mixed $actual): bool => ($actual !== null),
		];
	}                        

	   
                                        
   
                                                  
                                       
   
                
    
	private function evaluateLessThanOrEqual(mixed $actual, mixed $value): bool {
		$cmp = $this->compareNumeric(left: $actual, right: $value);
		return ($cmp === -1 || $cmp === 0);
	}                               

	   
                                                                          
   
                                                  
                                       
   
                
    
	private function evaluateBetween(mixed $actual, mixed $value): bool {
		if (is_array($value) === false || count($value) !== 2) {
			return false;
		}

		$low = $this->compareNumeric(left: $actual, right: $value[0]);
		$high = $this->compareNumeric(left: $actual, right: $value[1]);
		return ($low >= 0 && $high <= 0);
	}                       

	   
                                                                       
                
   
                                                  
                                                                      
   
                
    
	private function evaluateContainsAny(mixed $actual, mixed $value): bool {
		if (is_array($value) === false) {
			return false;
		}

		foreach ($value as $candidate) {
			if ($this->valueContains(haystack: $actual, needle: $candidate) === true) {
				return true;
			}
		}

		return false;
	}                           

	   
                                                                       
             
   
                                                  
                                                                      
   
                
    
	private function evaluateIn(mixed $actual, mixed $value): bool {
		if (is_array($value) === false) {
			return false;
		}

		foreach ($value as $candidate) {
			if ($this->looseEquals(left: $actual, right: $candidate) === true) {
				return true;
			}
		}

		return false;
	}                  

	   
                                                                     
                           
   
                                                  
                                                                      
   
                
    
	private function evaluateNotIn(mixed $actual, mixed $value): bool {
		if (is_array($value) === false) {
			return true;
		}

		foreach ($value as $candidate) {
			if ($this->looseEquals(left: $actual, right: $candidate) === true) {
				return false;
			}
		}

		return true;
	}                     

	   
                                                        
   
                                  
                                    
   
                                              
    
	private function looseEquals(mixed $left, mixed $right): bool {
		if ($left === null && $right === null) {
			return true;
		}

		if (is_bool($left) === true || is_bool($right) === true) {
			return ((bool)$left === (bool)$right);
		}

		if (is_numeric($left) === true && is_numeric($right) === true) {
			return ((float)$left === (float)$right);
		}

		if (is_scalar($left) === true && is_scalar($right) === true) {
			return (strcasecmp((string)$left, (string)$right) === 0);
		}

		return ($left === $right);
	}                   

	   
                                                                           
                                                                      
                                                         
   
                                                  
                                       
   
                
   
                                                                                
    
	private function ruleEquals(mixed $actual, mixed $value): bool {
		if (is_array($actual) === false || array_is_list($actual) === false || is_scalar($value) === false) {
			return $this->looseEquals(left: $actual, right: $value);
		}

		foreach ($actual as $entry) {
			if (is_scalar($entry) === true && strcasecmp((string)$entry, (string)$value) === 0) {
				return true;
			}
		}

		return false;
	}                  

	   
                                                                   
                                               
   
                                  
                                    
   
                            
    
	private function compareNumeric(mixed $left, mixed $right): int {
		if (is_numeric($left) === false || is_numeric($right) === false) {
			if (is_string($left) === true && is_string($right) === true) {
				return $left <=> $right;
			}

			return 0;
		}

		$leftFloat = (float)$left;
		$rightFloat = (float)$right;
		if ($leftFloat < $rightFloat) {
			return -1;
		}

		if ($leftFloat > $rightFloat) {
			return 1;
		}

		return 0;
	}                      

	   
                                                               
                                   
   
                                            
                                              
   
                            
    
	private function compareDates(mixed $left, mixed $right): int {
		$leftTimestamp = $this->toTimestamp(value: $left);
		$rightTimestamp = $this->toTimestamp(value: $right);
		if ($leftTimestamp === null || $rightTimestamp === null) {
			return 0;
		}

		if ($leftTimestamp < $rightTimestamp) {
			return -1;
		}

		if ($leftTimestamp > $rightTimestamp) {
			return 1;
		}

		return 0;
	}                    

	   
                                                                   
                                                               
   
                                        
   
                                                                
    
	private function toTimestamp(mixed $value): ?int {
		if (is_int($value) === true) {
			return $value;
		}

		if (is_string($value) === false || $value === '') {
			return null;
		}

		$match = [];
		if (preg_match('/^(\d+)\s+days?(\s+ago)?$/i', $value, $match) === 1) {
			$days = (int)$match[1];
			$stamp = (time() - ($days * 86400));
			return $stamp;
		}

		$parsed = strtotime($value);
		if ($parsed === false) {
			return null;
		}

		return $parsed;
	}                   

	   
                                                                           
   
                                                   
                                        
   
                                                          
    
	private function valueContains(mixed $haystack, mixed $needle): bool {
		if ($haystack === null) {
			return false;
		}

		if (is_array($haystack) === true) {
			foreach ($haystack as $element) {
				if ($this->looseEquals(left: $element, right: $needle) === true) {
					return true;
				}
			}

			return false;
		}

		if (is_scalar($haystack) === false || is_scalar($needle) === false) {
			return false;
		}

		$haystackStr = strtolower((string)$haystack);
		$needleStr = strtolower((string)$needle);
		if ($needleStr === '') {
			return false;
		}

		return (str_contains($haystackStr, $needleStr) === true);
	}                     

	   
                                
   
                                                
                                                    
                                                                  
   
                                             
    
	private function validateNode(array $node, string $path, array $properties): ?string {
		$type = $this->nodeType(node: $node);
		if ($type === 'AND' || $type === 'OR') {
			return $this->validateCompositeNode(node: $node, path: $path, properties: $properties, type: $type);
		}

		if ($type === 'NOT') {
			return $this->validateNotNode(node: $node, path: $path, properties: $properties);
		}

		return $this->validateLeafNode(node: $node, path: $path, properties: $properties);
	}                    

	   
                                                                      
                           
   
                                                               
                                              
   
                                                
                                                    
                                                                  
                                                              
   
                                             
    
	private function validateCompositeNode(array $node, string $path, array $properties, string $type): ?string {
		$children = ($node['children'] ?? null);
		if (is_array($children) === false || $children === []) {
			return sprintf('%s: composite "%s" node requires non-empty "children".', $path, $type);
		}

		foreach ($children as $index => $child) {
			if (is_array($child) === false) {
				return sprintf('%s.children[%d]: child must be an object.', $path, $index);
			}

			$childError = $this->validateNode(
				node: $child,
				path: $path . '.children[' . $index . ']',
				properties: $properties
			);
			if ($childError !== null) {
				return $childError;
			}
		}

		return null;
	}                             

	   
                                                                     
                          
   
                                                               
                                              
   
                                                
                                                    
                                                                  
   
                                             
    
	private function validateNotNode(array $node, string $path, array $properties): ?string {
		$children = ($node['children'] ?? null);
		if (is_array($children) === false || count($children) !== 1) {
			return sprintf('%s: NOT node requires exactly one child.', $path);
		}

		$child = $children[0];
		if (is_array($child) === false) {
			return sprintf('%s.children[0]: child must be an object.', $path);
		}

		return $this->validateNode(node: $child, path: $path . '.children[0]', properties: $properties);
	}                       

	   
                                                                   
                                                                      
                                             
   
                                                               
                                              
   
                                                
                                                    
                                                                  
   
                                             
    
	private function validateLeafNode(array $node, string $path, array $properties): ?string {
		$field = ($node['field'] ?? null);
		if (is_string($field) === false || $field === '') {
			return sprintf('%s: leaf predicate requires non-empty "field".', $path);
		}

		$fieldType = $this->resolveFieldType(field: $field, properties: $properties);
		if ($fieldType === null) {
			return sprintf('%s: field "%s" is not declared on the entity schema.', $path, $field);
		}

		$operator = ($node['operator'] ?? null);
		$allowedTypes = $this->resolveAllowedTypesForOperator(operator: $operator);
		if ($allowedTypes === null) {
			return sprintf('%s: operator "%s" is not supported.', $path, (string)$operator);
		}

		if (in_array($fieldType, $allowedTypes, true) === false) {
			return sprintf(
				'%s: operator "%s" is not valid for field "%s" of type "%s".',
				$path,
				(string)$operator,
				$field,
				$fieldType
			);
		}

		if (in_array($operator, ['isNull', 'isNotNull'], true) === true) {
			return null;
		}

		if (array_key_exists('value', $node) === false) {
			return sprintf('%s: operator "%s" requires a "value".', $path, (string)$operator);
		}

		if ($this->isValueCoercible(value: $node['value'], fieldType: $fieldType, operator: (string)$operator) === false) {
			return sprintf(
				'%s: value for field "%s" is not coercible to type "%s".',
				$path,
				$field,
				$fieldType
			);
		}

		return null;
	}                        

	   
                                                                        
                                                         
   
                                                                          
                                                                        
                                                                        
                           
   
                                                  
   
                                                                                        
    
	private function resolveAllowedTypesForOperator(mixed $operator): ?array {
		if (is_string($operator) === false) {
			return null;
		}

		return (self::OPERATOR_TYPE_MATRIX[$operator] ?? null);
	}                                      

	   
                                                                        
   
                                           
                                              
                                                                    
   
                                             
    
	private function isValueCoercible(mixed $value, string $fieldType, string $operator): bool {
		if ($fieldType === 'array' && in_array($operator, ['contains', 'containsAny'], true) === true) {
			return $this->isMembershipCoercible(value: $value);
		}

		if (in_array($operator, ['in', 'notIn', 'containsAny', 'between'], true) === true) {
			if (is_array($value) === false) {
				return false;
			}

			if ($operator === 'between' && count($value) !== 2) {
				return false;
			}

			foreach ($value as $element) {
				if ($this->isScalarCoercible(value: $element, fieldType: $fieldType) === false) {
					return false;
				}
			}

			return true;
		}

		return $this->isScalarCoercible(value: $value, fieldType: $fieldType);
	}                        

	   
                                                            
   
                                                                      
                                                                 
                                                                           
                                                                          
                                                                            
                                                                            
               
   
                                                      
   
                                                       
   
                                                                                                                                                                                    
    
	private function isMembershipCoercible(mixed $value): bool {
		$candidates = $value;
		if (is_array($candidates) === false) {
			$candidates = [$value];
		}

		foreach ($candidates as $candidate) {
			if (is_scalar($candidate) === false) {
				return false;
			}
		}

		return true;
	}                             

	   
                                                                 
   
                                      
                                              
   
                                             
    
	private function isScalarCoercible(mixed $value, string $fieldType): bool {
		if ($value === null) {
			return true;
		}

		switch ($fieldType) {
			case 'integer':
				return $this->isIntegerCoercible(value: $value);
			case 'number':
				return $this->isNumberCoercible(value: $value);
			case 'boolean':
				return $this->isBooleanCoercible(value: $value);
			case 'array':
				return is_array($value);
			case 'string':
			default:
				return (is_scalar($value) === true);
		}            
	}                         

	   
                                                                   
                 
   
                                                                    
                                              
   
                                                 
   
                
    
	private function isIntegerCoercible(mixed $value): bool {
		if (is_int($value) === true) {
			return true;
		}

		return (is_string($value) === true && preg_match('/^-?\d+$/', $value) === 1);
	}                          

	   
                                                             
   
                                                                    
                                              
   
                                                 
   
                
    
	private function isNumberCoercible(mixed $value): bool {
		if (is_int($value) === true || is_float($value) === true) {
			return true;
		}

		return (is_string($value) === true && is_numeric($value) === true);
	}                         

	   
                                                                   
                                           
   
                                                                    
                                              
   
                                                 
   
                
    
	private function isBooleanCoercible(mixed $value): bool {
		if (is_bool($value) === true) {
			return true;
		}

		if (is_string($value) === true && in_array(strtolower($value), ['true', 'false', '0', '1'], true) === true) {
			return true;
		}

		return (is_int($value) === true && ($value === 0 || $value === 1));
	}                          

	   
                                                                   
   
                                                
   
                                 
    
	private function nodeType(array $node): string {
		$declared = ($node['type'] ?? null);
		if (is_string($declared) === true) {
			$upper = strtoupper($declared);
			if (in_array($upper, ['AND', 'OR', 'NOT'], true) === true) {
				return $upper;
			}
		}

		return 'LEAF';
	}                

	   
                                                                       
   
                                                   
   
                               
    
	private function propertyType(mixed $property): string {
		if (is_array($property) === true && isset($property['type']) === true && is_string($property['type']) === true) {
			return $property['type'];
		}

		return 'string';
	}                    

	   
                                                                        
                                                                
                                                                     
                                                                       
                                                                   
                                                                  
                                                                  
   
                                                              
                                                                  
   
                                                                      
   
                                                                                                                                                     
    
	private function resolveFieldType(string $field, array $properties): ?string {
		if (str_contains($field, '.') === false) {
			if (array_key_exists($field, $properties) === false) {
				return null;
			}

			return $this->propertyType(property: $properties[$field]);
		}

		[$parent, $child] = explode('.', $field, 2);
		if (array_key_exists($parent, $properties) === false) {
			return null;
		}

		$parentProperty = $properties[$parent];
		if (is_array($parentProperty) === false || ($parentProperty['type'] ?? null) !== 'array') {
			return null;
		}

		$itemProperties = $parentProperty['items']['properties'] ?? null;
		if (is_array($itemProperties) === false || array_key_exists($child, $itemProperties) === false) {
			return null;
		}

		return $this->propertyType(property: $itemProperties[$child]);
	}                        

	   
                                                                
   
                                                                       
                                                                     
                                                                    
                                                                      
                                                                
            
   
                                                      
   
                                                                      
                                                               
   
                                                                                                       
                                                                                                                                            
                                                                
                                                                 
                
    
	protected function resolveSchemaProperties(string $entityType): ?array {
		$schemaSlug = $this->resolveSchemaSlug(entityType: $entityType);
		if ($schemaSlug === '') {
			return null;
		}

		$schemaMapper = $this->getSchemaMapper();
		if ($schemaMapper === null) {
			return null;
		}

		try {
			                                                    
			                                                           
			$schema = $schemaMapper->find(
				id: $schemaSlug,
				_rbac: false,
				_multitenancy: false,
			);
		} catch (Throwable $e) {
			$this->logger->info(
				'SegmentService.resolveSchemaProperties: schema lookup failed',
				['entityType' => $entityType, 'slug' => $schemaSlug, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if (is_object($schema) === false || method_exists($schema, 'getProperties') === false) {
			return null;
		}

		$properties = $schema->getProperties();
		if (is_array($properties) === false) {
			return null;
		}

		return $properties;
	}                               

	   
                                                     
   
                     
                                                      
                                                         
                                                                
                                                               
   
                                                      
   
                                                                   
   
                                                                                                       
    
	protected function resolveSchemaSlug(string $entityType): string {
		$entityType = strtolower($entityType);
		$candidateKeys = [];
		if ($entityType === 'contact') {
			$candidateKeys = ['contact_schema'];
		} elseif ($entityType === 'customer' || $entityType === 'client') {
			$candidateKeys = ['customer_schema', 'client_schema'];
		}

		foreach ($candidateKeys as $key) {
			$slug = $this->appConfig->getValueString(Application::APP_ID, $key, '');
			if ($slug !== '') {
				return $slug;
			}
		}

		if ($entityType === 'contact') {
			return self::DEFAULT_CONTACT_SCHEMA_SLUG;
		}

		if ($entityType === 'customer' || $entityType === 'client') {
			return self::DEFAULT_CUSTOMER_SCHEMA_SLUG;
		}

		return '';
	}                         

	   
                                             
   
                                                      
   
                                                                      
    
	private function loadSegment(string $segmentId): ?array {
		$register = $this->getRegisterSlug();
		$schema = $this->getSegmentSchemaSlug();
		if ($register === '' || $schema === '') {
			return null;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$entity = $objectService->find(
				id: $segmentId,
				register: $register,
				schema: $schema,
			);
		} catch (Throwable $e) {
			$this->logger->info(
				'SegmentService.loadSegment: not found',
				['segmentId' => $segmentId, 'exception' => $e->getMessage()]
			);
			return null;
		}

		                                                               
		                                                                
		                                                              
		                                                       
		                                                                   
		                                           
		                                                   
		if ($entity === null) {
			return null;
		}

		return $this->toArray(value: $entity);
	}                   

	   
                                                                      
   
                                                                 
                                                             
                                                                   
                                                               
            
   
                                                      
   
                                                             
    
	private function loadEntitiesForType(string $entityType): array {
		$register = $this->getRegisterSlug();
		$schema = $this->resolveSchemaSlug(entityType: $entityType);
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
					'filters' => [
						'register' => $register,
						'schema' => $schema,
					],
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SegmentService.loadEntitiesForType: findAll failed',
				['entityType' => $entityType, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$entities = [];
		foreach (($rows ?? []) as $row) {
			$entities[] = $this->toArray(value: $row);
		}

		return $entities;
	}                           

	   
                                                    
   
                               
    
	private function getSegmentSchemaSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'segment_schema', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_SEGMENT_SCHEMA_SLUG;
	}                            

	   
                                                
   
                                                                       
                                                                      
                                                                  
                                    
   
                               
   
                                                                                                                          
    
	public function getSegmentSchemaSlugPublic(): string {
		return $this->getSegmentSchemaSlug();
	}                                  

	   
                                              
   
                                 
    
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_REGISTER_SLUG;
	}                       

	   
                                                 
   
                                                             
   
                                                                   
    
	private function extractRules(array $segment): ?array {
		$rules = ($segment['rules'] ?? null);
		if (is_string($rules) === true && $rules !== '') {
			$decoded = json_decode($rules, true);
			if (is_array($decoded) === true) {
				return $decoded;
			}

			return null;
		}

		if (is_array($rules) === true) {
			return $rules;
		}

		return null;
	}                    

	   
                                                  
   
                                                             
   
                                                      
    
	private function extractEntityType(array $segment): ?string {
		$entityType = ($segment['entityType'] ?? null);
		if (is_string($entityType) === true && $entityType !== '') {
			return $entityType;
		}

		return null;
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

	   
                                                                       
   
                            
    
	private function getEstimateTtl(): int {
		$configured = $this->appConfig->getValueString(
			Application::APP_ID,
			'segment.estimate_ttl_seconds',
			(string)self::DEFAULT_ESTIMATE_TTL_SECONDS
		);
		if ($configured === '' || is_numeric($configured) === false) {
			return self::DEFAULT_ESTIMATE_TTL_SECONDS;
		}

		$ttl = (int)$configured;
		if ($ttl < 0) {
			return self::DEFAULT_ESTIMATE_TTL_SECONDS;
		}

		return $ttl;
	}                      

	   
                                                                   
                                                     
   
                                                                     
    
	private function getEstimateCache(): ?ICache {
		try {
			if ($this->cacheFactory->isAvailable() === true) {
				return $this->cacheFactory->createDistributed('pipelinq_segment_estimate');
			}
		} catch (Throwable $e) {
			$this->logger->info(
				'SegmentService.getEstimateCache: distributed cache unavailable',
				['exception' => $e->getMessage()]
			);
		}

		try {
			return $this->cacheFactory->createLocal('pipelinq_segment_estimate');
		} catch (Throwable $e) {
			$this->logger->warning(
				'SegmentService.getEstimateCache: local cache unavailable',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}                        

	   
                                                  
   
                                                                   
                                   
    
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'SegmentService.getObjectService: OpenRegister unavailable',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}                        

	   
                                                 
   
                                                                      
                               
    
	private function getSchemaMapper(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
		} catch (Throwable $e) {
			$this->logger->info(
				'SegmentService.getSchemaMapper: SchemaMapper unavailable',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}                       

	   
                                                               
                                                              
   
                                               
    
	protected function schemaMapService(): SchemaMapService {
		return $this->schemaMapService;
	}                        
}           
