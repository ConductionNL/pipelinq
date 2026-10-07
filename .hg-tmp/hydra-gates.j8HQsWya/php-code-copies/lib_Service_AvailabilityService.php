<?php

   
                                
  
                                                                          
                                                                          
                                                                          
                                                   
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                            
  
                                                   
                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                                                              
  
                                                                               
                                                                                 
                                                                         
                                                                              
                                                                              
  
                                                                                
                                                                               
                                                                              
                                                                                
                                                                  
  
                                                  
                                                                                                                             
                                                                                                                                               
  
                                                   
                                                   
   
class AvailabilityService {
	   
                              
   
            
    
	public const SLOT_INTERVAL_MINUTES = 15;

	   
                                    
   
            
    
	public const CACHE_TTL_SECONDS = 86400;

	   
                                                   
   
               
    
	public const RESOURCE_SCHEMA_KEY = 'resource_schema';

	   
                                                  
   
               
    
	public const BOOKING_SCHEMA_KEY = 'booking_schema';

	   
                                                  
   
               
    
	public const SERVICE_SCHEMA_KEY = 'service_schema';

	   
                                                            
   
               
    
	public const AVAILABILITY_CACHE_SCHEMA_KEY = 'availability_cache_schema';

	   
                                                                         
   
                                                                             
                                                                         
   
                    
    
	private ?object $calendarProvider = null;

	   
                                                                 
   
                                                                            
                                                                        
                                    
   
                    
    
	private ?ICache $resourceCache = null;

	   
                
   
                                                       
                                                                             
                                              
                                                                                         
    
