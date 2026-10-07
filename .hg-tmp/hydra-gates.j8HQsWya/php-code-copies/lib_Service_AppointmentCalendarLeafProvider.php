<?php

   
                                            
  
                                                                                
                                                                                 
                                                                                 
                                                                           
                                                                              
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                            
  
                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

   
                                                            
  
                                             
  
                                                                          
                                                                         
                                                                               
                                                                            
                          
  
                                                                      
                                                                     
                                                                          
                                                                 
  
                                                                               
                                                                           
                                                                             
                                                                               
                                                                            
              
  
                                                  
                                                                                  
                                                                           
  
                                                   
   
class AppointmentCalendarLeafProvider {

	   
                                                  
   
               
    
	public const BOOKING_SCHEMA_KEY = 'booking_schema';

	   
                                                   
   
               
    
	public const RESOURCE_SCHEMA_KEY = 'resource_schema';

	   
                                                  
   
               
    
	public const SERVICE_SCHEMA_KEY = 'service_schema';

	   
                                                          
   
               
    
	public const CUSTOMER_SCHEMA_KEY = 'contact_schema';

	   
                                                                         
   
                    
    
	private ?object $leafOverride = null;

	   
                
   
                                                                           
                                                       
                                                                            
                                                                             
                                              
    
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private IUserManager $userManager,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                         
   
                                                                             
                                                                
                                                     
   
                                                             
   
                
   
                                                    
    
	public function setLeaf(?object $leaf): void {
		$this->leafOverride = $leaf;
	}               

	   
                                                    
   
                                                                       
                                                                          
                                                                        
                                                                        
                                       
   
                                                                        
                                                                         
   
                                                 
                                              
   
                                                                 
   
                                                    
    
	public function getBlockedTimes(string $resourceId, string $date): array {
		if ($resourceId === '' || $date === '') {
			return [];
		}

		$resource = $this->loadResource(resourceId: $resourceId);
		if ($resource === null) {
			return [];
		}

		$calendarSyncId = (string)($resource['calendarSyncId'] ?? '');
		if ($calendarSyncId === '') {
			return [];
		}

		$leaf = $this->resolveLeaf();
		if ($leaf === null) {
			return [];
		}

		if (method_exists($leaf, 'getEventsForObject') === false) {
			return [];
		}

		try {
			                                                                         
			$events = $leaf->getEventsForObject($calendarSyncId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: calendar leaf getEventsForObject failed',
				['resource' => $resourceId, 'date' => $date]
			);
			return [];
		}

		if (is_array($events) === false) {
			return [];
		}

		return $this->eventsToBlocks(events: $events, date: $date);
	}                       

	   
                                                          
   
                                                                        
                                                                      
                                                                          
                                                                    
                                                                        
   
                                          
   
                
   
                                                    
    
	public function pushBookingEvent(string $bookingId): void {
		if ($bookingId === '') {
			return;
		}

		$leaf = $this->resolveLeaf();
		if ($leaf === null) {
			return;
		}

		$booking = $this->loadBooking(bookingId: $bookingId);
		if ($booking === null) {
			return;
		}

		$status = (string)($booking['status'] ?? '');
		if ($status !== 'confirmed') {
			return;
		}

		$service = $this->loadServiceQuiet(serviceId: (string)($booking['serviceId'] ?? ''));
		$summary = $this->buildSummary(booking: $booking, service: $service);
		$body = $this->buildDescription(booking: $booking, service: $service);

		$registerId = $this->registerNumeric();
		$schemaId = $this->schemaNumeric(key: self::BOOKING_SCHEMA_KEY);

		                                                               
		                                                                 
		                                                                    
		$this->dropExistingEvents(leaf: $leaf, bookingUuid: $bookingId);

		foreach ($this->resourcesFromBooking(booking: $booking) as $resourceId) {
			$resource = $this->loadResource(resourceId: $resourceId);
			if ($resource === null) {
				continue;
			}

			$attendees = $this->attendeesFor(resource: $resource);
			$payload = [
				'summary' => $summary,
				'dtstart' => (string)($booking['startAt'] ?? ''),
				'dtend' => (string)($booking['endAt'] ?? ''),
				'description' => $body,
				'attendees' => $attendees,
			];

			try {
				if (method_exists($leaf, 'createEvent') === false) {
					$this->logger->warning('Pipelinq: calendar leaf missing createEvent method');
					return;
				}

				                                                                         
				$leaf->createEvent($registerId, $schemaId, $bookingId, $summary, $payload);
			} catch (Throwable $e) {
				$this->logger->warning(
					'Pipelinq: calendar leaf createEvent failed',
					['booking' => $bookingId, 'resource' => $resourceId]
				);
			}
		}             
	}                        

	   
                                                        
   
                                                                            
                                                                      
   
                                                        
                                                                           
   
                  
    
	private function buildSummary(array $booking, ?array $service): string {
		$customer = $this->customerLabel(booking: $booking);
		$svcName = '';
		if ($service !== null) {
			$svcName = (string)($service['name'] ?? '');
		}

		if ($customer === '' && $svcName === '') {
			return 'Pipelinq booking';
		}

		if ($customer === '') {
			return $svcName;
		}

		if ($svcName === '') {
			return $customer;
		}

		return sprintf('%s – %s', $customer, $svcName);
	}                    

	   
                                                                        
   
                                                        
                                                                           
   
                  
    
