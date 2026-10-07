<?php

   
                           
  
                                                                           
                                                                          
                                                                             
                                                                         
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                            
  
                                                   
                                                   
                                                   
                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\BackgroundJob\WalkInQueueRebalanceJob;
use OCA\Pipelinq\Service\Lifecycle\SchemaLifecycleGraph;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                              
  
                                                                            
                                                                          
                                                                    
                                                                              
                  
  
                        
                                 
                                                                     
                                                                                                         
                                                                            
                                                  
  
                                                  
                                                
                                                                                                                                    
                                                                                                                                    
                                                                                                                                    
  
                                                   
   
class BookingService {
	   
                                                  
   
               
    
	public const BOOKING_SCHEMA_KEY = 'booking_schema';

	   
                                                                                   
   
               
    
	private const BOOKING_SCHEMA_SLUG = 'appointmentBooking';

	   
                                                  
   
               
    
	public const SERVICE_SCHEMA_KEY = 'service_schema';

	   
                                                   
   
                                                                           
                                                                            
                                                                     
   
               
    
	public const CUSTOMER_SCHEMA_KEY = 'contact_schema';

	   
                                                                               
   
                           
    
	public const STATUSES = [
		'pending-deposit',
		'confirmed',
		'completed',
		'no-show',
		'cancelled-by-customer',
		'cancelled-by-business',
		'rescheduled',
	];

	   
                                                          
   
                           
    
	public const SOURCES = ['portal', 'admin', 'phone', 'walk-in', 'import'];

	   
                                                                               
   
               
    
	public const ACTOR_SYSTEM = 'system';

	   
                                      
   
                                                                                    
                                                                             
                                                                            
   
                    
    
	private ?object $paymentProvider = null;

	   
                                    
   
                                                                       
   
                    
    
	private ?object $emailProvider = null;

	   
                                       
   
                                                                              
                                                                         
                                                                           
                                                          
   
                    
    