	public function __construct(
		private IAppConfig $appConfig,
		private ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                                                
   
                                                                           
                                                                           
   
                                                                                          
   
                
   
                                                    
    
	public function setCalendarProvider(?object $provider): void {
		$this->calendarProvider = $provider;
	}                           

	   
                                                                  
   
                                                                             
                                                                          
                                                             
   
                                                 
                                              
                                                                   
   
                                                                                       
   
                                                    
   
                                                                                                                            
    
	public function computeAvailability(string $resourceId, string $date, int $serviceDurationMinutes): array {
		if ($resourceId === '' || $date === '' || $serviceDurationMinutes <= 0) {
			return [];
		}

		$weekDay = $this->weekDayFor(date: $date);
		$hours = $this->getWorkingHours(resourceId: $resourceId, weekDay: $weekDay);
		if ($hours === null) {
			return [];
		}

		$blocked = $this->getBlockedTimes(resourceId: $resourceId, date: $date);
		$aligned = $this->alignToSlots(
			startTime: $hours['openTime'],
			endTime: $hours['closeTime'],
			intervalMinutes: self::SLOT_INTERVAL_MINUTES
		);

		$free = [];
		foreach ($aligned as $slotStartMin) {
			$slotEndMin = ($slotStartMin + $serviceDurationMinutes);
			if ($slotEndMin > $this->minutesFromHHMM(time: $hours['closeTime'])) {
				continue;
			}

			if ($this->overlapsAny(startMin: $slotStartMin, endMin: $slotEndMin, blocks: $blocked) === true) {
				continue;
			}

			$free[] = [
				'startTime' => $this->minutesToHHMM(minutes: $slotStartMin),
				'endTime' => $this->minutesToHHMM(minutes: $slotEndMin),
				'durationMinutes' => $serviceDurationMinutes,
			];
		}

		return $free;
	}                           

	   
                                                                
   
                                                                            
                                                                         
                                                                      
   
                                                 
                                                                           
   
                                                           
   
                                                    
    
	public function getWorkingHours(string $resourceId, string $weekDay): ?array {
		$resource = $this->loadResource(resourceId: $resourceId);
		if ($resource === null) {
			return null;
		}

		$entries = ($resource['workingHours'] ?? []);
		if (is_array($entries) === false) {
			return null;
		}

		$needle = strtolower(trim($weekDay));
		foreach ($entries as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$day = strtolower((string)($entry['day'] ?? ''));
			if ($day !== $needle) {
				continue;
			}

			$open = (string)($entry['openTime'] ?? '');
			$close = (string)($entry['closeTime'] ?? '');
			if ($open === '' || $close === '') {
				return null;
			}

			return [
				'openTime' => $open,
				'closeTime' => $close,
			];
		}             

		return null;
	}                       

	   
                                                                       
   
            
                                                                        
                                                                          
                       
                                                                            
   
                                                                            
                                                                        
                                               
   
                                                 
                                              
   
                                                         
   
                                                    
    
	public function getBlockedTimes(string $resourceId, string $date): array {
		$blocks = [];

		$resource = $this->loadResource(resourceId: $resourceId);
		if ($resource !== null && $this->isOnVacation(resource: $resource, date: $date) === true) {
			                                                  
			$blocks[] = ['startMin' => 0, 'endMin' => (24 * 60)];
			return $blocks;
		}

		foreach ($this->loadOverlappingBookings(resourceId: $resourceId, date: $date) as $booking) {
			$startAt = (string)($booking['startAt'] ?? '');
			$endAt = (string)($booking['endAt'] ?? '');
			if ($startAt === '' || $endAt === '') {
				continue;
			}

			$buffers = $this->buffersFor(serviceId: (string)($booking['serviceId'] ?? ''));
			$startMin = $this->clampToDay(value: ($this->minutesOfDay(iso: $startAt, date: $date) - $buffers['before']));
			$endMin = $this->clampToDay(value: ($this->minutesOfDay(iso: $endAt, date: $date) + $buffers['after']));
			if ($endMin <= $startMin) {
				continue;
			}

			$blocks[] = ['startMin' => $startMin, 'endMin' => $endMin];
		}

		foreach ($this->fetchCalendarBlocks(resourceId: $resourceId, date: $date) as $cal) {
			$startMin = $this->minutesFromHHMM(time: $cal['startTime']);
			$endMin = $this->minutesFromHHMM(time: $cal['endTime']);
			if ($endMin <= $startMin) {
				continue;
			}

			$blocks[] = ['startMin' => $startMin, 'endMin' => $endMin];
		}

		return $blocks;
	}                       

	   
                                                                         
   
                                                                         
                                                                          
                                                                             
                                             
   
                                                       
                                                   
                                                            
   
                                                                        
   
                                                    
    
	public function alignToSlots(string $startTime, string $endTime, int $intervalMinutes): array {
		if ($intervalMinutes <= 0) {
			return [];
		}

		$start = $this->minutesFromHHMM(time: $startTime);
		$end = $this->minutesFromHHMM(time: $endTime);
		if ($end <= $start) {
			return [];
		}

		                                                                
		$first = (int)(ceil($start / $intervalMinutes) * $intervalMinutes);
		$slots = [];
		for ($cursor = $first; $cursor < $end; $cursor += $intervalMinutes) {
			$slots[] = $cursor;
		}

		return $slots;
	}                    

	   
                                                                 
   
                                               
   
                                                 
                                              
   
                
   
                                                    
    
	public function invalidateCache(string $resourceId, string $date): void {
		$entry = $this->loadCacheEntry(resourceId: $resourceId, date: $date);
		if ($entry === null) {
			return;
		}

		$uuid = $this->idOf(object: $entry);
		if ($uuid === '') {
			return;
		}

		$register = $this->registerId();
		$schema = $this->schemaId(key: self::AVAILABILITY_CACHE_SCHEMA_KEY);
		if ($register === '' || $schema === '') {
			return;
		}

		try {
			$this->getObjectService()->deleteObject(
				uuid: $uuid,
				register: $register,
				schema: $schema
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: availability cache invalidation failed',
				['resource' => $resourceId, 'date' => $date]
			);
		}
	}                       

	   
                                                                            
   
                                                                           
                                                                         
                                                                             
                                    
   
                                                 
                                              
                                                                                                       
   
                                                                                                
   
                                                    
   
                                                                                                                            
    
	public function getOrComputeCache(string $resourceId, string $date, ?int $serviceDurationMinutes = null): array {
		$duration = ($serviceDurationMinutes ?? self::SLOT_INTERVAL_MINUTES);
		$entry = $this->loadCacheEntry(resourceId: $resourceId, date: $date);
		$nowIso = $this->nowIso();

		if ($entry !== null) {
			$expiresAt = (string)($entry['expiresAt'] ?? '');
			$isStale = ($expiresAt === '' || strtotime($expiresAt) < strtotime($nowIso));
			return [
				'freeBlocks' => $this->normaliseFreeBlocks(blocks: ($entry['freeBlocks'] ?? [])),
				'generatedAt' => (string)($entry['generatedAt'] ?? ''),
				'expiresAt' => $expiresAt,
				'stale' => $isStale,
			];
		}

		$free = $this->computeAvailability(
			resourceId: $resourceId,
			date: $date,
			serviceDurationMinutes: $duration
		);
		$expiresAt = $this->isoOffsetSeconds(seconds: self::CACHE_TTL_SECONDS);
		$this->persistCacheEntry(
			resourceId: $resourceId,
			date: $date,
			freeBlocks: $free,
			generatedAt: $nowIso,
			expiresAt: $expiresAt
		);

		return [
			'freeBlocks' => $free,
			'generatedAt' => $nowIso,
			'expiresAt' => $expiresAt,
			'stale' => false,
		];
	}                         

	   
                                                                       
   
                                                 
   
                                     
    
	private function loadResource(string $resourceId): ?array {
		if ($resourceId === '') {
			return null;
		}

		$cache = $this->resourceCache();
		if ($cache !== null) {
			$hit = $cache->get('res:' . $resourceId);
			if (is_array($hit) === true) {
				return $hit;
			}
		}

		$register = $this->registerId();
		$schema = $this->schemaId(key: self::RESOURCE_SCHEMA_KEY);
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$result = $this->getObjectService()->find(
				id: $resourceId,
				register: $register,
				schema: $schema
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: resource load failed',
				['resource' => $resourceId]
			);
			return null;
		}

		$normalised = $this->toArray(object: $result);
		if ($cache !== null && $normalised !== null) {
			$cache->set('res:' . $resourceId, $normalised, 60);
		}

		return $normalised;
	}                    

	   
                                                               
   
                                                                         
                                          
   
                       
    
