<?php

   
                                    
  
                                                                          
                                                                        
                                                                         
                     
  
                                                                               
                                                                                 
                                                                              
  
                                    
                                               
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                           
                                                                                                  
                                                                                                    
                                                                                                         
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                                                   
  
                                                  
                                                    
  
                                                           
   
class ActivityTimelineService {
	   
                                                 
    
	private const MAX_LIMIT = 100;

	   
                                         
    
	private const DEFAULT_LIMIT = 20;

	   
                                                                            
                                                                      
                                                            
    
	private const PER_SCHEMA_CEILING = 500;

	   
                
   
                                                        
                                                              
                                              
                                                                                     
                                                                                         
                                                                                         
                                                                                         
    
	public function __construct(
		private IAppConfig $appConfig,
		private IUserSession $userSession,
		private LoggerInterface $logger,
		private TicketService $ticketService,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                       
   
                                                           
    
	private function getObjectService(): object {
		                                                                 
		                                                              
		return $this->objectService;
	}                        

	   
                                                
   
                                                                            
                                                                        
   
                                                                         
                                                                         
                                                                          
                                                                  
   
                                                            
    
	private function getConfig(): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$task = $this->appConfig->getValueString(Application::APP_ID, 'task_schema', '');
		$emailLink = $this->appConfig->getValueString(Application::APP_ID, 'emailLink_schema', '');
		$calendarLink = $this->appConfig->getValueString(Application::APP_ID, 'calendarLink_schema', '');
		if ($register === '') {
			$this->logger->warning('Pipelinq: "register" is not configured; the activity timeline is refused, not run unscoped');
		}

		return [
			'register' => $register,
			'interaction' => $this->ticketService->getSchemaId(),
			'task' => $task,
			'emailLink' => $emailLink,
			'calendarLink' => $calendarLink,
		];
	}                 

	   
                                                                        
   
                                                                            
                                            
                                                                                            
   
                                                                                           
   
                                                                                                     
    
	public function getTimeline(string $entityType, string $entityId, array $params): array {
		$page = max(1, (int)($params['_page'] ?? 1));
		$limit = $this->normaliseLimit(rawLimit: ($params['_limit'] ?? null));

		$config = $this->getConfig();
		$registerId = $config['register'];

		                                                                         
		if ($registerId === '') {
			return $this->emptyResult(page: $page);
		}

		$merged = $this->collectActivities(
			entityType: $entityType,
			entityId: $entityId,
			params: $params,
			config: $config
		);

		                                          
		usort(
			$merged,
			static function (array $left, array $right): int {
				$leftDate = (string)($left['date'] ?? '');
				$rightDate = (string)($right['date'] ?? '');
				return strcmp($rightDate, $leftDate);
			}
		);

		return $this->paginate(merged: $merged, page: $page, limit: $limit);
	}                   

	   
                                                          
   
                                                          
   
                                      
    
	private function normaliseLimit(mixed $rawLimit): int {
		$limit = (int)($rawLimit ?? self::DEFAULT_LIMIT);
		if ($limit <= 0) {
			$limit = self::DEFAULT_LIMIT;
		}

		if ($limit > self::MAX_LIMIT) {
			$limit = self::MAX_LIMIT;
		}

		return $limit;
	}                      

	   
                                                                                    
   
                                              
                                            
                                                                          
                                                                           
   
                                                                           
    
	private function collectActivities(string $entityType, string $entityId, array $params, array $config): array {
		$typesFilter = $this->normaliseTypes(rawTypes: ($params['types'] ?? null));
		[$from, $to] = $this->extractDateBounds(params: $params);
		$perSchemaFilters = $this->resolveEntityQueryParams(entityType: $entityType, entityId: $entityId);
		$registerId = $config['register'];

		$merged = [];

		foreach ($perSchemaFilters as $sourceType => $sourceFilter) {
			                                                                       
			if ($typesFilter !== null && in_array($this->sourceToActivityType(sourceType: $sourceType), $typesFilter, true) === false) {
				continue;
			}

			$schemaId = $config[$sourceType] ?? '';
			if ($schemaId === '') {
				continue;
			}

			$objects = $this->querySchema(
				registerId: $registerId,
				schemaId: $schemaId,
				filters: $sourceFilter
			);

			$merged = array_merge(
				$merged,
				$this->normalizeSchemaObjects(
					objects: $objects,
					sourceType: $sourceType,
					entityType: $entityType,
					entityId: $entityId,
					from: $from,
					to: $to
				)
			);
		}             

		return $merged;
	}                         

	   
                                                                 
   
                                                      
   
                                                                        
    
	private function extractDateBounds(array $params): array {
		$from = null;
		if (isset($params['from']) === true && $params['from'] !== '') {
			$from = (string)$params['from'];
		}

		$to = null;
		if (isset($params['to']) === true && $params['to'] !== '') {
			$to = (string)$params['to'];
		}

		return [$from, $to];
	}                         

	   
                                                                                  
   
                                                                          
                                                                  
                                              
                                            
                                                                      
                                                                    
   
                                                                               
    
