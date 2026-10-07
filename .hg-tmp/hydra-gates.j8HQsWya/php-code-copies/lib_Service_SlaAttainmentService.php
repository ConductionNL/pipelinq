<?php

   
                                 
  
                                                                   
                                                                     
                                                                 
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                         
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                     
  
                                                                     
                                                                     
                                                                 
                                                                      
                                                                   
             
  
                                                                                                                                  
                                                                                                                                   
                                                                                          
   
class SlaAttainmentService {
	public const VALID_BUCKETS = ['day', 'week', 'month', 'quarter'];

	public const VALID_GROUPS = ['policy', 'customer', 'tier', 'team', 'target'];

	   
                
   
                                                      
                                            
                                                                               
                                              
    
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private TicketService $ticketService,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                
   
                                                                        
                                                                              
   
                                                    
   
                                                                      
   
                                           
    
	public function compute(array $params): array {
		$bucket = (string)($params['bucket'] ?? 'month');
		if (in_array($bucket, self::VALID_BUCKETS, true) === false) {
			throw new InvalidArgumentException('invalidBucket');
		}

		$groupBy = (string)($params['groupBy'] ?? 'policy');
		if (in_array($groupBy, self::VALID_GROUPS, true) === false) {
			throw new InvalidArgumentException('invalidGroupBy');
		}

		[$start, $end] = $this->resolveBucketRange(bucket: $bucket, params: $params);
		$events = $this->loadBreachEventsInRange(start: $start, end: $end);
		$policyFilter = (string)($params['policy'] ?? '');

		                                                                  
		                                                                  
		$trackedRows = $this->loadTrackedRows();
		$context = $this->buildGroupingContext(groupBy: $groupBy, trackedRows: $trackedRows);

		$accumulated = $this->accumulateBreachedEvents(
			events: $events,
			policyFilter: $policyFilter,
			groupBy: $groupBy,
			context: $context
		);

		                                                                      
		                                              
		$withCounts = $this->countWithObjectsInRange(
			rows: $trackedRows,
			start: $start,
			end: $end,
			policyFilter: $policyFilter,
			groupBy: $groupBy,
			context: $context
		);
		$merged = $this->mergeWithCounts(accumulated: $accumulated, withCounts: $withCounts);

		$byTargetOut = $this->buildByTargetOut(byTarget: $merged['byTarget']);
		$byGroup = $this->buildByGroup(groupAccum: $merged['groupAccum']);
		$overallAttainment = $this->ratio(numerator: $merged['met'], denominator: $merged['total']);

		return [
			'attainment' => $overallAttainment,
			                                                                  
			                                                                   
			'attainmentPercent' => round(($overallAttainment * 100), 1),
			'total' => $merged['total'],
			'met' => $merged['met'],
			'breached' => $accumulated['breached'],
			'inFlightBreached' => $accumulated['inFlight'],
			'closedBreached' => $accumulated['closed'],
			'range' => [
				'start' => $start->format(DateTimeInterface::ATOM),
				'end' => $end->format(DateTimeInterface::ATOM),
			],
			'details' => [
				'byTarget' => $byTargetOut,
				'byGroup' => $byGroup,
			],
		];
	}               

	   
                                                                             
   
                                                                  
                                                                
                                         
                                                                                                      
   
                                                                                                                                          
    
