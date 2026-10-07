<?php

   
                               
  
                                                                                  
                                                                                
                                                                                  
                                                                              
                                               
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                            
  
                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Lifecycle\SchemaLifecycleGraph;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                                                     
  
                        
                                
                         
                         
                                     
  
                                                  
                                                                         
                                                                              
                                                                      
                                                                          
                                                                      
                                                                  
                          
  
                                                   
   
class WalkInQueueService {
	   
                                                       
   
               
    
	public const TICKET_SCHEMA_KEY = 'walkInTicket_schema';

	   
                                                  
   
               
    
	public const SERVICE_SCHEMA_KEY = 'service_schema';

	   
                                                        
   
                           
    
	public const STATUSES = [
		'waiting',
		'called',
		'served',
		'abandoned',
	];

	   
                                                                                  
   
               
    
	private const TICKET_SCHEMA_SLUG = 'walkInTicket';

	   
                                                                          
                                                                                
                                                                                  
                                                                                    
   
                                          
    
	private const FALLBACK_TRANSITIONS = [
		'waiting' => ['called', 'abandoned'],
		'called' => ['served', 'abandoned'],
		'served' => [],
		'abandoned' => [],
	];

	   
                                                                           
   
            
    
	public const QUEUE_PAGE_SIZE = 200;

	   
                
   
                                                       
                                                                                       
                                              
                                                                                         
    
	public function __construct(
		private IAppConfig $appConfig,
		private AvailabilityService $availabilityService,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                              
   
                                                                           
                                                                      
                                                                             
                                                                            
                                                 
   
                                                                              
                                                        
   
                                             
   
                                                               
                                                            
   
                                                    
   
                                                               
                                                                              
                                                                           
                                                                   
                                                                           
                                                                      
                                                                            
                                                       
    
	public function createTicket(array $data): string {
		$displayName = trim((string)($data['displayName'] ?? ''));
		if ($displayName === '') {
			throw new InvalidArgumentException('displayName is required');
		}

		$serviceId = trim((string)($data['serviceId'] ?? ''));
		$customerId = trim((string)($data['customerId'] ?? ''));
		$phone = trim((string)($data['phone'] ?? ''));

		$nowIso = $this->nowIso();

		$payload = [
			'displayName' => $this->truncate(value: $displayName, max: 255),
			'arrivedAt' => $nowIso,
			'status' => 'waiting',
		];

		if ($customerId !== '') {
			$payload['customerId'] = $customerId;
		}

		if ($phone !== '') {
			$payload['phone'] = $this->truncate(value: $phone, max: 32);
		}

		if ($serviceId !== '') {
			$payload['serviceId'] = $serviceId;
			$eta = $this->computeEstimatedReadyAt(serviceId: $serviceId);
			if ($eta !== '') {
				$payload['estimatedReadyAt'] = $eta;
			}
		}

		$saved = $this->saveTicket(payload: $payload, uuid: null);
		$uuid = $this->idOf(object: $saved);
		if ($uuid === '') {
			throw new RuntimeException('WalkInTicket save returned no id');
		}

		return $uuid;
	}                    

	   
                                                                  
   
                                                                    
                                                                             
                   
   
                                                                             
   
                                                                               
   
                                                    
    
	public function callNext(string $assignedResourceId = ''): string {
		$waiting = $this->listByStatus(status: 'waiting');
		if ($waiting === []) {
			return '';
		}

		usort(
			$waiting,
			static function (array $left, array $right): int {
				return strcmp(
					(string)($left['arrivedAt'] ?? ''),
					(string)($right['arrivedAt'] ?? '')
				);
			}
		);

		$first = $waiting[0];
		$ticket = $this->ticketAsArray(value: $first);
		$uuid = $this->idOf(object: $first);
		if ($uuid === '') {
			return '';
		}

		$this->callTicket(ticketId: $uuid, assignedResourceId: $assignedResourceId, preloaded: $ticket);
		return $uuid;
	}                

	   
                                                            
   
                                        
                                                           
                                                                                        
   
                
   
                                                                   
   
                                                    
    
	public function callTicket(string $ticketId, string $assignedResourceId = '', ?array $preloaded = null): void {
		$ticket = $preloaded ?? $this->loadTicket(ticketId: $ticketId);
		$current = (string)($ticket['status'] ?? '');
		$this->assertTransitionAllowed(from: $current, to: 'called');

		$ticket['status'] = 'called';
		if ($assignedResourceId !== '') {
			$ticket['assignedResourceId'] = $assignedResourceId;
		}

		$this->saveTicket(payload: $this->stripSelf(payload: $ticket), uuid: $ticketId);
	}                  

	   
                                                  
   
                                                             
   
                                        
   
                
   
                                                                   
   
                                                    
    
	public function serveTicket(string $ticketId): void {
		$ticket = $this->loadTicket(ticketId: $ticketId);
		$current = (string)($ticket['status'] ?? '');
		$this->assertTransitionAllowed(from: $current, to: 'served');

		$ticket['status'] = 'served';
		$ticket['actualServedAt'] = $this->nowIso();

		$this->saveTicket(payload: $this->stripSelf(payload: $ticket), uuid: $ticketId);
	}                   

	   
                                                     
   
                                        
   
                
   
                                                                   
   
                                                    
    
	public function abandonTicket(string $ticketId): void {
		$ticket = $this->loadTicket(ticketId: $ticketId);
		$current = (string)($ticket['status'] ?? '');
		$this->assertTransitionAllowed(from: $current, to: 'abandoned');

		$ticket['status'] = 'abandoned';

		$this->saveTicket(payload: $this->stripSelf(payload: $ticket), uuid: $ticketId);
	}                     

	   
                                                       
   
                                                                           
                                                                           
                                                        
   
                                              
   
                                                    
    
	public function rebalance(): int {
		$waiting = $this->listByStatus(status: 'waiting');
		if ($waiting === []) {
			return 0;
		}

		$touched = 0;
		foreach ($waiting as $row) {
			if ($this->rebalanceTicket(row: $row) === true) {
				$touched++;
			}
		}

		if ($touched > 0) {
			$this->logger->info(
				'Pipelinq: walk-in queue rebalanced',
				['updated' => $touched, 'total' => count($waiting)]
			);
		}

		return $touched;
	}                 

	   
                                                                   
   
                                                                             
   
                                                                                       
    
	private function rebalanceTicket(array|object $row): bool {
		$ticket = $this->ticketAsArray(value: $row);
		$uuid = $this->idOf(object: $row);
		if ($uuid === '') {
			return false;
		}

		$serviceId = (string)($ticket['serviceId'] ?? '');
		if ($serviceId === '') {
			                                                      
			return false;
		}

		try {
			$eta = $this->computeEstimatedReadyAt(serviceId: $serviceId);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: walk-in rebalance ETA failed',
				['ticket' => $uuid, 'service' => $serviceId]
			);
			return false;
		}

		if ($eta === '') {
			return false;
		}

		if ((string)($ticket['estimatedReadyAt'] ?? '') === $eta) {
			return false;
		}

		$ticket['estimatedReadyAt'] = $eta;

		try {
			$this->saveTicket(payload: $this->stripSelf(payload: $ticket), uuid: $uuid);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: walk-in rebalance save failed',
				['ticket' => $uuid]
			);
			return false;
		}