	private function normalizeSchemaObjects(
		iterable $objects,
		string $sourceType,
		string $entityType,
		string $entityId,
		?string $from,
		?string $to,
	): array {
		$activities = [];
		foreach ($objects as $object) {
			$normalized = $this->normalizeActivity(
				sourceType: $sourceType,
				object: $object,
				entityType: $entityType,
				entityId: $entityId
			);
			if ($normalized === null) {
				continue;
			}

			if ($this->withinDateRange(date: $normalized['date'], from: $from, to: $to) === false) {
				continue;
			}

			$activities[] = $normalized;
		}

		return $activities;
	}                              

	   
                                                                  
   
                                                                        
                                                
                                    
   
                                                                                           
    
	private function paginate(array $merged, int $page, int $limit): array {
		$total = count($merged);

		$pages = 1;
		if ($total !== 0) {
			$pages = (int)ceil($total / $limit);
		}

		$start = (($page - 1) * $limit);
		$page = min($page, $pages);

		if ($start < 0) {
			$start = 0;
		}

		$slice = array_slice($merged, $start, $limit);

		return [
			'items' => array_values($slice),
			'total' => $total,
			'page' => $page,
			'pages' => $pages,
		];
	}                

	   
                                                                                                 
   
                                                                                       
   
                                                  
   
                                                                                        
    
	private function sourceToActivityType(string $sourceType): string {
		return match ($sourceType) {
			'interaction' => 'interaction',
			'task' => 'task',
			'emailLink' => 'email',
			'calendarLink' => 'calendar',
			default => $sourceType,
		};
	}                            

	   
                                                                                       
                                      
   
                                                                                              
   
                                                                                          
   
                                                                                        
    