	private function accumulateBreachedEvents(array $events, string $policyFilter, string $groupBy, array $context): array {
		$total = 0;
		$breached = 0;
		$inFlight = 0;
		$closed = 0;
		$byTarget = [];
		$groupAccum = [];

		foreach ($events as $event) {
			if ($policyFilter !== '' && (string)($event['policyId'] ?? '') !== $policyFilter) {
				continue;
			}

			$total++;
			$kind = (string)($event['targetKind'] ?? 'resolution');
			$resolved = isset($event['resolvedAt']) === true && $event['resolvedAt'] !== '';
			if ($resolved === true) {
				$closed++;
			}

			if ($resolved === false) {
				$inFlight++;
			}

			$breached++;
			$byTarget[$kind] = ($byTarget[$kind] ?? ['breached' => 0, 'met' => 0]);
			$byTarget[$kind]['breached']++;

			                                                                         
			$tracked = ($context['tracked'][(string)($event['targetObjectId'] ?? '')] ?? []);
			$groupKeys = $this->groupKeys(
				groupBy: $groupBy,
				policyId: (string)($event['policyId'] ?? ''),
				kinds: [$kind],
				tracked: $tracked,
				event: $event,
				context: $context
			);
			foreach ($groupKeys as $groupKey) {
				$groupName = $this->groupName(groupBy: $groupBy, key: $groupKey, context: $context);
				$groupAccum[$groupKey] = ($groupAccum[$groupKey] ?? ['name' => $groupName, 'total' => 0, 'breached' => 0]);
				$groupAccum[$groupKey]['total']++;
				$groupAccum[$groupKey]['breached']++;
			}
		}             

		return [
			'total' => $total,
			'breached' => $breached,
			'inFlight' => $inFlight,
			'closed' => $closed,
			'byTarget' => $byTarget,
			'groupAccum' => $groupAccum,
		];
	}                                

	   
                                                                                    
   
                                                                                                        
                                                                                             
   
                                                                                                         
    
	private function mergeWithCounts(array $accumulated, array $withCounts): array {
		$byTarget = $accumulated['byTarget'];
		$groupAccum = $accumulated['groupAccum'];

		foreach ($withCounts['byTarget'] as $kind => $count) {
			$byTarget[$kind] = ($byTarget[$kind] ?? ['breached' => 0, 'met' => 0]);
			$byTarget[$kind]['met'] = $count;
		}

		$with = $withCounts['total'];
		$total = $accumulated['total'] + $withCounts['total'];

		foreach ($withCounts['byGroup'] as $key => $entry) {
			$groupAccum[$key] = ($groupAccum[$key] ?? ['name' => $entry['name'], 'total' => 0, 'breached' => 0, 'met' => 0]);
			$groupAccum[$key]['total'] = ($groupAccum[$key]['total'] ?? 0) + $entry['total'];
			$groupAccum[$key]['met'] = ($groupAccum[$key]['met'] ?? 0) + $entry['total'];
		}

		return [
			'total' => $total,
			'met' => $with,
			'byTarget' => $byTarget,
			'groupAccum' => $groupAccum,
		];
	}                      

	   
                                                                                  
   
                                                                                             
   
                                               
    
	private function buildByTargetOut(array $byTarget): array {
		$byTargetOut = [];
		foreach ($byTarget as $kind => $counts) {
			$withCount = (int)$counts['met'];
			$denom = ($counts['breached'] + $withCount);
			$attainment = $this->ratio(numerator: $withCount, denominator: $denom);

			$byTargetOut[$kind] = [
				'attainment' => $attainment,
				'breached' => $counts['breached'],
				'met' => $withCount,
			];
		}

		return $byTargetOut;
	}                        

	   
                                                                                
   
                                                                                                
   
                                            
    
	private function buildByGroup(array $groupAccum): array {
		$byGroup = [];
		foreach ($groupAccum as $key => $entry) {
			$denom = (int)$entry['total'];
			$withE = (int)($entry['met'] ?? 0);
			$groupAttainment = $this->ratio(numerator: $withE, denominator: $denom);

			$byGroup[] = [
				'groupKey' => (string)$key,
				'groupName' => (string)($entry['name'] ?? $key),
				'attainment' => $groupAttainment,
				'total' => $denom,
				'met' => $withE,
				'breached' => (int)$entry['breached'],
			];
		}

		return $byGroup;
	}                    

	   
                                                                             
   
                                        
                                            
   
                 
    
	private function ratio(int $numerator, int $denominator): float {
		if ($denominator > 0) {
			return round(($numerator / $denominator), 4);
		}

		return 0.0;
	}             

	   
                                                                   
   
                                            
                                                          
   
                                                                    
   
                                                                     
   
                                           
    