	private function buildDescription(array $booking, ?array $service): string {
		$parts = [];
		$detail = '';
		if ($service !== null) {
			$detail = (string)($service['description'] ?? ($service['name'] ?? ''));
		}

		if ($detail !== '') {
			$parts[] = $detail;
		}

		$link = $this->deepLinkFor(booking: $booking);
		if ($link !== '') {
			$parts[] = sprintf('Pipelinq: %s', $link);
		}

		if ($parts === []) {
			return '';
		}

		return implode("\n\n", $parts);
	}                        

	   
                                                         
   
                                                                           
                                                         
   
                                                        
   
                  
    
	private function deepLinkFor(array $booking): string {
		$bookingId = $this->idOf(object: $booking);
		if ($bookingId === '') {
			return '';
		}

		try {
			$base = $this->urlGenerator->getAbsoluteURL(
				sprintf('/index.php/apps/pipelinq/booking/%s', rawurlencode($bookingId))
			);
		} catch (Throwable $e) {
			return sprintf('/apps/pipelinq/booking/%s', rawurlencode($bookingId));
		}

		return $base;
	}                   

	   
                                                          
   
                                                                 
                                                                     
                                              
   
                                                        
   
                  
    
	private function customerLabel(array $booking): string {
		$snapshot = (string)($booking['customerName'] ?? '');
		if ($snapshot !== '') {
			return $snapshot;
		}

		$customerId = (string)($booking['customerId'] ?? '');
		if ($customerId === '') {
			return '';
		}

		$register = $this->registerSlug();
		$schema = $this->schemaSlug(key: self::CUSTOMER_SCHEMA_KEY);
		if ($register === '' || $schema === '') {
			return $customerId;
		}

		try {
			$found = $this->getObjectService()->find(
				id: $customerId,
				register: $register,
				schema: $schema
			);
		} catch (Throwable $e) {
			return $customerId;
		}

		$data = $this->toArray(object: $found);
		if ($data === null) {
			return $customerId;
		}

		$name = (string)($data['name'] ?? '');
		if ($name === '') {
			$name = (string)($data['displayName'] ?? '');
		}

		if ($name === '') {
			return $customerId;
		}

		return $name;
	}                     

	   
                                                                    
   
                                                                        
                                                                         
                                            
   
                                                          
   
                              
    
	private function attendeesFor(array $resource): array {
		$userId = (string)($resource['userId'] ?? '');
		if ($userId === '') {
			return [];
		}

		try {
			$user = $this->userManager->get($userId);
		} catch (Throwable $e) {
			return [];
		}

		if ($user === null) {
			return [];
		}

		$email = (string)$user->getEMailAddress();
		if ($email === '') {
			return [];
		}

		return [$email];
	}                    

	   
                                                      
   
                                                                        
                                                                      
                                                                         
                                   
   
                                                              
                                            
   
                
    
	private function dropExistingEvents(object $leaf, string $bookingUuid): void {
		if (method_exists($leaf, 'unlinkEventsForObject') === false) {
			return;
		}

		try {
			                                                                         
			$leaf->unlinkEventsForObject($bookingUuid);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: calendar leaf unlinkEventsForObject failed',
				['booking' => $bookingUuid]
			);
		}
	}                          

	   
                                                        
   
                                                        
   
                              
    
	private function resourcesFromBooking(array $booking): array {
		$assignments = ($booking['resourceAssignments'] ?? []);
		if (is_array($assignments) === false) {
			return [];
		}

		$ids = [];
		foreach ($assignments as $assignment) {
			if (is_array($assignment) === false) {
				continue;
			}

			$resourceId = (string)($assignment['resourceId'] ?? '');
			if ($resourceId === '' || in_array($resourceId, $ids, true) === true) {
				continue;
			}

			$ids[] = $resourceId;
		}

		return $ids;
	}                            

	   
                                                                           
   
                                                                         
                                                                        
                                                                        
   
                                                         
                                              
   
                                                                 
    
	private function eventsToBlocks(array $events, string $date): array {
		$dayStart = strtotime($date . 'T00:00:00');
		$dayEnd = strtotime($date . 'T23:59:59');
		if ($dayStart === false || $dayEnd === false) {
			return [];
		}

		$blocks = [];
		foreach ($events as $event) {
			$block = $this->eventToBlock(event: $event, dayStart: $dayStart, dayEnd: $dayEnd);
			if ($block !== null) {
				$blocks[] = $block;
			}
		}

		return $blocks;
	}                      

	   
                                                                     
   
                                                                          
                                                                        
                                 
   
                                                                            
                                                  
                                              
   
                                                          
    
	private function eventToBlock(mixed $event, int $dayStart, int $dayEnd): ?array {
		$data = $this->toArray(object: $event);
		if ($data === null) {
			return null;
		}

		$startIso = (string)($data['dtstart'] ?? ($data['startAt'] ?? ''));
		$endIso = (string)($data['dtend'] ?? ($data['endAt'] ?? ''));
		if ($startIso === '' || $endIso === '') {
			return null;
		}

		$startTs = strtotime($startIso);
		$endTs = strtotime($endIso);
		if ($startTs === false || $endTs === false || $endTs <= $startTs) {
			return null;
		}

		if ($endTs <= $dayStart || $startTs > $dayEnd) {
			return null;
		}

		return [
			'startTime' => $this->hhmmFromTs(timestamp: max($startTs, $dayStart)),
			'endTime' => $this->hhmmFromTs(timestamp: min($endTs, ($dayEnd + 1))),
		];
	}                    

	   
                                                              
   
                                         
   
                  
    
	private function hhmmFromTs(int $timestamp): string {
		$dateTime = (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone(date_default_timezone_get()));
		return $dateTime->format('H:i');
	}                  

	   
                                                                         
   
                       
    
	private function resolveLeaf(): ?object {
		if ($this->leafOverride !== null) {
			return $this->leafOverride;
		}

		try {
			$leaf = $this->container->get('OCA\\OpenRegister\\Service\\CalendarEventService');
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: calendar leaf unavailable, skipping calendar sync');
			return null;
		}

		if (is_object($leaf) === false) {
			return null;
		}

		return $leaf;
	}                   

	   
                                                        
   
                                          
   
                                     
    
	private function loadBooking(string $bookingId): ?array {
		$register = $this->registerSlug();
		$schema = $this->schemaSlug(key: self::BOOKING_SCHEMA_KEY);
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$found = $this->getObjectService()->find(
				id: $bookingId,
				register: $register,
				schema: $schema
			);
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: booking load failed in calendar push', ['booking' => $bookingId]);
			return null;
		}

		return $this->toArray(object: $found);
	}                   

	   
                                                         
   
                                                 
   
                                     
    
	private function loadResource(string $resourceId): ?array {
		$register = $this->registerSlug();
		$schema = $this->schemaSlug(key: self::RESOURCE_SCHEMA_KEY);
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$found = $this->getObjectService()->find(
				id: $resourceId,
				register: $register,
				schema: $schema
			);
		} catch (Throwable $e) {
			return null;
		}

		return $this->toArray(object: $found);
	}                    

	   
                                          
   
                                               
   
                                     
    
	private function loadServiceQuiet(string $serviceId): ?array {
		if ($serviceId === '') {
			return null;
		}

		$register = $this->registerSlug();
		$schema = $this->schemaSlug(key: self::SERVICE_SCHEMA_KEY);
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$found = $this->getObjectService()->find(
				id: $serviceId,
				register: $register,
				schema: $schema
			);
		} catch (Throwable $e) {
			return null;
		}

		return $this->toArray(object: $found);
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

			if (method_exists($object, 'getObject') === true) {
				$payload = $object->getObject();
				if (is_array($payload) === true) {
					return $payload;
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

	   
                                                       
   
                  
    
	private function registerSlug(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'register', '');
	}                    

	   
                                            
   
                                                             
   
                  
    
	private function schemaSlug(string $key): string {
		return $this->appConfig->getValueString(Application::APP_ID, $key, '');
	}                  

	   
                                                              
   
                                                                    
                                                                         
                                                                       
                                          
   
               
    
	private function registerNumeric(): int {
		$value = $this->registerSlug();
		if (ctype_digit($value) === true) {
			return (int)$value;
		}

		return 0;
	}                       

	   
                                                    
   
                                      
   
               
    
	private function schemaNumeric(string $key): int {
		$value = $this->schemaSlug(key: $key);
		if (ctype_digit($value) === true) {
			return (int)$value;
		}

		return 0;
	}                     

	   
                                                                
   
                                              
   
                                                              
    
	private function getObjectService(): object {
		                                                                         
		                                                                   
		                                                                        
		                                                                    
		if (class_exists('\OCA\OpenRegister\Service\ObjectService') === false) {
			throw new RuntimeException('The OpenRegister app is not installed');
		}

		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			throw new RuntimeException('OpenRegister ObjectService is unavailable.', 0, $e);
		}
	}                        
}           
