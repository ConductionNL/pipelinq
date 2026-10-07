<?php

   
                             
  
                                                                           
                                                                            
                                                                                
                                                                               
                                                                      
  
                                                                           
                                                                              
                                                                                 
                                                                      
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                                    
                                               
  
                         
  
                                                 
  
                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\WebhookService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;

   
                                                                        
  
                                                                             
                                                                             
                                                                              
                                                                              
                                                                            
                  
  
                                                                              
                                                                            
                                                                                
                                                         
                                                                             
                                                                              
                                                                             
                                                                               
                                                                               
                                                                                 
                                                                                
                                                       
  
                                                          
   
class CashShiftService {
	   
                                                             
   
               
    
	public const EVENT_CASH_DIFF_CONFIRMED = 'pipelinq.CashDiff.confirmed';

	   
                                                             
   
               
    
	private const EVENT_SOURCE = 'pipelinq/cashShift';

	   
                                                                   
   
              
    
	private const DEFAULT_TOLERANCE_PCT = 2.0;

	   
                
   
                                                
                                                                
                                              
                                                                  
                                                                                         
                                                                            
    
	public function __construct(
		private IAppConfig $appConfig,
		private PosAccessPolicy $policy,
		private LoggerInterface $logger,
		private readonly WebhookService $webhookService,
		private readonly ObjectServiceInterface $objectService,
		private readonly AggregationRunner $aggregationRunner,
	) {
	}                   

	   
                                         
   
                                           
   
                                             
    
	private function money(float $value): float {
		return round($value, 2);
	}             

	   
                                                                       
   
                                                                              
                                                                          
                                                                              
   
                                                           
                                                         
                                                                     
                                                               
                                                
   
                                                   
   
                                                                    
                                                            
   
                                                           
    
	public function openShift(
		string $drawer,
		float $floatAmount,
		string $userId,
		string $reference = '',
		string $notes = '',
	): array {
		$this->requirePosUser(userId: $userId);

		if ($floatAmount < 0) {
			throw new OCSBadRequestException('Openingsbedrag mag niet negatief zijn.');
		}

		$shift = [
			'reference' => trim($reference),
			'drawer' => trim($drawer),
			'operator' => $userId,
			'currency' => 'EUR',
			'floatAmount' => $this->money(value: $floatAmount),
			'floatAt' => $this->now(),
			'status' => 'open',
			'reconciliationStatus' => 'pending',
			'notes' => trim($notes),
		];

		return $this->saveShift(id: '', shift: $shift);
	}                 

	   
                                                
   
                                                                               
                                                                              
   
                                                 
                                         
                                                        
                                                                     
   
                                                  
   
                                                                    
                                                             
                                                                                          
   
                                                           
    
	public function recordDrop(string $shiftId, float $amount, string $reason, string $userId): array {
		$this->requirePosUser(userId: $userId);

		$shift = $this->fetchShift(id: $shiftId);
		if ((string)($shift['status'] ?? '') !== 'open') {
			throw new OCSBadRequestException('Een drop kan alleen worden vastgelegd op een open shift.');
		}

		if ($amount <= 0) {
			throw new OCSBadRequestException('Het dropbedrag moet groter zijn dan nul.');
		}

		$drop = [
			'shift' => $shiftId,
			'amount' => $this->money(value: $amount),
			'reason' => trim($reason),
			'droppedAt' => $this->now(),
			'droppedBy' => $userId,
		];

		return $this->saveObjectFor(schemaKey: 'cashDrop_schema', id: '', object: $drop);
	}                  

	   
                                                                         
   
                                                                           
                                                                         
                                                                              
                                                         
   
                                          
                                                 
                                                                    
                                                
   
                                                                                                                   
   
                                                                    
                                                             
                                                                                      
   
                                                           
    
	public function recordCount(string $shiftId, float $amount, string $userId, string $notes = ''): array {
		$this->requirePosUser(userId: $userId);

		$shift = $this->fetchShift(id: $shiftId);
		if ((string)($shift['status'] ?? '') !== 'open') {
			throw new OCSBadRequestException('Alleen een open shift kan worden afgesloten en geteld.');
		}

		if ($amount < 0) {
			throw new OCSBadRequestException('Het getelde bedrag mag niet negatief zijn.');
		}

		$closedAt = $this->now();
		$count = [
			'shift' => $shiftId,
			'amount' => $this->money(value: $amount),
			'countedAt' => $closedAt,
			'countedBy' => $userId,
			'notes' => trim($notes),
		];
		$count = $this->saveObjectFor(schemaKey: 'cashCount_schema', id: '', object: $count);

		$shift['status'] = 'closed';
		$shift['closedAt'] = $closedAt;
		$shift['reconciliationStatus'] = 'pending';
		$shift = $this->saveShift(id: $shiftId, shift: $shift);

		$diff = $this->calculateDiff(shift: $shift, count: $count);

		return [
			'count' => $count,
			'diff' => $diff,
			'shift' => $shift,
		];
	}                   

	   
                                                             
   
                                                                     
                                                                               
                                                                             
                                                                         
                                                                             
                                                                               
                                                          
   
                                                        
                                                                 
   
                                                        
   
                                                           
    