	private ?object $calendarProvider = null;

	   
                
   
                                                       
                                                                        
                                                                                           
                                                                                             
                                              
                                                                                             
                                                                                         
    
	public function __construct(
		private IAppConfig $appConfig,
		private IUserSession $userSession,
		private AvailabilityService $availabilityService,
		private EligibilityService $eligibilityService,
		private LoggerInterface $logger,
		private IJobList $jobList,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                               
   
                                                                                             
   
                
   
                                                    
    
	public function setPaymentProvider(?object $provider): void {
		$this->paymentProvider = $provider;
	}                          

	   
                                              
   
                                                                              
   
                
   
                                                    
    
	public function setEmailProvider(?object $provider): void {
		$this->emailProvider = $provider;
	}                        

	   
                                                
   
                                                                              
   
                
   
                                                    
    
	public function setCalendarProvider(?object $provider): void {
		$this->calendarProvider = $provider;
	}                           

	   
                         
   
                                                                            
                                                                           
                                                                              
                                                                      
                                                           
   
                                                                                                                          
                                                      
   
                                        
   
                                                         
                                                            
   
                                                                                                           
                                                                                                           
   
                                                    
    
	public function createBooking(array $data, string $source): string {
		$customerId = trim((string)($data['customerId'] ?? ''));
		$serviceId = trim((string)($data['serviceId'] ?? ''));
		$startAt = trim((string)($data['startAt'] ?? ''));
		$endAt = trim((string)($data['endAt'] ?? ''));

		if ($customerId === '' || $serviceId === '' || $startAt === '' || $endAt === '') {
			throw new InvalidArgumentException('customerId, serviceId, startAt and endAt are required');
		}

		if ($this->isValidIso(value: $startAt) === false || $this->isValidIso(value: $endAt) === false) {
			throw new InvalidArgumentException('startAt and endAt must be ISO-8601 timestamps');
		}

		if (strtotime($startAt) >= strtotime($endAt)) {
			throw new InvalidArgumentException('startAt must be earlier than endAt');
		}

		$normalisedSource = $this->normaliseSource(source: $source);

		$service = $this->loadService(serviceId: $serviceId);
		$requireDeposit = (bool)($service['requiresDeposit'] ?? false);
		$depositAmount = (float)($service['depositAmount'] ?? 0.0);
		$initialStatus = 'confirmed';
		if ($requireDeposit === true && $depositAmount > 0.0) {
			$initialStatus = 'pending-deposit';
		}

		$nowIso = $this->nowIso();
		$changer = $this->actorUid();

		$payload = [
			'customerId' => $customerId,
			'serviceId' => $serviceId,
			'resourceAssignments' => $this->normaliseAssignments(value: ($data['resourceAssignments'] ?? [])),
			'startAt' => $startAt,
			'endAt' => $endAt,
			'status' => $initialStatus,
			'statusHistory' => [
				[
					'status' => $initialStatus,
					'changedAt' => $nowIso,
					'changedBy' => $changer,
					'reason' => sprintf('Booking created via %s', $normalisedSource),
				],
			],
			'notes' => (string)($data['notes'] ?? ''),
			'internalNotes' => (string)($data['internalNotes'] ?? ''),
			'source' => $normalisedSource,
			'depositAmount' => max(0.0, $depositAmount),
		];

		$saved = $this->saveBooking(payload: $payload, uuid: null);
		$uuid = $this->idOf(object: $saved);
		if ($uuid === '') {
			throw new RuntimeException('Booking save returned no id');
		}

		$this->invalidateAvailability(payload: $payload);

		if ($initialStatus === 'confirmed') {
			$this->dispatchConfirmationEmail(bookingId: $uuid);
			$this->pushCalendarEvent(bookingId: $uuid);
		}

		return $uuid;
	}                     

	   
                                                    
   
                                                                           
                                                                        
                                                                           
                                                                               
   
                                               
                                              
   
                                                                                                           
   
                                                    
    
	public function getAvailableSlots(string $serviceId, string $date): array {
		if ($serviceId === '' || $this->isValidDate(value: $date) === false) {
			return [];
		}

		try {
			$service = $this->loadService(serviceId: $serviceId);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: service lookup failed in getAvailableSlots',
				['service' => $serviceId, 'date' => $date]
			);
			return [];
		}

		                                                                     
		                                                            
		                                                                    
		                                                                   
		                                                                     
		                                                                    
		                      
		if (($service['bookableOnline'] ?? false) !== true) {
			return [];
		}

		$duration = (int)($service['durationMinutes'] ?? 0);
		if ($duration <= 0) {
			return [];
		}

		$candidates = $this->loadCandidateResources(service: $service);
		$merged = [];
		foreach ($candidates as $resourceId) {
			$slots = $this->availabilityService->computeAvailability(
				resourceId: $resourceId,
				date: $date,
				serviceDurationMinutes: $duration
			);
			foreach ($slots as $slot) {
				$merged[] = [
					'startTime' => $slot['startTime'],
					'endTime' => $slot['endTime'],
					'durationMinutes' => $slot['durationMinutes'],
					'resourceId' => $resourceId,
				];
			}
		}

		return $merged;
	}                         

	   
                                      
   
                                                                      
                                                                     
   
                                          
                                                                         
   
                
   
                                                                           
   
                                                    
    
	public function confirmBooking(string $bookingId, string $reason): void {
		$booking = $this->loadBooking(bookingId: $bookingId);
		$current = (string)($booking['status'] ?? '');
		if ($current === 'confirmed') {
			return;
		}

		$this->assertTransitionAllowed(from: $current, to: 'confirmed');

		$nowIso = $this->nowIso();
		$booking['status'] = 'confirmed';
		$booking['statusHistory'] = $this->appendHistory(
			history: ($booking['statusHistory'] ?? []),
			status: 'confirmed',
			changedAt: $nowIso,
			reason: $reason
		);
		$booking['confirmationSentAt'] = $nowIso;

		$this->saveBooking(payload: $this->stripSelf(payload: $booking), uuid: $bookingId);
		$this->invalidateAvailability(payload: $booking);
		$this->dispatchConfirmationEmail(bookingId: $bookingId);
		$this->pushCalendarEvent(bookingId: $bookingId);
	}                      

	   
                                             
   
                                                                      
                                                                        
                                                                            
                                                                            
                                                                               
   
                                                   
                                                           
   
                                        
   
                                                         
   
                                                    
    