	private function resourceCache(): ?ICache {
		if ($this->resourceCache !== null) {
			return $this->resourceCache;
		}

		try {
			$this->resourceCache = $this->cacheFactory->createLocal('pipelinq_availability');
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: availability L1 cache unavailable');
			return null;
		}

		return $this->resourceCache;
	}                     

	   
                                                                      
   
                                                                            
                                                                      
                                                                   
   
                                                 
                                              
   
                                            
   
                                                                                                                            
    
	private function loadOverlappingBookings(string $resourceId, string $date): array {
		$register = $this->registerId();
		$schema = $this->schemaId(key: self::BOOKING_SCHEMA_KEY);
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'resourceId' => $resourceId,
					],
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: booking lookup failed',
				['resource' => $resourceId, 'date' => $date]
			);
			return [];
		}

		$dayStart = ($date . 'T00:00:00');
		$dayEnd = ($date . 'T23:59:59');
		$matches = [];
		foreach (($rows ?? []) as $row) {
			$booking = $this->toArray(object: $row);
			if ($booking === null) {
				continue;
			}

			$startAt = (string)($booking['startAt'] ?? '');
			$endAt = (string)($booking['endAt'] ?? '');
			if ($startAt === '' || $endAt === '') {
				continue;
			}

			                                     
			if ($endAt < $dayStart || $startAt > $dayEnd) {
				continue;
			}

			$matches[] = $booking;
		}

		return $matches;
	}                               

	   
                                            
   
                                                                  
   
                                          
    
	private function buffersFor(string $serviceId): array {
		if ($serviceId === '') {
			return ['before' => 0, 'after' => 0];
		}

		$register = $this->registerId();
		$schema = $this->schemaId(key: self::SERVICE_SCHEMA_KEY);
		if ($register === '' || $schema === '') {
			return ['before' => 0, 'after' => 0];
		}

		try {
			$svc = $this->getObjectService()->find(
				id: $serviceId,
				register: $register,
				schema: $schema
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: service lookup failed', ['service' => $serviceId]);
			return ['before' => 0, 'after' => 0];
		}

		$data = $this->toArray(object: $svc);
		if ($data === null) {
			return ['before' => 0, 'after' => 0];
		}

		return [
			'before' => max(0, (int)($data['bufferBeforeMinutes'] ?? 0)),
			'after' => max(0, (int)($data['bufferAfterMinutes'] ?? 0)),
		];
	}                  

	   
                                                                 
   
                                                          
                                              
   
                
    
	private function isOnVacation(array $resource, string $date): bool {
		$vacations = ($resource['vacations'] ?? []);
		if (is_array($vacations) === false) {
			return false;
		}

		foreach ($vacations as $vac) {
			if (is_array($vac) === false) {
				continue;
			}

			$start = (string)($vac['startDate'] ?? '');
			$end = (string)($vac['endDate'] ?? '');
			if ($start === '' || $end === '') {
				continue;
			}

			if ($date >= $start && $date <= $end) {
				return true;
			}
		}

		return false;
	}                    

	   
                                                                       
   
                                                 
                                              
   
                                     
    
	private function loadCacheEntry(string $resourceId, string $date): ?array {
		$register = $this->registerId();
		$schema = $this->schemaId(key: self::AVAILABILITY_CACHE_SCHEMA_KEY);
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'resourceId' => $resourceId,
						'date' => $date,
					],
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: availability cache read failed',
				['resource' => $resourceId, 'date' => $date]
			);
			return null;
		}

		foreach (($rows ?? []) as $row) {
			$entry = $this->toArray(object: $row);
			if ($entry !== null) {
				return $entry;
			}
		}

		return null;
	}                      

	   
                                                        
   
                                                 
                                              
                                                                                                               
                                             
                                           
   
                
    
	private function persistCacheEntry(
		string $resourceId,
		string $date,
		array $freeBlocks,
		string $generatedAt,
		string $expiresAt,
	): void {
		$register = $this->registerId();
		$schema = $this->schemaId(key: self::AVAILABILITY_CACHE_SCHEMA_KEY);
		if ($register === '' || $schema === '') {
			return;
		}

		$payload = [
			'resourceId' => $resourceId,
			'date' => $date,
			'freeBlocks' => $freeBlocks,
			'generatedAt' => $generatedAt,
			'expiresAt' => $expiresAt,
		];

		try {
			$this->getObjectService()->saveObject(
				object: $payload,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: null
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: availability cache write failed',
				['resource' => $resourceId, 'date' => $date]
			);
		}
	}                         

	   
                                                                
   
                                                 
                                              
   
                                                                 
    
	private function fetchCalendarBlocks(string $resourceId, string $date): array {
		if ($this->calendarProvider === null) {
			return [];
		}

		if (method_exists($this->calendarProvider, 'getBlockedTimes') === false) {
			return [];
		}

		try {
			                                                  
			$blocks = $this->calendarProvider->getBlockedTimes($resourceId, $date);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: calendar provider failed',
				['resource' => $resourceId, 'date' => $date]
			);
			return [];
		}

		if (is_array($blocks) === false) {
			return [];
		}

		$normalised = [];
		foreach ($blocks as $block) {
			if (is_array($block) === false) {
				continue;
			}

			$normalised[] = [
				'startTime' => (string)($block['startTime'] ?? ''),
				'endTime' => (string)($block['endTime'] ?? ''),
			];
		}

		return $normalised;
	}                           

	   
                                                    
   
                                                            
                                                        
                                                                                
   
                
    
	private function overlapsAny(int $startMin, int $endMin, array $blocks): bool {
		foreach ($blocks as $block) {
			$blockStart = $block['startMin'];
			$blockEnd = $block['endMin'];
			if ($blockEnd <= $blockStart) {
				continue;
			}

			if ($startMin < $blockEnd && $endMin > $blockStart) {
				return true;
			}
		}

		return false;
	}                   

	   
                                                                     
   
                                                      
   
               
    
	private function minutesFromHHMM(string $time): int {
		if ($time === '' || preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $time, $match) !== 1) {
			return 0;
		}

		$hours = (int)$match[1];
		$minutes = (int)$match[2];
		if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59) {
			return 0;
		}

		return (($hours * 60) + $minutes);
	}                       

	   
                                                                
   
                                                                     
   
                  
    
	private function minutesToHHMM(int $minutes): string {
		$clamped = $this->clampToDay(value: $minutes);
		$hourPart = intdiv($clamped, 60);
		$minutePart = ($clamped % 60);
		return sprintf('%02d:%02d', $hourPart, $minutePart);
	}                     

	   
                                                      
   
                                    
   
               
    
	private function clampToDay(int $value): int {
		if ($value < 0) {
			return 0;
		}

		if ($value > (24 * 60)) {
			return (24 * 60);
		}

		return $value;
	}                  

	   
                                                                                              
   
                                                                           
                                                    
   
                                                                      
                                                
   
               
    
	private function minutesOfDay(string $iso, string $date): int {
		$timestamp = strtotime($iso);
		if ($timestamp === false) {
			return 0;
		}

		$dayStart = strtotime($date . 'T00:00:00');
		$dayEnd = strtotime($date . 'T23:59:59');
		if ($dayStart === false || $dayEnd === false) {
			return 0;
		}

		if ($timestamp <= $dayStart) {
			return 0;
		}

		if ($timestamp >= $dayEnd) {
			return (24 * 60);
		}

		$minutesFromDayStart = intdiv(($timestamp - $dayStart), 60);
		return $this->clampToDay(value: (int)$minutesFromDayStart);
	}                    

	   
                                          
   
                                         
   
                                                                
    
	private function weekDayFor(string $date): string {
		try {
			$dateTime = new DateTimeImmutable($date, new DateTimeZone('UTC'));
		} catch (\Throwable $e) {
			return '';
		}

		return strtolower($dateTime->format('l'));
	}                  

	   
                                                      
   
                                                      
   
                                                                                       
    
	private function normaliseFreeBlocks(mixed $blocks): array {
		if (is_array($blocks) === false) {
			return [];
		}

		$out = [];
		foreach ($blocks as $block) {
			if (is_array($block) === false) {
				continue;
			}

			$out[] = [
				'startTime' => (string)($block['startTime'] ?? ''),
				'endTime' => (string)($block['endTime'] ?? ''),
				'durationMinutes' => (int)($block['durationMinutes'] ?? 0),
			];
		}

		return $out;
	}                           

	   
                        
   
                  
    
	private function nowIso(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');
	}              

	   
                                       
   
                                          
   
                  
    
	private function isoOffsetSeconds(int $seconds): string {
		$dateTime = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
			->modify(sprintf('+%d seconds', $seconds));
		return $dateTime->format('Y-m-d\TH:i:sP');
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

	   
                                                     
   
                                                    
   
                  
    
	private function idOf(array $object): string {
		if (isset($object['@self']) === true && is_array($object['@self']) === true) {
			$self = $object['@self'];
			if (isset($self['id']) === true) {
				return (string)$self['id'];
			}

			if (isset($self['uuid']) === true) {
				return (string)$self['uuid'];
			}
		}

		if (isset($object['id']) === true) {
			return (string)$object['id'];
		}

		if (isset($object['uuid']) === true) {
			return (string)$object['uuid'];
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