	public function calculateDiff(array $shift, array $count): array {
		$shiftId = (string)($shift['id'] ?? $shift['uuid'] ?? '');
		$floatAmount = (float)($shift['floatAmount'] ?? 0);
		$salesTotal = $this->sumConfirmedSales(
			from: (string)($shift['floatAt'] ?? ''),
			to: (string)($shift['closedAt'] ?? $count['countedAt'] ?? '')
		);
		$dropsTotal = $this->sumDrops(shiftId: $shiftId);

		$expected = $this->money(value: ($floatAmount + $salesTotal - $dropsTotal));
		$actual = $this->money(value: (float)($count['amount'] ?? 0));
		$diff = $this->money(value: ($actual - $expected));

		$tolerance = self::DEFAULT_TOLERANCE_PCT;
		$diffPercentage = null;
		$withinTolerance = false;
		if ($expected !== 0.0) {
			$diffPercentage = round((($diff / $expected) * 100), 2);
			$withinTolerance = (abs($diffPercentage) <= $tolerance);
		}

		$diffObject = [
			'shift' => $shiftId,
			'count' => (string)($count['id'] ?? $count['uuid'] ?? ''),
			'expectedAmount' => $expected,
			'actualAmount' => $actual,
			'diffAmount' => $diff,
			'diffPercentage' => $diffPercentage,
			'tolerancePercentage' => $tolerance,
			'withinTolerance' => $withinTolerance,
			'status' => 'pending',
		];

		$existing = $this->findPendingDiff(shiftId: $shiftId);
		$existingId = (string)($existing['id'] ?? $existing['uuid'] ?? '');

		return $this->saveObjectFor(schemaKey: 'cashDiff_schema', id: $existingId, object: $diffObject);
	}                     

	   
                                                                          
   
                                                                           
                                                                        
                                                                          
   
                                            
                                                                    
   
                                                   
   
                                                                   
                                                                         
                                                              
   
                                                           
    
	public function approveDiff(string $diffId, string $userId): array {
		$this->requireManager(userId: $userId);

		$diff = $this->fetchDiff(id: $diffId);
		if ((string)($diff['status'] ?? '') !== 'pending') {
			throw new OCSBadRequestException('Alleen een openstaand kasverschil kan worden goedgekeurd.');
		}

		$shift = $this->fetchShift(id: (string)($diff['shift'] ?? ''));

		$approvedAt = $this->now();
		$diff['status'] = 'approved';
		$diff['approvedBy'] = $userId;
		$diff['approvedAt'] = $approvedAt;
		$diff = $this->saveObjectFor(schemaKey: 'cashDiff_schema', id: $diffId, object: $diff);

		$shift['status'] = 'reconciled';
		$shift['reconciliationStatus'] = 'approved';
		$shift['managedBy'] = $userId;
		$shift = $this->saveShift(id: (string)($shift['id'] ?? $shift['uuid'] ?? ''), shift: $shift);

		$eventId = $this->emitDiffConfirmedEvent(shift: $shift, diff: $diff, userId: $userId, approvedAt: $approvedAt);
		if ($eventId !== '') {
			$diff['cloudEventId'] = $eventId;
			$diff = $this->saveObjectFor(schemaKey: 'cashDiff_schema', id: $diffId, object: $diff);
		}

		$this->logger->info('Pipelinq: cash diff approved', ['id' => $diffId, 'userId' => $userId]);

		return $diff;
	}                   

	   
                                                                      
   
                                                                 
                                                                              
                                                                             
                                                      
   
                                            
                                                          
                                                                    
   
                                                   
   
                                                                   
                                                                         
                                                                                     
   
                                                           
    