	public function rescheduleBooking(string $bookingId, string $newStartAt): string {
		if ($this->isValidIso(value: $newStartAt) === false) {
			throw new InvalidArgumentException('newStartAt must be an ISO-8601 timestamp');
		}

		$original = $this->loadBooking(bookingId: $bookingId);
		$current = (string)($original['status'] ?? '');
		$this->assertTransitionAllowed(from: $current, to: 'rescheduled');

		$originalStart = (string)($original['startAt'] ?? '');
		$originalEnd = (string)($original['endAt'] ?? '');
		if ($originalStart === '' || $originalEnd === '') {
			throw new InvalidArgumentException('original booking is missing startAt/endAt');
		}

		$deltaSeconds = (strtotime($newStartAt) - strtotime($originalStart));
		if ($deltaSeconds === 0) {
			throw new InvalidArgumentException('newStartAt must differ from current startAt');
		}

		$newEnd = $this->shiftIso(iso: $originalEnd, seconds: $deltaSeconds);
		$nowIso = $this->nowIso();

		$newAssignments = [];
		foreach (($original['resourceAssignments'] ?? []) as $assignment) {
			if (is_array($assignment) === false) {
				continue;
			}

			$newAssignments[] = [
				'stepIndex' => (int)($assignment['stepIndex'] ?? 0),
				'resourceId' => (string)($assignment['resourceId'] ?? ''),
				'startAt' => $this->shiftIso(iso: (string)($assignment['startAt'] ?? ''), seconds: $deltaSeconds),
				'endAt' => $this->shiftIso(iso: (string)($assignment['endAt'] ?? ''), seconds: $deltaSeconds),
			];
		}

		$newPayload = [
			'customerId' => (string)($original['customerId'] ?? ''),
			'serviceId' => (string)($original['serviceId'] ?? ''),
			'resourceAssignments' => $newAssignments,
			'startAt' => $newStartAt,
			'endAt' => $newEnd,
			'status' => 'confirmed',
			'statusHistory' => [
				[
					'status' => 'confirmed',
					'changedAt' => $nowIso,
					'changedBy' => $this->actorUid(),
					'reason' => sprintf('Rescheduled from %s', $bookingId),
				],
			],
			'notes' => (string)($original['notes'] ?? ''),
			'internalNotes' => (string)($original['internalNotes'] ?? ''),
			'source' => (string)($original['source'] ?? 'portal'),
			'depositAmount' => (float)($original['depositAmount'] ?? 0.0),
			'previousBookingId' => $bookingId,
		];

		$saved = $this->saveBooking(payload: $newPayload, uuid: null);
		$newUuid = $this->idOf(object: $saved);
		if ($newUuid === '') {
			throw new RuntimeException('Reschedule save returned no id');
		}

		                                                              
		$original['status'] = 'rescheduled';
		$original['statusHistory'] = $this->appendHistory(
			history: ($original['statusHistory'] ?? []),
			status: 'rescheduled',
			changedAt: $nowIso,
			reason: sprintf('Rescheduled to booking %s', $newUuid)
		);
		$this->saveBooking(payload: $this->stripSelf(payload: $original), uuid: $bookingId);

		                                                
		$this->invalidateAvailability(payload: $original);
		$this->invalidateAvailability(payload: $newPayload);

		                                                                      
		                                                                     
		                                                                     
		$this->pushCalendarEvent(bookingId: $newUuid);

		return $newUuid;
	}                         

	   
                                             
   
                                                                     
                                                                            
                                                                              
                                                                                
   
                                          
                                                                               
                                                                                                      
   
                
   
                                                                           
   
                                                    
    