	private function normaliseTypes(mixed $rawTypes): ?array {
		if ($rawTypes === null || $rawTypes === '' || $rawTypes === []) {
			return null;
		}

		if (is_array($rawTypes) === false) {
			$rawTypes = explode(',', (string)$rawTypes);
		}

		$allowed = ['interaction', 'task', 'email', 'calendar', 'worklog'];
		$result = [];
		foreach ($rawTypes as $type) {
			$type = strtolower(trim((string)$type));
			if (in_array($type, $allowed, true) === true) {
				$result[] = $type;
			}
		}

		if ($result === []) {
			return null;
		}

		return array_values(array_unique($result));
	}                      

	   
                                                                       
   
                                              
                                          
                                                               
   
                                                                 
   
                                                                                        
    
	private function querySchema(string $registerId, string $schemaId, array $filters): array {
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

			return $this->normaliseResultset(results: $results);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ActivityTimelineService: failed to query schema',
				[
					'exception' => $e->getMessage(),
					'schemaId' => $schemaId,
				]
			);
			return [];
		}         
	}                   

	   
                                                                           
   
                                                                      
   
                                                                         
   
                                                                                        
    
	private function normaliseResultset(mixed $results): array {
		if (is_array($results) === false) {
			return [];
		}

		$output = [];
		foreach ($results as $item) {
			$normalised = $this->normaliseResultItem(item: $item);
			if ($normalised !== null) {
				$output[] = $normalised;
			}
		}             

		return $output;
	}                          

	   
                                                              
   
                                                                          
                                                                           
                              
   
                                            
   
                                                                             
    
	private function normaliseResultItem(mixed $item): ?array {
		if (is_array($item) === true) {
			return $item;
		}

		if (is_object($item) === false) {
			return null;
		}

		if (method_exists($item, 'getObject') === true) {
			$value = $item->getObject();
			if (is_array($value) === true) {
				                                                      
				if (isset($value['id']) === false && method_exists($item, 'getUuid') === true) {
					$value['id'] = $item->getUuid();
				}

				return $value;
			}
		}

		return (array)$item;
	}                           

	   
                                                             
   
                                                                        
                                                           
                                                       
   
                                                                   
   
                                                                                        
    
	private function withinDateRange(?string $date, ?string $from, ?string $to): bool {
		if ($from === null && $to === null) {
			return true;
		}

		if ($date === null || $date === '') {
			                                                                      
			return false;
		}

		$itemTs = strtotime($date);
		if ($itemTs === false) {
			return false;
		}

		return $this->isAfterFrom(itemTs: $itemTs, from: $from)
			&& $this->isBeforeTo(itemTs: $itemTs, to: $to);
	}                       

	   
                                                                        
   
                                                 
                                                          
   
                                                                          
    
	private function isAfterFrom(int $itemTs, ?string $from): bool {
		if ($from === null) {
			return true;
		}

		$fromTs = strtotime($from);
		return $fromTs === false || $itemTs >= $fromTs;
	}                   

	   
                                                                                   
   
                                                                                     
   
                                                 
                                                        
   
                                                                          
    
	private function isBeforeTo(int $itemTs, ?string $to): bool {
		if ($to === null) {
			return true;
		}

		                                                                 
		$toString = $to;
		if (strlen($to) === 10) {
			$toString = $to . 'T23:59:59';
		}

		$toTs = strtotime($toString);
		return $toTs === false || $itemTs <= $toTs;
	}                  

	   
                                                              
   
                                                                               
                                                                              
                                                                                
   
                                                                            
                                            
   
                                                                                 
   
                                                                                                     
    
	public function resolveEntityQueryParams(string $entityType, string $entityId): array {
		return match ($entityType) {
			'client' => [
				'interaction' => [
					'ticketType' => TicketService::TYPE_CONTACTMOMENT,
					'client' => $entityId,
				],
				'task' => ['clientId' => $entityId],
				'emailLink' => ['linkedEntityType' => 'client', 'linkedEntityId' => $entityId],
				'calendarLink' => ['linkedEntityType' => 'client', 'linkedEntityId' => $entityId],
			],
			'request' => [
				'interaction' => [
					'ticketType' => TicketService::TYPE_CONTACTMOMENT,
					'parentTicket' => $entityId,
				],
				'task' => ['requestId' => $entityId],
				'emailLink' => ['linkedEntityType' => 'request', 'linkedEntityId' => $entityId],
				'calendarLink' => ['linkedEntityType' => 'request', 'linkedEntityId' => $entityId],
			],
			'lead' => [
				'emailLink' => ['linkedEntityType' => 'lead', 'linkedEntityId' => $entityId],
				'calendarLink' => ['linkedEntityType' => 'lead', 'linkedEntityId' => $entityId],
			],
			'contact' => [
				'emailLink' => ['linkedEntityType' => 'contact', 'linkedEntityId' => $entityId],
				'calendarLink' => ['linkedEntityType' => 'contact', 'linkedEntityId' => $entityId],
			],
			default => [],
		};             
	}                                

	   
                                                                      
   
                                                                                                       
                                                                              
                                                                            
                                                                        
   
                                                                                          
   
                                                                                                          
    
	public function normalizeActivity(string $sourceType, array $object, string $entityType, string $entityId): ?array {
		$id = (string)($object['id'] ?? $object['uuid'] ?? '');

		switch ($sourceType) {
			case 'interaction':
				                                                                 
				                                                                        
				$channel = (string)($object['channel'] ?? '');
				$type = 'interaction';
				if ($channel === 'worklog') {
					$type = 'worklog';
				}
				return [
					'type' => $type,
					'id' => $id,
					'title' => (string)($object['title'] ?? $object['description'] ?? ''),
					'description' => (string)($object['description'] ?? ''),
					'date' => $this->stringOrNull(value: ($object['occurredAt'] ?? null)),
					'user' => $this->stringOrNull(value: ($object['assignee'] ?? null)),
					'entityType' => $entityType,
					'entityId' => $entityId,
					'metadata' => [
						'channel' => $channel,
						'direction' => $this->stringOrNull(value: ($object['direction'] ?? null)),
						'duration' => $this->stringOrNull(value: ($object['duration'] ?? null)),
						'outcome' => $this->stringOrNull(value: ($object['outcome'] ?? null)),
					],
				];

			case 'task':
				return [
					'type' => 'task',
					'id' => $id,
					'title' => (string)($object['subject'] ?? $object['title'] ?? ''),
					'description' => (string)($object['description'] ?? ''),
					'date' => $this->stringOrNull(value: ($object['deadline'] ?? $object['createdAt'] ?? null)),
					'user' => $this->stringOrNull(value: ($object['assigneeUserId'] ?? null)),
					'entityType' => $entityType,
					'entityId' => $entityId,
					'metadata' => [
						'status' => $this->stringOrNull(value: ($object['status'] ?? null)),
						'priority' => $this->stringOrNull(value: ($object['priority'] ?? null)),
					],
				];

			case 'emailLink':
				return [
					'type' => 'email',
					'id' => $id,
					'title' => (string)($object['subject'] ?? ''),
					'description' => (string)($object['sender'] ?? ''),
					'date' => $this->stringOrNull(value: ($object['date'] ?? null)),
					'user' => null,
					'entityType' => $entityType,
					'entityId' => $entityId,
					'metadata' => [
						'direction' => $this->stringOrNull(value: ($object['direction'] ?? null)),
						'messageId' => $this->stringOrNull(value: ($object['messageId'] ?? null)),
					],
				];

			case 'calendarLink':
				return [
					'type' => 'calendar',
					'id' => $id,
					'title' => (string)($object['title'] ?? $object['subject'] ?? ''),
					'description' => (string)($object['notes'] ?? $object['description'] ?? ''),
					'date' => $this->stringOrNull(value: ($object['startDate'] ?? null)),
					'user' => null,
					'entityType' => $entityType,
					'entityId' => $entityId,
					'metadata' => [
						'endDate' => $this->stringOrNull(value: ($object['endDate'] ?? null)),
						'location' => $this->stringOrNull(value: ($object['location'] ?? null)),
					],
				];

			default:
				return null;
		}            
	}                         

	   
                                                                            
   
                                                                          
                                                                      
                                                                         
                               
   
                                                                             
                                 
   
                                                               
                                            
                                                                                    
   
                                                                    
   
                                                                       
   
                                                                                                   
    
	public function createWorklog(string $entityType, string $entityId, array $data): array {
		$config = $this->getConfig();
		if ($config['register'] === '' || $config['interaction'] === '') {
			throw new RuntimeException('Contactmoment register or schema not configured.');
		}

		$user = $this->userSession->getUser();

		$agentUid = '';
		if ($user !== null) {
			$agentUid = (string)$user->getUID();
		}

		$duration = $this->stringOrNull(value: ($data['duration'] ?? null));
		$summary = $this->stringOrNull(value: ($data['description'] ?? null));
		$date = $this->stringOrNull(value: ($data['date'] ?? null)) ?? (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

		$payload = [
			'channel' => 'worklog',
			                                                                 
			                                                     
			'direction' => 'internal',
			'description' => $summary ?? '',
			'duration' => $duration ?? '',
			'occurredAt' => $date,
			'assignee' => $agentUid,
		];

		if ($entityType === 'client') {
			$payload['client'] = $entityId;
		} elseif ($entityType === 'request') {
			$payload['parentTicket'] = $entityId;
		}

		$saved = $this->ticketService->save(TicketService::TYPE_CONTACTMOMENT, $payload);

		$savedArray = $this->extractObjectArray(saved: $saved);
		$normalized = $this->normalizeActivity(
			sourceType: 'interaction',
			object: $savedArray,
			entityType: $entityType,
			entityId: $entityId
		);

		return $normalized ?? [];
	}                     

	   
                                                                              
                                                            
   
                                                               
                                            
                                                                         
   
                                                                                                                  
   
                                                                                                   
    
	public function getWorklog(string $entityType, string $entityId, array $params): array {
		$page = max(1, (int)($params['_page'] ?? 1));
		$limit = $this->normaliseLimit(rawLimit: ($params['_limit'] ?? null));

		$config = $this->getConfig();
		if ($config['register'] === '' || $config['interaction'] === '') {
			return [
				'items' => [],
				'total' => 0,
				'page' => $page,
				'pages' => 1,
				'totalDuration' => 'PT0S',
			];
		}

		                                                                         
		                                                                          
		                                                         
		$filters = [
			'ticketType' => TicketService::TYPE_CONTACTMOMENT,
			'channel' => 'worklog',
		];
		if ($entityType === 'client') {
			$filters['client'] = $entityId;
		} elseif ($entityType === 'request') {
			$filters['parentTicket'] = $entityId;
		}

		$rawObjects = $this->querySchema(
			registerId: $config['register'],
			schemaId: $config['interaction'],
			filters: $filters
		);

		[$items, $totalSeconds] = $this->collectWorklogItems(
			rawObjects: $rawObjects,
			entityType: $entityType,
			entityId: $entityId
		);

		usort(
			$items,
			static function (array $left, array $right): int {
				return strcmp((string)($right['date'] ?? ''), (string)($left['date'] ?? ''));
			}
		);

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
			'items' => array_values(array_slice($items, $start, $limit)),
			'total' => $total,
			'page' => $page,
			'pages' => $pages,
			'totalDuration' => $this->secondsToIsoDuration(seconds: $totalSeconds),
		];
	}                  

	   
                                                                         
   
                                                                              
                                              
                                            
   
                                                                                            
    
	private function collectWorklogItems(iterable $rawObjects, string $entityType, string $entityId): array {
		$items = [];
		$totalSeconds = 0;
		foreach ($rawObjects as $object) {
			$normalised = $this->normalizeActivity(
				sourceType: 'interaction',
				object: $object,
				entityType: $entityType,
				entityId: $entityId
			);
			if ($normalised === null) {
				continue;
			}

			$items[] = $normalised;
			$duration = (string)($normalised['metadata']['duration'] ?? '');
			$totalSeconds += $this->isoDurationToSeconds(duration: $duration);
		}

		return [$items, $totalSeconds];
	}                           

	   
                                                                       
   
                                            
   
                                                      
   
                                                                                        
    
	private function extractObjectArray(mixed $saved): array {
		if (is_array($saved) === true) {
			return $saved;
		}

		if (is_object($saved) === true && method_exists($saved, 'getObject') === true) {
			$value = $saved->getObject();
			if (is_array($value) === true) {
				if (isset($value['id']) === false && method_exists($saved, 'getUuid') === true) {
					$value['id'] = $saved->getUuid();
				}

				return $value;
			}
		}

		if (is_object($saved) === true) {
			return (array)$saved;
		}

		return [];
	}                          

	   
                                                 
   
                                                                       
                            
   
                                                                 
   
                              
   
                                                                                        
    
	private function isoDurationToSeconds(string $duration): int {
		if ($duration === '') {
			return 0;
		}

		try {
			$interval = new DateInterval($duration);
		} catch (Throwable $e) {
			return 0;
		}

		$seconds = (((($interval->y * 365) + ($interval->m * 30) + $interval->d) * 24) + $interval->h) * 3600;
		$seconds += ($interval->i * 60);
		$seconds += $interval->s;
		return $seconds;
	}                            

	   
                                                                    
   
                                              
   
                                         
   
                                                                                        
    
	private function secondsToIsoDuration(int $seconds): string {
		if ($seconds <= 0) {
			return 'PT0S';
		}

		$hours = intdiv($seconds, 3600);
		$minutes = intdiv(($seconds % 3600), 60);
		$secs = ($seconds % 60);

		$output = 'PT';
		if ($hours > 0) {
			$output .= $hours . 'H';
		}

		if ($minutes > 0) {
			$output .= $minutes . 'M';
		}

		if ($secs > 0 || ($hours === 0 && $minutes === 0)) {
			$output .= $secs . 'S';
		}

		return $output;
	}                            

	   
                                                               
   
                                      
   
                                                 
    
	private function stringOrNull(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		$stringValue = '';
		if (is_scalar($value) === true) {
			$stringValue = (string)$value;
		}

		if ($stringValue === '') {
			return null;
		}

		return $stringValue;
	}                    

	   
                                   
   
                                                      
   
                                                                                           
    
	private function emptyResult(int $page): array {
		return [
			'items' => [],
			'total' => 0,
			'page' => $page,
			'pages' => 1,
		];
	}                   
}           