	public function rejectDiff(string $diffId, string $reason, string $userId): array {
		$this->requireManager(userId: $userId);

		if (trim($reason) === '') {
			throw new OCSBadRequestException('Vul een reden in voor de afwijzing.');
		}

		$diff = $this->fetchDiff(id: $diffId);
		if ((string)($diff['status'] ?? '') !== 'pending') {
			throw new OCSBadRequestException('Alleen een openstaand kasverschil kan worden afgewezen.');
		}

		$shift = $this->fetchShift(id: (string)($diff['shift'] ?? ''));

		$diff['status'] = 'rejected';
		$diff['approvedBy'] = $userId;
		$diff['approvedAt'] = $this->now();
		$diff['rejectionReason'] = trim($reason);
		$diff = $this->saveObjectFor(schemaKey: 'cashDiff_schema', id: $diffId, object: $diff);

		$shift['status'] = 'open';
		$shift['reconciliationStatus'] = 'rejected';
		$shift = $this->saveShift(id: (string)($shift['id'] ?? $shift['uuid'] ?? ''), shift: $shift);

		$this->createRecountTask(shift: $shift, reason: trim($reason), userId: $userId);

		$this->logger->info('Pipelinq: cash diff rejected', ['id' => $diffId, 'userId' => $userId]);

		return $diff;
	}                  

	   
                                                                              
   
                                                                         
                                                                             
                                                                           
                                                                             
                                                                    
   
                                                               
                                                           
   
                                                                            
   
                                                           
   
                                                                           
                                                                      
    
	private function sumConfirmedSales(string $from, string $to): float {
		[$register, $schema] = $this->config(schemaKey: 'posTransaction_schema');

		                                                                         
		                                                                          
		                                                                     
		                                                                       
		                                                                          
		                                                                     
		                                                                        
		                        
		try {
			$query = AggregationQuery::create(
				metric: 'sum',
				field: 'total',
				filter: [
					'status' => ['in' => ['confirmed', 'settled']],
					'confirmedAt' => ['gte' => $from, 'lte' => $to],
				],
			);
			$result = $this->getAggregationRunner()->runAdhocByRef(
				registerRef: $register,
				schemaRef: $schema,
				query: $query
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: failed to read POS transactions for cash diff; sales total assumed 0',
				['exception' => $e->getMessage()]
			);
			return 0.0;
		}         

		                                                              
		$sum = (float)($result['value'] ?? 0.0);

		return $this->money(value: $sum);
	}                         

	   
                                                          
   
                                          
   
                                  
   
                                                           
    
	private function sumDrops(string $shiftId): float {
		$sum = 0.0;
		foreach ($this->fetchByShift(schemaKey: 'cashDrop_schema', shiftId: $shiftId) as $drop) {
			$sum += (float)($drop['amount'] ?? 0);
		}

		return $this->money(value: $sum);
	}                

	   
                                                                        
   
                                          
   
                                                                     
   
                                                           
    
	private function findPendingDiff(string $shiftId): array {
		foreach ($this->fetchByShift(schemaKey: 'cashDiff_schema', shiftId: $shiftId) as $diff) {
			if ((string)($diff['status'] ?? '') === 'pending') {
				return $diff;
			}
		}

		return [];
	}                       

	   
                                                                       
   
                                                                            
                     
   
                                                          
                                               
                                                    
   
                
   
                                                           
    