	public function cancelBooking(string $bookingId, string $reason, string $cancelledBy): void {
		$booking = $this->loadBooking(bookingId: $bookingId);
		$current = (string)($booking['status'] ?? '');

		$isCustomerCancel = ($cancelledBy === (string)($booking['customerId'] ?? ''));
		$targetStatus = 'cancelled-by-business';
		if ($isCustomerCancel === true) {
			$targetStatus = 'cancelled-by-customer';
		}

		$this->assertTransitionAllowed(from: $current, to: $targetStatus);

		$service = $this->loadServiceQuiet(serviceId: (string)($booking['serviceId'] ?? ''));
		$shouldCharge = $this->shouldChargeCancellation(
			booking: $booking,
			service: $service,
			isCustomerCancel: $isCustomerCancel
		);
		$chargeAmount = $this->cancellationChargeAmount(booking: $booking, service: $service);

		$nowIso = $this->nowIso();
		$booking['status'] = $targetStatus;
		$booking['cancellationReason'] = $this->truncate(value: $reason, max: 1000);
		$booking['cancelledAt'] = $nowIso;
		$cancelActor = $cancelledBy;
		if ($cancelActor === '') {
			$cancelActor = self::ACTOR_SYSTEM;
		}

		$booking['cancelledBy'] = $cancelActor;
		$booking['statusHistory'] = $this->appendHistory(
			history: ($booking['statusHistory'] ?? []),
			status: $targetStatus,
			changedAt: $nowIso,
			reason: $this->truncate(value: $reason, max: 1000)
		);

		$this->saveBooking(payload: $this->stripSelf(payload: $booking), uuid: $bookingId);
		$this->invalidateAvailability(payload: $booking);

		if ($shouldCharge === true && $chargeAmount > 0.0) {
			$this->chargeCancellationFee(bookingId: $bookingId, amount: $chargeAmount);
		}
	}                     

	   
                                
   
                                                                            
                                                                            
                                                                            
                             
   
                                          
                                                                          
   
                
   
                                                                           
   
                                                    
    
	public function markNoShow(string $bookingId, string $staffUserId): void {
		$booking = $this->loadBooking(bookingId: $bookingId);
		$current = (string)($booking['status'] ?? '');

		$this->assertTransitionAllowed(from: $current, to: 'no-show');

		$nowIso = $this->nowIso();
		$actor = $staffUserId;
		if ($actor === '') {
			$actor = $this->actorUid();
		}

		$booking['status'] = 'no-show';
		$booking['statusHistory'] = $this->appendHistoryWithActor(
			history: ($booking['statusHistory'] ?? []),
			status: 'no-show',
			changedAt: $nowIso,
			changedBy: $actor,
			reason: 'No-show marked by staff'
		);

		$this->saveBooking(payload: $this->stripSelf(payload: $booking), uuid: $bookingId);
		$this->invalidateAvailability(payload: $booking);

		$this->incrementCustomerNoShowCount(customerId: (string)($booking['customerId'] ?? ''));

		$service = $this->loadServiceQuiet(serviceId: (string)($booking['serviceId'] ?? ''));
		$fee = (float)($service['noShowFee'] ?? 0.0);
		if ($fee > 0.0) {
			$this->chargeNoShowFee(bookingId: $bookingId, amount: $fee);
		}
	}                  

	   
                                          
   
                                          
   
                
   
                                                                           
   
                                                    
    
	public function completeBooking(string $bookingId): void {
		$booking = $this->loadBooking(bookingId: $bookingId);
		$current = (string)($booking['status'] ?? '');

		$this->assertTransitionAllowed(from: $current, to: 'completed');

		$nowIso = $this->nowIso();
		$booking['status'] = 'completed';
		$booking['statusHistory'] = $this->appendHistory(
			history: ($booking['statusHistory'] ?? []),
			status: 'completed',
			changedAt: $nowIso,
			reason: 'Service completed'
		);

		$this->saveBooking(payload: $this->stripSelf(payload: $booking), uuid: $bookingId);
		$this->invalidateAvailability(payload: $booking);
		$this->rebalanceWalkInQueue();
	}                       

	   
                                                     
   
                                                                         
                                                                  
                                                 
                                                                      
                                                                       
                                                            
   
                                                                            
                                                                             
                                                                       
                                                                          
                                                                        
                                                                           
                                                                            
   
                                                                            
                                                                          
                                                                             
                                                                            
                                       
   
                
   
                                                    
    
	private function rebalanceWalkInQueue(): void {
		try {
			$this->jobList->add(WalkInQueueRebalanceJob::class);
		} catch (\Throwable $e) {
			                                                                
			                                                             
			                                  
			$this->logger->warning(
				'Pipelinq: could not schedule the walk-in queue rebalance',
				['exception' => $e->getMessage()]
			);
		}
	}                            

	   
                                                                      
   
                                       
                                    
   
                
   
                                                                     
   
                                                    
    