		return true;
	}                       

	   
                                                        
   
                                       
                                    
   
                
   
                                                   
   
                                                    
    
	public function assertTransitionAllowed(string $from, string $to): void {
		$allowed = self::allowedTransitions();
		if (isset($allowed[$from]) === false) {
			throw new InvalidArgumentException(
				sprintf('Unknown WalkInTicket status: %s', $from)
			);
		}

		if (in_array($to, $allowed[$from], true) === false) {
			throw new InvalidArgumentException(
				sprintf('Invalid WalkInTicket transition: %s -> %s', $from, $to)
			);
		}
	}                               

	   
                                             
   
                                                                                 
                                                              
                                                                                 
                                                                                
                                                                           
                                                                                  
                                                              
   
                                             
                                                                
    
	public static function allowedTransitions(): array {
		$graph = (new SchemaLifecycleGraph())->fullAdjacencyFor(schemaSlug: self::TICKET_SCHEMA_SLUG);
		if ($graph === []) {
			return self::FALLBACK_TRANSITIONS;
		}

		return $graph;
	}                          

	   
                                                                         
   
                                                                          
                                                                            
   
                                               
   
                                                                     
    
	private function computeEstimatedReadyAt(string $serviceId): string {
		if ($serviceId === '') {
			return '';
		}

		$service = $this->loadServiceQuiet(serviceId: $serviceId);
		if ($service === null) {
			return '';
		}

		$duration = (int)($service['durationMinutes'] ?? 0);
		if ($duration <= 0) {
			return '';
		}

		$candidates = $this->loadCandidateResources(service: $service);
		if ($candidates === []) {
			return '';
		}

		$date = $this->todayLocalDate();
		$earliest = $this->findEarliestSlotStart(
			candidates: $candidates,
			date: $date,
			duration: $duration,
			serviceId: $serviceId
		);

		if ($earliest === null) {
			return '';
		}

		return $this->localTimeToUtcIso(date: $date, hhmm: $earliest);
	}                               

	   
                                                                               
   
                                                                 
                                            
                                                              
                                                                       
   
                                                                               
    
	private function findEarliestSlotStart(array $candidates, string $date, int $duration, string $serviceId): ?string {
		$earliest = null;
		foreach ($candidates as $resourceId) {
			try {
				$slots = $this->availabilityService->computeAvailability(
					resourceId: $resourceId,
					date: $date,
					serviceDurationMinutes: $duration
				);
			} catch (\Throwable $e) {
				$this->logger->warning(
					'Pipelinq: walk-in ETA availability lookup failed',
					['service' => $serviceId, 'resource' => $resourceId]
				);
				continue;
			}

			foreach ($slots as $slot) {
				$startTime = $slot['startTime'];
				if ($startTime === '') {
					continue;
				}

				if ($earliest === null || strcmp($startTime, $earliest) < 0) {
					$earliest = $startTime;
				}
			}
		}             

		return $earliest;
	}                             

	   
                                                                                  
   
                                                
   
                                            
    
	private function listByStatus(string $status): array {
		$register = $this->registerId();
		$schema = $this->schemaId(key: self::TICKET_SCHEMA_KEY);
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => ['status' => $status],
					'register' => $register,
					'schema' => $schema,
					'limit' => self::QUEUE_PAGE_SIZE,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: walk-in queue lookup failed',
				['status' => $status]
			);
			return [];
		}

		if (is_array($rows) === false) {
			return [];
		}

		$out = [];
		foreach (array_values($rows) as $row) {
			$arr = $this->toArray(object: $row);
			if ($arr !== null) {
				$out[] = $arr;
			}
		}

		return $out;
	}                    

	   
                                                       
   
                                        
   
                                
   
                                              
                                          
    
	private function loadTicket(string $ticketId): array {
		if ($ticketId === '') {
			throw new InvalidArgumentException('ticketId must be a non-empty string');
		}

		$register = $this->registerId();
		$schema = $this->schemaId(key: self::TICKET_SCHEMA_KEY);
		if ($register === '' || $schema === '') {
			throw new RuntimeException('WalkInTicket register/schema not configured');
		}

		try {
			$found = $this->getObjectService()->find(
				id: $ticketId,
				register: $register,
				schema: $schema
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: walk-in ticket load failed', ['ticket' => $ticketId]);
			throw new RuntimeException('WalkInTicket lookup failed', 0, $e);
		}

		$data = $this->toArray(object: $found);
		if ($data === null) {
			throw new RuntimeException(sprintf('WalkInTicket %s not found', $ticketId));
		}

		return $data;
	}                  

	   
                                          
   
                                               
   
                                     
    
	private function loadServiceQuiet(string $serviceId): ?array {
		if ($serviceId === '') {
			return null;
		}

		$register = $this->registerId();
		$schema = $this->schemaId(key: self::SERVICE_SCHEMA_KEY);
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$found = $this->getObjectService()->find(
				id: $serviceId,
				register: $register,
				schema: $schema
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: walk-in service load failed', ['service' => $serviceId]);
			return null;
		}

		return $this->toArray(object: $found);
	}                        

	   
                                                                          
   
                                                                         
                                                                          
                                                                         
                                                      
   
                                                        
   
                              
    
	private function loadCandidateResources(array $service): array {
		$register = $this->registerId();
		$schema = $this->schemaId(key: 'resource_schema');
		$requiredTypes = $this->resolveRequiredTypes(service: $service);

		if ($register === '' || $schema === '') {
			return [];
		}

		$candidates = [];
		foreach ($this->fetchResourceRows(register: $register, schema: $schema) as $row) {
			$resource = $this->toArray(object: $row);
			if ($resource === null || $this->isCandidateResource(resource: $resource, requiredTypes: $requiredTypes) === false) {
				continue;
			}

			$id = $this->idOf(object: $resource);
			if ($id !== '') {
				$candidates[] = $id;
			}
		}             

		return $candidates;
	}                              

	   
                                                         
   
                                                        
   
                              
    
	private function resolveRequiredTypes(array $service): array {
		$requiredTypes = ($service['requiredResourceTypes'] ?? []);
		if (is_array($requiredTypes) === false) {
			return [];
		}

		return $requiredTypes;
	}                            

	   
                                                                                 
   
                                            
                                                 
   
                                                   
    
	private function fetchResourceRows(string $register, string $schema): array {
		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [],
					'register' => $register,
					'schema' => $schema,
					'limit' => self::QUEUE_PAGE_SIZE,
				]
			);
		} catch (\Throwable $e) {
			return [];
		}

		if (is_array($rows) === false) {
			return [];
		}

		return array_values($rows);
	}                         

	   
                                                            
   
                                                                         
                  
   
                                                              
                                                                                        
   
                
    
	private function isCandidateResource(array $resource, array $requiredTypes): bool {
		if (($resource['bookable'] ?? true) === false) {
			return false;
		}

		$status = (string)($resource['status'] ?? 'active');
		if ($status !== 'active') {
			return false;
		}

		if ($requiredTypes !== []) {
			$type = (string)($resource['type'] ?? '');
			if (in_array($type, $requiredTypes, true) === false) {
				return false;
			}
		}

		return true;
	}                           

	   
                                                                        
   
                                                        
                                              
   
                                       
    
	private function saveTicket(array $payload, ?string $uuid): array|object {
		$register = $this->registerId();
		$schema = $this->schemaId(key: self::TICKET_SCHEMA_KEY);
		if ($register === '' || $schema === '') {
			throw new RuntimeException('WalkInTicket register/schema not configured');
		}

		return $this->getObjectService()->saveObject(
			object: $payload,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: $uuid
		);
	}                  

	   
                                                                         
   
                                                                    
   
                                
    
	private function ticketAsArray(array|object $value): array {
		$arr = $this->toArray(object: $value);
		return ($arr ?? []);
	}                     

	   
                                                 
   
                                                        
   
                                
    
	private function stripSelf(array $payload): array {
		if (array_key_exists('@self', $payload) === true) {
			unset($payload['@self']);
		}

		return $payload;
	}                 

	   
                                          
   
                                   
                                   
   
                  
    
	private function truncate(string $value, int $max): string {
		if (strlen($value) <= $max) {
			return $value;
		}

		return substr($value, 0, $max);
	}                

	   
                                                                    
   
                                                                        
                                                                         
                                                                       
   
                                   
                                                     
   
                                                                  
    
	private function localTimeToUtcIso(string $date, string $hhmm): string {
		$composed = ($date . 'T' . $hhmm . ':00');
		try {
			$timestamp = new DateTimeImmutable($composed, new DateTimeZone('UTC'));
		} catch (\Throwable $e) {
			return '';
		}

		return $timestamp->format('Y-m-d\TH:i:sP');
	}                         

	   
                                                  
   
                  
    
	private function todayLocalDate(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
	}                      

	   
                        
   
                  
    
	private function nowIso(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');
	}              

	   
                                             
   
                                                                       
                                                                      
                                                                           
                                                                            
                                                                           
                                                                      
   
                                                                       
    
	private function registerId(): string {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($registerId === '') {
			$this->logger->warning(
				'Pipelinq: app-config "register" is not configured; OpenRegister calls are refused, not run unscoped'
			);
		}

		return $registerId;
	}                  

	   
                                          
   
                                      
   
                  
    
	private function schemaId(string $key): string {
		return $this->appConfig->getValueString(Application::APP_ID, $key, '');
	}                

	   
                                                                  
   
                                                                           
   
                  
    
	private function idOf(array|object $object): string {
		$arr = $this->toArray(object: $object);
		if ($arr === null) {
			return '';
		}

		if (isset($arr['@self']) === true && is_array($arr['@self']) === true) {
			$self = $arr['@self'];
			if (isset($self['id']) === true) {
				return (string)$self['id'];
			}

			if (isset($self['uuid']) === true) {
				return (string)$self['uuid'];
			}
		}

		if (isset($arr['id']) === true) {
			return (string)$arr['id'];
		}

		if (isset($arr['uuid']) === true) {
			return (string)$arr['uuid'];
		}

		return '';
	}            

	   
                                                                 
   
                                                
   
                                     
    
	private function toArray(mixed $object): ?array {
		if ($object === null) {
			return null;
		}

		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true) {
			if (method_exists($object, 'jsonSerialize') === true) {
				$serialised = $object->jsonSerialize();
				if (is_array($serialised) === true) {
					return $serialised;
				}
			}

			if (method_exists($object, 'toArray') === true) {
				$arr = $object->toArray();
				if (is_array($arr) === true) {
					return $arr;
				}
			}

			return (array)$object;
		}

		return null;
	}               

	   
                                                                
   
                                              
    
	private function getObjectService(): object {
		                                                                 
		                                                              
		return $this->objectService;
	}                        
}           