	private function createRecountTask(array $shift, string $reason, string $userId): void {
		$operator = (string)($shift['operator'] ?? '');
		$reference = (string)($shift['reference'] ?? ($shift['id'] ?? ''));

		$task = [
			'type' => 'followUpTask',
			'subject' => 'Hercount verplicht voor shift ' . $reference,
			'description' => 'Hercount verplicht; vorige telling afgewezen — Reden: ' . $reason,
			'status' => 'open',
			'assigneeUserId' => $operator,
			'createdBy' => $userId,
		];

		try {
			$this->saveObjectFor(schemaKey: 'task_schema', id: '', object: $task);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: failed to create recount task after cash diff rejection',
				['exception' => $e->getMessage()]
			);
		}
	}                         

	   
                                                                      
   
                                                                             
                                                                     
                                                                        
                                                                       
   
                                                            
                                                        
                                                    
                                                     
   
                                                                            
   
                                                           
    
	public function emitDiffConfirmedEvent(array $shift, array $diff, string $userId, string $approvedAt): string {
		$eventId = $this->uuid();
		$payload = [
			'specversion' => '1.0',
			'type' => self::EVENT_CASH_DIFF_CONFIRMED,
			'source' => self::EVENT_SOURCE,
			'id' => $eventId,
			'time' => $approvedAt,
			'subject' => (string)($shift['reference'] ?? ($shift['id'] ?? '')),
			'datacontenttype' => 'application/json',
			'data' => [
				'shift_id' => (string)($shift['id'] ?? $shift['uuid'] ?? ''),
				'drawer' => (string)($shift['drawer'] ?? ''),
				'diff_amount' => (float)($diff['diffAmount'] ?? 0),
				'diff_percentage' => $diff['diffPercentage'] ?? null,
				'expected_amount' => (float)($diff['expectedAmount'] ?? 0),
				'actual_amount' => (float)($diff['actualAmount'] ?? 0),
				'approved_by' => $userId,
				'approved_at' => $approvedAt,
			],
		];

		try {
			$event = new Event();
			$this->webhookService->dispatchEvent(_event: $event, eventName: self::EVENT_CASH_DIFF_CONFIRMED, payload: $payload);
			return $eventId;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: cash diff CloudEvent not dispatched (no consumer or OR unavailable)',
				['exception' => $e->getMessage()]
			);
			return '';
		}         
	}                              

	   
                                                    
   
                                              
   
                
   
                                                                    
    
	private function requirePosUser(string $userId): void {
		if ($this->policy->isPosUser(userId: $userId) === false) {
			throw new OCSForbiddenException('Alleen kassamedewerkers mogen kassalade-acties uitvoeren.');
		}
	}                      

	   
                                                   
   
                                              
   
                
   
                                                                   
    
	private function requireManager(string $userId): void {
		if ($this->policy->isManager(userId: $userId) === false) {
			throw new OCSForbiddenException('Alleen een beheerder mag een kasverschil goedkeuren of afwijzen.');
		}
	}                      

	   
                                                         
   
                                     
   
                                           
   
                                                           
    
	private function fetchShift(string $id): array {
		return $this->fetchOne(schemaKey: 'cashShift_schema', id: $id, label: 'Shift niet gevonden.');
	}                  

	   
                                                       
   
                                    
   
                                          
   
                                                          
    
	private function fetchDiff(string $id): array {
		return $this->fetchOne(schemaKey: 'cashDiff_schema', id: $id, label: 'Kasverschil niet gevonden.');
	}                 

	   
                                                          
   
                                                       
                                      
                                                     
   
                                            
   
                                                            
    
	private function fetchOne(string $schemaKey, string $id, string $label): array {
		[$register, $schema] = $this->config(schemaKey: $schemaKey);

		try {
			$object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$object = null;
		}

		if ($object === null) {
			throw new OCSNotFoundException($label);
		}

		return $this->toArray(object: $object);
	}                

	   
                                                    
   
                                                       
                                                 
   
                                                                
    
	private function fetchByShift(string $schemaKey, string $shiftId): array {
		[$register, $schema] = $this->config(schemaKey: $schemaKey);

		try {
			$results = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'shift' => $shiftId,
					],
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: failed to fetch cash objects for shift',
				['exception' => $e->getMessage(), 'schemaKey' => $schemaKey]
			);
			return [];
		}

		$rows = [];
		foreach (($results ?? []) as $result) {
			$row = $this->toArray(object: $result);
			                                                                 
			                                                                
			if ((string)($row['shift'] ?? '') === $shiftId) {
				$rows[] = $row;
			}
		}

		return $rows;
	}                    

	   
                                             
   
                                                       
                                                      
   
                                                 
    
	private function saveShift(string $id, array $shift): array {
		return $this->saveObjectFor(schemaKey: 'cashShift_schema', id: $id, object: $shift);
	}                 

	   
                                                                       
   
                                                       
                                                        
                                                        
   
                                                  
    
	private function saveObjectFor(string $schemaKey, string $id, array $object): array {
		[$register, $schema] = $this->config(schemaKey: $schemaKey);

		unset($object['@self']);

		$saved = $this->getObjectService()->saveObject(
			object: $object,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: $id
		);

		return $this->toArray(object: $saved);
	}                     

	   
                                                                     
   
                                                                               
   
                                                                   
   
                                                                             
    
	private function config(string $schemaKey): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');

		if ($register === '' || $schema === '') {
			throw new OCSNotFoundException('Kassalade-register of -schema is niet geconfigureerd.');
		}

		return [$register, $schema];
	}              

	   
                                       
   
                                      
    
	private function getObjectService(): object {
		                                                                 
		                                                              
		return $this->objectService;
	}                        

	   
                                                  
   
                                                                   
                                                                              
                                                                              
                                                                           
                                                                       
                                                            
   
                                          
    
	private function getAggregationRunner(): object {
		return $this->aggregationRunner;
	}                            

	   
                                                                
   
                                       
   
                                                        
    
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialized = $object->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$data = $object->getObject();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return (array)$object;
	}               

	   
                                       
   
                                         
    
	private function now(): string {
		return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
	}           

	   
                       
   
                            
    
	private function uuid(): string {
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3F) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}            
}           