	public function assertTransitionAllowed(string $from, string $to): void {
		$allowed = self::allowedTransitions();
		if (isset($allowed[$from]) === false) {
			throw new InvalidArgumentException(
				sprintf('Unknown source status: %s', $from)
			);
		}

		if (in_array($to, $allowed[$from], true) === false) {
			throw new InvalidArgumentException(
				sprintf('Invalid status transition: %s -> %s', $from, $to)
			);
		}
	}                               

	   
                                    
   
                                                                            
                                                              
                                                                                 
                                                                             
                                                                                
                                                                         
                                                                              
                                                                                  
   
                                             
   
                                                    
                                                         
    
	public static function allowedTransitions(): array {
		$graph = (new SchemaLifecycleGraph())->fullAdjacencyFor(schemaSlug: self::BOOKING_SCHEMA_SLUG);
		if ($graph === []) {
			return self::FALLBACK_TRANSITIONS;
		}

		return $graph;
	}                          

	   
                                                                              
                                                                        
                                                                                  
   
                                          
    
	private const FALLBACK_TRANSITIONS = [
		'pending-deposit' => [
			'confirmed',
			'cancelled-by-customer',
			'cancelled-by-business',
			'rescheduled',
		],
		'confirmed' => [
			'completed',
			'no-show',
			'cancelled-by-customer',
			'cancelled-by-business',
			'rescheduled',
		],
		'completed' => [],
		'no-show' => [],
		'cancelled-by-customer' => [],
		'cancelled-by-business' => [],
		'rescheduled' => [],
	];

	   
                                                    
   
                                                                         
                                                                               
                                                    
   
                                                        
                                                                                    
                                                                               
   
                
    
	private function shouldChargeCancellation(array $booking, ?array $service, bool $isCustomerCancel): bool {
		if ($isCustomerCancel === false || $service === null) {
			return false;
		}

		$policy = (string)($service['cancellationPolicy'] ?? 'free');
		if ($policy === 'free') {
			return false;
		}

		$windowHours = (int)($service['cancellationHoursBefore'] ?? 24);
		$startAt = (string)($booking['startAt'] ?? '');
		if ($startAt === '') {
			return false;
		}

		$secondsUntilStart = (strtotime($startAt) - strtotime($this->nowIso()));
		$windowSeconds = ($windowHours * 3600);
		return ($secondsUntilStart < $windowSeconds);
	}                                

	   
                                           
   
                                                                    
                                                                
   
                                                        
                                                                       
   
                 
    
	private function cancellationChargeAmount(array $booking, ?array $service): float {
		if ($service === null) {
			return 0.0;
		}

		$policy = (string)($service['cancellationPolicy'] ?? 'free');
		if ($policy === 'charge-deposit') {
			$deposit = (float)($booking['depositAmount'] ?? ($service['depositAmount'] ?? 0.0));
			return max(0.0, $deposit);
		}

		if ($policy === 'always-charge') {
			return max(0.0, (float)($service['price'] ?? 0.0));
		}

		return 0.0;
	}                                

	   
                                                                  
   
                                                       
                                     
                                           
                                                
   
                                             
    
	private function appendHistory(array $history, string $status, string $changedAt, string $reason): array {
		return $this->appendHistoryWithActor(
			history: $history,
			status: $status,
			changedAt: $changedAt,
			changedBy: $this->actorUid(),
			reason: $reason
		);
	}                     

	   
                                                              
   
                                                       
                                     
                                           
                                                                      
                                                
   
                                             
    
	private function appendHistoryWithActor(
		array $history,
		string $status,
		string $changedAt,
		string $changedBy,
		string $reason,
	): array {
		$entries = [];
		foreach ($history as $entry) {
			if (is_array($entry) === true) {
				$entries[] = [
					'status' => (string)($entry['status'] ?? ''),
					'changedAt' => (string)($entry['changedAt'] ?? ''),
					'changedBy' => (string)($entry['changedBy'] ?? ''),
					'reason' => (string)($entry['reason'] ?? ''),
				];
			}
		}

		$entries[] = [
			'status' => $status,
			'changedAt' => $changedAt,
			'changedBy' => $changedBy,
			'reason' => $reason,
		];
		return $entries;
	}                              

	   
                                                                         
   
                                                                     
   
                  
    
	private function actorUid(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return self::ACTOR_SYSTEM;
		}

		$uid = $user->getUID();
		if ($uid === '') {
			return self::ACTOR_SYSTEM;
		}