	public function resolveBucketRange(string $bucket, array $params): array {
		$tz = new DateTimeZone('UTC');
		$now = new DateTimeImmutable('now', $tz);

		return match ($bucket) {
			'day' => $this->resolveDayRange(now: $now, tz: $tz, params: $params),
			'week' => $this->resolveWeekRange(now: $now, tz: $tz, params: $params),
			'month' => $this->resolveMonthRange(now: $now, tz: $tz, params: $params),
			'quarter' => $this->resolveQuarterRange(now: $now, tz: $tz, params: $params),
			default => throw new InvalidArgumentException('invalidBucket'),
		};
	}                          

	   
                                                        
   
                                                  
                                             
                                                          
   
                                                             
   
                                                              
    
	private function resolveDayRange(DateTimeImmutable $now, DateTimeZone $tz, array $params): array {
		                                                           
		                                                              
		                                                                
		$date = (string)($params['date'] ?? '');
		if ($date === '') {
			$date = $now->format('Y-m-d');
		} elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
			throw new InvalidArgumentException('invalidDate');
		}

		$start = new DateTimeImmutable($date . ' 00:00:00', $tz);
		return [$start, $start->modify('+1 day')];
	}                       

	   
                                                         
   
                                                  
                                             
                                                          
   
                                                             
   
                                                              
    
	private function resolveWeekRange(DateTimeImmutable $now, DateTimeZone $tz, array $params): array {
		$week = (string)($params['week'] ?? '');
		if ($week === '') {
			$week = $now->format('o-\WW');
		}

		if (preg_match('/^(\d{4})-W(\d{1,2})$/', $week, $matches) !== 1) {
			throw new InvalidArgumentException('invalidWeek');
		}

		$start = (new DateTimeImmutable('now', $tz))
			->setISODate((int)$matches[1], (int)$matches[2])
			->setTime(0, 0);
		return [$start, $start->modify('+7 days')];
	}                        

	   
                                                          
   
                                                  
                                             
                                                          
   
                                                             
   
                                                               
    
	private function resolveMonthRange(DateTimeImmutable $now, DateTimeZone $tz, array $params): array {
		$month = (string)($params['month'] ?? '');
		if ($month === '') {
			$month = $now->format('Y-m');
		}

		if (preg_match('/^(\d{4})-(\d{2})$/', $month) !== 1) {
			throw new InvalidArgumentException('invalidMonth');
		}

		$start = new DateTimeImmutable($month . '-01 00:00:00', $tz);
		return [$start, $start->modify('+1 month')];
	}                         

	   
                                                            
   
                                                  
                                             
                                                          
   
                                                             
   
                                                                 
    
	private function resolveQuarterRange(DateTimeImmutable $now, DateTimeZone $tz, array $params): array {
		$quarter = (string)($params['quarter'] ?? '');
		if ($quarter === '') {
			$quarter = sprintf('%s-Q%d', $now->format('Y'), (int)ceil(((int)$now->format('n')) / 3));
		}

		if (preg_match('/^(\d{4})-Q([1-4])$/', $quarter, $matches) !== 1) {
			throw new InvalidArgumentException('invalidQuarter');
		}

		$month = (((int)$matches[2] - 1) * 3) + 1;
		$start = new DateTimeImmutable(sprintf('%s-%02d-01 00:00:00', $matches[1], $month), $tz);
		return [$start, $start->modify('+3 months')];
	}                           

	   
                                                                    
   
                                                  
                                                          
   
                                                    
    
	private function loadBreachEventsInRange(DateTimeInterface $start, DateTimeInterface $end): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'sla_register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'sla_breach_event_schema', '');
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'SlaAttainmentService: ObjectService not available',
				['error' => $e->getMessage()]
			);
			return [];
		}

		try {
			                                                               
			  
			                                                        
			                                                                    
			                                                                     
			                                                                  
			                                                                  
			                       
			$rows = $objectService->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 5000,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SlaAttainmentService: findAll failed',
				['error' => $e->getMessage()]
			);
			return [];
		}

		$events = [];
		foreach ((array)$rows as $row) {
			$event = $this->parseBreachEventRow(row: $row, start: $start, end: $end);
			if ($event !== null) {
				$events[] = $event;
			}
		}

		return $events;
	}                               

	   
                                                     
   
                              
                                                  
                                                          
   
                                                                                                 
    
	private function parseBreachEventRow(mixed $row, DateTimeInterface $start, DateTimeInterface $end): ?array {
		$array = $this->normalise(row: $row);
		$when = $array['breachedAt'] ?? '';
		if ($when === '') {
			return null;
		}

		try {
			$instant = new DateTimeImmutable((string)$when);
		} catch (Throwable $e) {
			return null;
		}

		if ($instant < $start || $instant >= $end) {
			return null;
		}

		return $array;
	}                           

	   
                                                                 
                                                                     
                                                                       
                                                                
   
                                                             
    
	private function loadTrackedRows(): array {
		                                                                       
		                                                   
		$raw = [];
		foreach ([TicketService::TYPE_REQUEST, TicketService::TYPE_COMPLAINT] as $ticketType) {
			$raw = array_merge($raw, (array)$this->ticketService->findByType($ticketType, [], 5000));
		}

		$raw = array_merge($raw, $this->findConfiguredObjects(registerKey: 'register', schemaKey: 'callback_schema'));

		return array_map(fn ($row): array => $this->normalise(row: $row), $raw);
	}                       

	   
                                                                          
                                                                            
                     
   
                                         
                                                                                                 
   
                                                                                                                                              
    
	private function buildGroupingContext(string $groupBy, array $trackedRows): array {
		$tracked = [];
		foreach ($trackedRows as $row) {
			if (($row['id'] ?? '') !== '') {
				$tracked[(string)$row['id']] = $row;
			}
		}

		$policies = [];
		if (in_array($groupBy, ['policy', 'tier'], true) === true) {
			foreach ($this->findConfiguredObjects(registerKey: 'sla_register', schemaKey: 'sla_policy_schema') as $row) {
				$array = $this->normalise(row: $row);
				$policies[(string)($array['id'] ?? '')] = $array;
			}
		}

		$clients = [];
		if ($groupBy === 'customer') {
			foreach ($this->findConfiguredObjects(registerKey: 'register', schemaKey: 'client_schema') as $row) {
				$array = $this->normalise(row: $row);
				$clients[(string)($array['id'] ?? '')] = (string)($array['name'] ?? '');
			}
		}

		return ['tracked' => $tracked, 'policies' => $policies, 'clients' => $clients];
	}                            

	   
                                                                          
                                                                     
                      
   
                                                                                          
                                                  
                                              
                                                                
                                         
                                                                                                      
   
                                                                                                                 
    
	private function countWithObjectsInRange(
		array $rows,
		DateTimeInterface $start,
		DateTimeInterface $end,
		string $policyFilter,
		string $groupBy,
		array $context,
	): array {
		$accumulator = ['total' => 0, 'byTarget' => [], 'byGroup' => []];

		foreach ($rows as $row) {
			$result = $this->evaluateTrackedObjectRow(row: $row, start: $start, end: $end, policyFilter: $policyFilter);
			if ($result === null) {
				continue;
			}

			$accumulator['total']++;
			foreach ($result['byTarget'] as $kind => $count) {
				$accumulator['byTarget'][$kind] = ($accumulator['byTarget'][$kind] ?? 0) + $count;
			}

			$groupKeys = $this->groupKeys(
				groupBy: $groupBy,
				policyId: $result['policyId'],
				kinds: array_keys($result['byTarget']),
				tracked: $row,
				event: [],
				context: $context
			);
			foreach ($groupKeys as $key) {
				$name = $this->groupName(groupBy: $groupBy, key: $key, context: $context);
				$accumulator['byGroup'][$key] = ($accumulator['byGroup'][$key] ?? ['name' => $name, 'total' => 0]);
				$accumulator['byGroup'][$key]['total']++;
			}
		}             

		return $accumulator;
	}                               

	   
                                                                            
   
                                                                      
                                                                  
   
                                                                      
    
	private function findConfiguredObjects(string $registerKey, string $schemaKey): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, $registerKey, '');
		$schemaId = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
		if ($register === '' || $schemaId === '') {
			return [];
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			return [];
		}

		return $this->fetchTrackedObjectRows(objectService: $objectService, register: $register, schemaId: $schemaId);
	}                             

	   
                                                                        
   
                                                                
                                                    
                                                  
   
                             
    
	private function fetchTrackedObjectRows(object $objectService, string $register, string $schemaId): array {
		try {
			                                                                  
			                                                                     
			$rows = $objectService->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schemaId,
					],
					'limit' => 5000,
				]
			);
		} catch (Throwable $e) {
			return [];
		}

		return (array)$rows;
	}                              

	   
                                                                    
   
                              
                                                  
                                              
                                                                
   
                                                                                                                        
    
	private function evaluateTrackedObjectRow(mixed $row, DateTimeInterface $start, DateTimeInterface $end, string $policyFilter): ?array {
		$array = $this->normalise(row: $row);
		$slaStatus = $array['slaStatus'] ?? null;
		if (is_array($slaStatus) === false) {
			return null;
		}

		if ($policyFilter !== '' && (string)($slaStatus['policyId'] ?? '') !== $policyFilter) {
			return null;
		}

		$targets = $this->evaluateSlaTargets(slaStatus: $slaStatus, start: $start, end: $end);
		if ($targets['allMet'] === false || $targets['touchedKey'] === false) {
			return null;
		}

		return [
			'byTarget' => $targets['byTarget'],
			'policyId' => (string)($slaStatus['policyId'] ?? ''),
		];
	}                                

	   
                                                  
   
                                                                  
                                                  
                                              
   
                                                                               
    
	private function evaluateSlaTargets(array $slaStatus, DateTimeInterface $start, DateTimeInterface $end): array {
		$allWith = true;
		$touchedKey = false;
		$byTarget = [];
		foreach (($slaStatus['targets'] ?? []) as $target) {
			$status = (string)($target['status'] ?? '');
			if ($status !== SlaEngineService::STATUS_MET) {
				$allWith = false;
				continue;
			}

			$withAt = $target['withAt'] ?? '';
			if ($withAt === '') {
				continue;
			}

			try {
				$withInstant = new DateTimeImmutable((string)$withAt);
			} catch (Throwable $e) {
				continue;
			}

			if ($withInstant >= $start && $withInstant < $end) {
				$touchedKey = true;
				$kind = (string)($target['kind'] ?? 'resolution');
				$byTarget[$kind] = ($byTarget[$kind] ?? 0) + 1;
			}
		}             

		return ['allMet' => $allWith, 'touchedKey' => $touchedKey, 'byTarget' => $byTarget];
	}                          

	   
                                                                          
                                                                        
   
                                                                       
                                                                       
                                                                          
                                             
   
                                         
                                                    
                                                              
                                                                              
                                                                              
                                                                                                      
   
                                          
    
	private function groupKeys(
		string $groupBy,
		string $policyId,
		array $kinds,
		array $tracked,
		array $event,
		array $context,
	): array {
		$policyTier = (string)($context['policies'][$policyId]['customerTier'] ?? '');
		if ($policyTier === '*') {
			$policyTier = '';
		}

		return match ($groupBy) {
			'policy' => [$this->firstFilled(values: [$policyId])],
			'target' => $kinds,
			'tier' => [
				$this->firstFilled(
					values: [
						$event['customerTier'] ?? null,
						$tracked['slaTier'] ?? null,
						$tracked['customerTier'] ?? null,
						$policyTier,
					]
				),
			],
			'team' => [$this->firstFilled(values: [$event['team'] ?? null, $tracked['team'] ?? null])],
			'customer' => [$this->firstFilled(values: [$event['organisationId'] ?? null, $tracked['client'] ?? null])],
			default => ['all'],
		};
	}                 

	   
                                                 
   
                                                                        
   
                             
    
	private function firstFilled(array $values): string {
		foreach ($values as $value) {
			if (is_string($value) === true && $value !== '') {
				return $value;
			}
		}

		return 'unspecified';
	}                   

	   
                                                                        
                                           
   
                                         
                                 
                                                                                                      
   
                                      
    
	private function groupName(string $groupBy, string $key, array $context): string {
		$name = match ($groupBy) {
			'policy' => (string)($context['policies'][$key]['name'] ?? ''),
			'customer' => (string)($context['clients'][$key] ?? ''),
			default => '',
		};

		if ($name === '') {
			return $key;
		}

		return $name;
	}                 

	   
                                                         
   
                              
   
                                                  
    
	private function normalise($row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'getObject') === true) {
			$object = $row->getObject();
			if (is_array($object) === true) {
				return $object;
			}
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$json = $row->jsonSerialize();
			if (is_array($json) === true) {
				return $json;
			}

			return [];
		}

		return [];
	}                 
}           