		return $uid;
	}                

	   
                                                  
   
                                          
   
                                
   
                                              
                                          
    
	private function loadBooking(string $bookingId): array {
		if ($bookingId === '') {
			throw new InvalidArgumentException('bookingId must be a non-empty string');
		}

		$register = $this->registerId();
		$schema = $this->schemaId(key: self::BOOKING_SCHEMA_KEY);
		if ($register === '' || $schema === '') {
			throw new RuntimeException('Booking register/schema not configured');
		}

		try {
			$found = $this->getObjectService()->find(
				id: $bookingId,
				register: $register,
				schema: $schema
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: booking load failed', ['booking' => $bookingId]);
			throw new RuntimeException('Booking lookup failed', 0, $e);
		}

		$data = $this->toArray(object: $found);
		if ($data === null) {
			throw new RuntimeException(sprintf('Booking %s not found', $bookingId));
		}

		return $data;
	}                   

	   
                                    
   
                                               
   
                                
   
                                                
                                                                 
    
	private function loadService(string $serviceId): array {
		$service = $this->loadServiceQuiet(serviceId: $serviceId);
		if ($service === null) {
			throw new RuntimeException(sprintf('Service %s not found', $serviceId));
		}

		return $service;
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
			$this->logger->warning('Pipelinq: service load failed', ['service' => $serviceId]);
			return null;
		}

		return $this->toArray(object: $found);
	}                        

	   
                                                 
   
                                                                           
                                                                           
                                                       
                                                                             
                     
   
                                                        
   
                              
   
                                                                                                          
                                                                                                          
    
	private function loadCandidateResources(array $service): array {
		$serviceId = $this->idOf(object: $service);
		if ($serviceId === '') {
			return [];
		}

		try {
			$eligible = $this->eligibilityService->getEligibleResources(serviceId: $serviceId);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: eligibility lookup failed',
				['service' => $serviceId]
			);
			return [];
		}

		$requiredTypes = ($service['requiredResourceTypes'] ?? []);
		if (is_array($requiredTypes) === false) {
			$requiredTypes = [];
		}

		$candidates = [];
		foreach ($eligible as $resource) {
			if (($resource['bookable'] ?? true) === false) {
				continue;
			}

			$status = (string)($resource['status'] ?? 'active');
			if ($status !== 'active') {
				continue;
			}

			if ($requiredTypes !== []) {
				$type = (string)($resource['type'] ?? '');
				if (in_array($type, $requiredTypes, true) === false) {
					continue;
				}
			}

			$id = $this->idOf(object: $resource);
			if ($id !== '') {
				$candidates[] = $id;
			}
		}             

		return $candidates;
	}                              

	   
                                                    
   
                                                                       
                                                                         
                                                                   
   
                                            
   
                
    
	private function incrementCustomerNoShowCount(string $customerId): void {
		if ($customerId === '') {
			return;
		}

		$register = $this->registerId();
		$schema = $this->schemaId(key: self::CUSTOMER_SCHEMA_KEY);
		if ($register === '' || $schema === '') {
			return;
		}

		try {
			$customer = $this->getObjectService()->find(
				id: $customerId,
				register: $register,
				schema: $schema
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: customer load for no-show failed', ['customer' => $customerId]);
			return;
		}

		$data = $this->toArray(object: $customer);
		if ($data === null) {
			return;
		}

		$count = (int)($data['noShowCount'] ?? 0);
		$data['noShowCount'] = ($count + 1);

		try {
			$this->getObjectService()->saveObject(
				object: $this->stripSelf(payload: $data),
				extend: [],
				register: $register,
				schema: $schema,
				uuid: $customerId
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: customer no-show count save failed',
				['customer' => $customerId]
			);
		}
	}                                    

	   
                                                                   
   
                                                         
                                              
   
                                       
    
	private function saveBooking(array $payload, ?string $uuid): array|object {
		$register = $this->registerId();
		$schema = $this->schemaId(key: self::BOOKING_SCHEMA_KEY);
		if ($register === '' || $schema === '') {
			throw new RuntimeException('Booking register/schema not configured');
		}

		return $this->getObjectService()->saveObject(
			object: $payload,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: $uuid
		);
	}                   

	   
                                                                             
   
                                                                      
                                                                          
                                         
   
                                                         
   
                
    
	private function invalidateAvailability(array $payload): void {
		$assignments = ($payload['resourceAssignments'] ?? []);
		if (is_array($assignments) === false) {
			return;
		}

		$seen = [];
		foreach ($assignments as $assignment) {
			if (is_array($assignment) === false) {
				continue;
			}

			$resourceId = (string)($assignment['resourceId'] ?? '');
			$startAt = (string)($assignment['startAt'] ?? '');
			if ($resourceId === '' || $startAt === '') {
				continue;
			}

			$date = substr($startAt, 0, 10);
			$key = ($resourceId . '|' . $date);
			if (isset($seen[$key]) === true) {
				continue;
			}

			$seen[$key] = true;
			try {
				$this->availabilityService->invalidateCache(resourceId: $resourceId, date: $date);
			} catch (\Throwable $e) {
				$this->logger->warning(
					'Pipelinq: availability invalidation failed',
					['resource' => $resourceId, 'date' => $date]
				);
			}
		}             
	}                              

	   
                                                                    
   
                                          
   
                
    
	private function dispatchConfirmationEmail(string $bookingId): void {
		if ($this->emailProvider === null) {
			return;
		}

		if (method_exists($this->emailProvider, 'sendConfirmation') === false) {
			return;
		}

		try {
			                                                  
			$this->emailProvider->sendConfirmation($bookingId);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: confirmation email seam failed', ['booking' => $bookingId]);
		}
	}                                 

	   
                                                                              
   
                                                                           
                                                                           
                                                   
   
                                          
   
                
   
                                                    
    
	private function pushCalendarEvent(string $bookingId): void {
		if ($this->calendarProvider === null) {
			return;
		}

		if (method_exists($this->calendarProvider, 'pushBookingEvent') === false) {
			return;
		}

		try {
			                                                                           
			$this->calendarProvider->pushBookingEvent($bookingId);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: calendar push seam failed',
				['booking' => $bookingId]
			);
		}
	}                         

	   
                                                               
   
                                          
                                    
   
                
    
	private function chargeNoShowFee(string $bookingId, float $amount): void {
		if ($this->paymentProvider === null) {
			return;
		}

		if (method_exists($this->paymentProvider, 'chargeNoShowFee') === false) {
			return;
		}

		try {
			                                                  
			$this->paymentProvider->chargeNoShowFee($bookingId, $amount);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: no-show fee charge failed',
				['booking' => $bookingId, 'amount' => $amount]
			);
		}
	}                       

	   
                                                                  
   
                                          
                                    
   
                
    
	private function chargeCancellationFee(string $bookingId, float $amount): void {
		if ($this->paymentProvider === null) {
			return;
		}

		if (method_exists($this->paymentProvider, 'chargeCancellationFee') === false) {
			return;
		}

		try {
			                                                  
			$this->paymentProvider->chargeCancellationFee($bookingId, $amount);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: cancellation fee charge failed',
				['booking' => $bookingId, 'amount' => $amount]
			);
		}
	}                             

	   
                                                                    
   
                                                                            
                                                                          
                                                
   
                                                         
   
                                
    
	private function stripSelf(array $payload): array {
		if (array_key_exists('@self', $payload) === true) {
			unset($payload['@self']);
		}

		return $payload;
	}                 

	   
                                                   
   
                                  
   
                                            
    
	private function normaliseAssignments(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$out = [];
		foreach ($value as $assignment) {
			if (is_array($assignment) === false) {
				continue;
			}

			$out[] = [
				'stepIndex' => (int)($assignment['stepIndex'] ?? 0),
				'resourceId' => (string)($assignment['resourceId'] ?? ''),
				'startAt' => (string)($assignment['startAt'] ?? ''),
				'endAt' => (string)($assignment['endAt'] ?? ''),
			];
		}

		return $out;
	}                            

	   
                                                                
   
                                           
   
                  
    
	private function normaliseSource(string $source): string {
		$lower = strtolower(trim($source));
		if (in_array($lower, self::SOURCES, true) === true) {
			return $lower;
		}

		return 'portal';
	}                       

	   
                                          
   
                                   
                                   
   
                  
    
	private function truncate(string $value, int $max): string {
		if (strlen($value) <= $max) {
			return $value;
		}

		return substr($value, 0, $max);
	}                

	   
                                                         
   
                                          
   
                
    
	private function isValidIso(string $value): bool {
		if ($value === '') {
			return false;
		}

		$timestamp = strtotime($value);
		return ($timestamp !== false);
	}                  

	   
                                             
   
                                   
   
                
    
	private function isValidDate(string $value): bool {
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
			return false;
		}

		return ((bool)strtotime($value));
	}                   

	   
                                        
   
                                        
                                                                
   
                  
    
	private function shiftIso(string $iso, int $seconds): string {
		if ($iso === '') {
			return '';
		}

		$timestamp = strtotime($iso);
		if ($timestamp === false) {
			return $iso;
		}

		$shifted = ($timestamp + $seconds);
		$dateTime = (new DateTimeImmutable('@' . $shifted))->setTimezone(new DateTimeZone('UTC'));
		return $dateTime->format('Y-m-d\TH:i:sP');
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
