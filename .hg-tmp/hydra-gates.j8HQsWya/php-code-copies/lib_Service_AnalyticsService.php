<?php

   
                             
  
                                                                     
                                                              
                                                                       
                                      
  
                                                                        
                                                               
                                                         
                            
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                            
  
                                                          
  
                                                                    
                                    
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                          
  
                                                                           
                                                                        
                                                                           
                       
  
                                                          
  
                                                                                                                               
                                                                                                                                  
   
class AnalyticsService {
	   
                                                      
   
                           
    
	public const ALLOWED_PERIODS = ['week', 'month', 'quarter'];

	   
                                             
   
               
    
	public const DEFAULT_PERIOD = 'month';

	   
                                     
   
                           
   
                                                      
                                                     
    
	public const ALLOWED_TREND_METRICS = [
		'leads',
		'requests-by-category',
		'pipeline-value',
		'revenue',
		'pipeline-by-stage',
		'revenue-by-product-category',
		'top-customers',
	];

	   
                                                            
   
                           
   
                                                     
    
	private const REVENUE_POS_STATUSES = ['settled', 'confirmed'];

	   
                                                           
   
            
   
                                                     
    
	private const TOP_CUSTOMERS_LIMIT = 8;

	   
                                                                        
   
                           
    
	private const PERIOD_DAYS = [
		'week' => 7,
		'month' => 30,
		'quarter' => 90,
		'year' => 365,
	];

	   
                                                                               
   
                           
   
                                                      
    
	public const ALLOWED_OVERVIEW_PERIODS = ['week', 'month', 'quarter', 'year'];

	   
                
   
                                                                         
                                              
                                                                               
                                                                                         
    
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private readonly TicketService $ticketService,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                                              
   
                                                                            
                                                                        
                                                                     
                                                                         
                                                        
   
                                                                         
   
                                            
   
                                                                                                                          
    
	private function findTickets(string $ticketType): array {
		$objects = [];
		foreach ($this->ticketService->findByType(ticketType: $ticketType) as $result) {
			$objects[] = $this->toArray(object: $result);
		}

		return $objects;
	}                   

	   
                                                                        
   
                                                                   
                                                                    
                                                                 
   
                                                                      
   
                                            
   
                                                                        
    
	private function findObjects(string $schemaKey): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
		if ($register === '' || $schema === '') {
			$this->logger->info(
				message: '[AnalyticsService] register or schema not configured',
				context: ['schemaKey' => $schemaKey]
			);
			return [];
		}

		try {
			$objectService = $this->getObjectService();
			$results = $objectService->findAll(
				config: ['filters' => ['register' => $register, 'schema' => $schema]]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[AnalyticsService] findAll failed',
				context: ['schemaKey' => $schemaKey, 'error' => $e->getMessage()]
			);
			throw new RuntimeException(message: 'Analytics query failed', code: 0, previous: $e);
		}

		$objects = [];
		foreach (($results ?? []) as $result) {
			$objects[] = $this->toArray(object: $result);
		}

		return $objects;
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

		return [];
	}               

	   
                                                            
   
                                              
    
	private function getObjectService(): object {
		                                                                 
		                                                              
		return $this->objectService;
	}                        

	   
                                                               
   
             
                                                                                    
                                                                                     
                                                                                      
                                                        
                                                                                   
                                                                                             
                                                         
                                                                                      
   
                                                          
   
                  
                                     
                                           
                               
                                            
                     
                                          
     
   
                                                                       
                                                              
   
                                                      
    
	public function getOverview(string $period = self::DEFAULT_PERIOD): array {
		if (in_array($period, self::ALLOWED_OVERVIEW_PERIODS, true) === false) {
			throw new InvalidArgumentException(message: 'Invalid period');
		}

		$days = self::PERIOD_DAYS[$period];
		$now = new DateTimeImmutable();

		$currentStart = $now->modify(sprintf('-%d days', $days));
		$previousStart = $currentStart->modify(sprintf('-%d days', $days));

		$leads = $this->findObjects(schemaKey: 'lead_schema');
		$requests = $this->findTickets(ticketType: TicketService::TYPE_REQUEST);
		$cms = $this->findTickets(ticketType: TicketService::TYPE_CONTACTMOMENT);
		                                                                       
		                                                                 
		                                                                            
		$responses = [];

		$current = $this->aggregateOverviewWindow(
			from: $currentStart,
			to: $now,
			leads: $leads,
			requests: $requests,
			cms: $cms,
			responses: $responses
		);
		$previous = $this->aggregateOverviewWindow(
			from: $previousStart,
			to: $currentStart,
			leads: $leads,
			requests: $requests,
			cms: $cms,
			responses: $responses
		);

		return [
			'leadConversionRate' => $current['leadConversionRate'],
			'avgRequestResolutionTime' => $current['avgRequestResolutionTime'],
			'contactMomentVolume' => $current['contactMomentVolume'],
			'customerSatisfactionScore' => $current['customerSatisfactionScore'],
			'period' => $period,
			'previousPeriod' => $previous,
		];
	}                   

	   
                                                         
   
                                                                          
   
                                                         
                                                       
                                                             
                                                                          
                                                                           
                                                                            
   
                                         
   
                                                                                                                      
                                                                                                                                             
    
	private function aggregateOverviewWindow(
		DateTimeInterface $from,
		DateTimeInterface $to,
		array $leads,
		array $requests,
		array $cms,
		array $responses,
	): array {
		$fromTs = $from->getTimestamp();
		$toTs = $to->getTimestamp();

		                        
		$totalLeads = 0;
		$wonLeads = 0;
		foreach ($leads as $lead) {
			$timestamp = $this->extractTimestamp(row: $lead, fields: ['createdAt', 'created', 'expectedCloseDate']);
			if ($timestamp === null || $timestamp < $fromTs || $timestamp >= $toTs) {
				continue;
			}

			$totalLeads++;
			if (((string)($lead['status'] ?? '')) === 'won') {
				$wonLeads++;
			}
		}

		$leadConversionRate = null;
		if ($totalLeads > 0) {
			$leadConversionRate = round(($wonLeads * 100.0) / $totalLeads, 1);
		}

		                                                                  
		                                                                
		                                                                   
		$resolutionHours = 0.0;
		$resolvedCount = 0;
		foreach ($requests as $request) {
			$requestedTs = $this->extractTimestamp(row: $request, fields: ['occurredAt']);
			$completedTs = $this->extractTimestamp(row: $request, fields: ['resolvedAt', 'completedAt']);
			if ($requestedTs === null || $completedTs === null) {
				continue;
			}

			if ($completedTs < $fromTs || $completedTs >= $toTs) {
				continue;
			}

			$resolutionHours += max(0, ($completedTs - $requestedTs) / 3600.0);
			$resolvedCount++;
		}

		$avgResolutionHours = null;
		if ($resolvedCount > 0) {
			$avgResolutionHours = round($resolutionHours / $resolvedCount, 2);
		}

		                                                              
		                                     
		$cmCount = 0;
		foreach ($cms as $cm) {
			$timestamp = $this->extractTimestamp(row: $cm, fields: ['occurredAt', 'createdAt']);
			if ($timestamp === null || $timestamp < $fromTs || $timestamp >= $toTs) {
				continue;
			}

			$cmCount++;
		}

		                                                              
		$scoreSum = 0.0;
		$scoreN = 0;
		foreach ($responses as $r) {
			$timestamp = $this->extractTimestamp(row: $r, fields: ['submittedAt', 'createdAt']);
			if ($timestamp === null || $timestamp < $fromTs || $timestamp >= $toTs) {
				continue;
			}

			if (isset($r['score']) === false || is_numeric($r['score']) === false) {
				continue;
			}

			$scoreSum += (float)$r['score'];
			$scoreN++;
		}

		$satisfactionScore = null;
		if ($scoreN > 0) {
			$satisfactionScore = round($scoreSum / $scoreN, 2);
		}

		return [
			'leadConversionRate' => $leadConversionRate,
			'avgRequestResolutionTime' => $avgResolutionHours,
			'contactMomentVolume' => $cmCount,
			'customerSatisfactionScore' => $satisfactionScore,
		];
	}                               

	   
                                                            
   
                      
                                                                     
                                                                              
                                                                      
                                                                        
   
                                                       
                                                          
   
                                                                                           
   
                                                                       
                                                              
   
                                                      
    
	public function getTrends(string $metric, string $period = self::DEFAULT_PERIOD): array {
		if (in_array($metric, self::ALLOWED_TREND_METRICS, true) === false) {
			throw new InvalidArgumentException(message: 'Unsupported metric');
		}

		if (in_array($period, self::ALLOWED_OVERVIEW_PERIODS, true) === false) {
			throw new InvalidArgumentException(message: 'Invalid period');
		}

		return match ($metric) {
			'requests-by-category' => $this->buildCategorySeries(period: $period),
			'revenue' => $this->buildRevenueSeries(period: $period),
			'pipeline-by-stage' => $this->buildPipelineByStageSeries(period: $period),
			'revenue-by-product-category' => $this->buildRevenueByCategorySeries(period: $period),
			'top-customers' => $this->buildTopCustomersSeries(period: $period),
			default => $this->buildTimeBucketSeries(metric: $metric, period: $period),
		};
	}                 

	   
                                                          
   
                                                             
                                                          
   
                                                                                           
    
	private function buildTimeBucketSeries(string $metric, string $period): array {
		$leads = $this->findObjects(schemaKey: 'lead_schema');
		$days = self::PERIOD_DAYS[$period];
		$now = new DateTimeImmutable();
		$start = $now->modify(sprintf('-%d days', $days));
		$startTs = $start->getTimestamp();
		$nowTs = $now->getTimestamp();

		                                                                            
		$bucketSize = match ($period) {
			'week', 'month' => 86400,
			         
			'quarter' => 86400 * 7,
			          
			'year' => 86400 * 30,
			            
			default => 86400,
		};

		$buckets = [];
		foreach ($leads as $lead) {
			$timestamp = $this->extractTimestamp(row: $lead, fields: ['createdAt', 'created', 'expectedCloseDate']);
			if ($timestamp === null || $timestamp < $startTs || $timestamp > $nowTs) {
				continue;
			}

			$bucketTs = $startTs + ((int)floor(($timestamp - $startTs) / $bucketSize)) * $bucketSize;
			$key = date('Y-m-d', $bucketTs);
			if (isset($buckets[$key]) === false) {
				$buckets[$key] = 0;
			}

			if ($metric === 'leads') {
				$buckets[$key] += 1;
				continue;
			}

			$buckets[$key] += (float)($lead['value'] ?? 0);
		}

		ksort($buckets);
		$series = [];
		foreach ($buckets as $date => $value) {
			$bucketValue = round((float)$value, 2);
			if ($metric === 'leads') {
				$bucketValue = (int)$value;
			}

			$series[] = ['date' => $date, 'value' => $bucketValue];
		}

		return ['metric' => $metric, 'period' => $period, 'series' => $series];
	}                             

	   
                                                                           
                            
   
                                                          
   
                                                                                           
    
	private function buildCategorySeries(string $period): array {
		$requests = $this->findTickets(ticketType: TicketService::TYPE_REQUEST);
		$days = self::PERIOD_DAYS[$period];
		$now = new DateTimeImmutable();
		$startTs = $now->modify(sprintf('-%d days', $days))->getTimestamp();
		$nowTs = $now->getTimestamp();

		$counts = [];
		foreach ($requests as $request) {
			$timestamp = $this->extractTimestamp(row: $request, fields: ['occurredAt', 'createdAt']);
			if ($timestamp === null || $timestamp < $startTs || $timestamp > $nowTs) {
				continue;
			}

			                                                               
			                                                                     
			$category = (string)($request['category'] ?? '');
			if ($category === '') {
				continue;
			}

			if (isset($counts[$category]) === false) {
				$counts[$category] = 0;
			}

			$counts[$category]++;
		}

		                                                 
		arsort($counts);

		$series = [];
		foreach ($counts as $category => $count) {
			if ($count <= 0) {
				continue;
			}

			$series[] = ['date' => $category, 'value' => $count];
		}

		return ['metric' => 'requests-by-category', 'period' => $period, 'series' => $series];
	}                           

	   
                                                      
   
            
                                                          
                                                                                
   
                  
                                                
                                                  
     
   
                                                              
   
                                                      
    
	public function getFunnels(): array {
		$leads = $this->findObjects(schemaKey: 'lead_schema');
		$requests = $this->findTickets(ticketType: TicketService::TYPE_REQUEST);

		$leadCounts = ['open' => 0, 'won' => 0, 'lost' => 0];
		foreach ($leads as $lead) {
			$status = (string)($lead['status'] ?? '');
			if (isset($leadCounts[$status]) === true) {
				$leadCounts[$status]++;
			}
		}

		$leadTotal = $leadCounts['open'] + $leadCounts['won'] + $leadCounts['lost'];
		$leadConversionRate = null;
		if ($leadTotal > 0) {
			$leadConversionRate = round(($leadCounts['won'] * 100.0) / $leadTotal, 1);
		}

		$requestCounts = ['new' => 0, 'in_progress' => 0, 'completed' => 0, 'rejected' => 0];
		foreach ($requests as $request) {
			$status = (string)($request['status'] ?? '');
			if (isset($requestCounts[$status]) === true) {
				$requestCounts[$status]++;
			}
		}

		$requestTotal = array_sum($requestCounts);
		$resolutionRate = null;
		if ($requestTotal > 0) {
			$resolutionRate = round(($requestCounts['completed'] * 100.0) / $requestTotal, 1);
		}

		return [
			'leadFunnel' => array_merge($leadCounts, ['conversionRate' => $leadConversionRate]),
			'requestFunnel' => array_merge($requestCounts, ['resolutionRate' => $resolutionRate]),
		];
	}                  

	   
                                                         
   
                                                 
                                                                                 
                                                                 
                                                                                          
                                                                       
                                                                                                
                                                                         
                                                                                 
   
                                                          
   
                  
                     
                      
                          
                              
                              
                               
                     
                                               
     
   
                                                                       
                                                              
   
                                                     
    
	public function getCommercialOverview(string $period = self::DEFAULT_PERIOD): array {
		if (in_array($period, self::ALLOWED_OVERVIEW_PERIODS, true) === false) {
			throw new InvalidArgumentException(message: 'Invalid period');
		}

		$days = self::PERIOD_DAYS[$period];
		$now = new DateTimeImmutable();

		$currentStart = $now->modify(sprintf('-%d days', $days));
		$previousStart = $currentStart->modify(sprintf('-%d days', $days));

		$leads = $this->findObjects(schemaKey: 'lead_schema');
		$pos = $this->findObjects(schemaKey: 'posTransaction_schema');

		$current = $this->aggregateCommercialWindow(from: $currentStart, to: $now, leads: $leads, pos: $pos);
		$previous = $this->aggregateCommercialWindow(from: $previousStart, to: $currentStart, leads: $leads, pos: $pos);

		$forecast = $this->aggregateOpenPipeline(leads: $leads);

		return [
			'revenue' => $current['revenue'],
			'wonValue' => $current['wonValue'],
			'winRate' => $current['winRate'],
			'avgDealSize' => $current['avgDealSize'],
			'weightedForecast' => $forecast['weightedForecast'],
			'openPipelineValue' => $forecast['openPipelineValue'],
			'period' => $period,
			'previousPeriod' => $previous,
		];
	}                             

	   
                                                                  
   
                                                         
                                                       
                                                             
                                                                      
   
                                                                                                
   
                                                     
   
                                                                                                                   
                                                                                                                       
    
	private function aggregateCommercialWindow(
		DateTimeInterface $from,
		DateTimeInterface $to,
		array $leads,
		array $pos,
	): array {
		$fromTs = $from->getTimestamp();
		$toTs = $to->getTimestamp();

		$posRevenue = 0.0;
		foreach ($pos as $txn) {
			$timestamp = $this->posRevenueTimestamp(txn: $txn);
			if ($this->isRevenueTransaction(txn: $txn) === false || $timestamp === null || $timestamp < $fromTs || $timestamp >= $toTs) {
				continue;
			}

			$posRevenue += (float)($txn['total'] ?? 0);
		}

		$wonValue = 0.0;
		$wonCount = 0;
		$lostCount = 0;
		foreach ($leads as $lead) {
			$status = (string)($lead['status'] ?? '');
			if ($status !== 'won' && $status !== 'lost') {
				continue;
			}

			$timestamp = $this->leadCloseTimestamp(lead: $lead);
			if ($timestamp === null || $timestamp < $fromTs || $timestamp >= $toTs) {
				continue;
			}

			if ($status === 'won') {
				$wonValue += (float)($lead['value'] ?? 0);
				$wonCount++;
				continue;
			}

			$lostCount++;
		}

		$closed = ($wonCount + $lostCount);

		$winRate = null;
		if ($closed > 0) {
			$winRate = round(($wonCount * 100.0) / $closed, 1);
		}

		$avgDealSize = null;
		if ($wonCount > 0) {
			$avgDealSize = round($wonValue / $wonCount, 2);
		}

		return [
			'revenue' => round(($posRevenue + $wonValue), 2),
			'wonValue' => round($wonValue, 2),
			'winRate' => $winRate,
			'avgDealSize' => $avgDealSize,
		];
	}                                 

	   
                                                                        
   
                                                             
   
                                                                    
   
                                                     
                                                                                             
    
	private function aggregateOpenPipeline(array $leads): array {
		$weightedForecast = 0.0;
		$openPipelineValue = 0.0;
		foreach ($leads as $lead) {
			if (((string)($lead['status'] ?? '')) !== 'open') {
				continue;
			}

			$openPipelineValue += (float)($lead['value'] ?? 0);
			$weightedForecast += $this->winChanceWeightedValue(lead: $lead);
		}

		return [
			'weightedForecast' => round($weightedForecast, 2),
			'openPipelineValue' => round($openPipelineValue, 2),
		];
	}                             

	   
                                              
   
                                                                          
                                                                           
                                                                       
                                                                           
                                                
   
                                                   
   
                                     
   
                                                                                             
    
	private function winChanceWeightedValue(array $lead): float {
		$score = ($lead['qualificationScore'] ?? null);
		if (is_numeric($score) === false) {
			return 0.0;
		}

		return ((float)($lead['value'] ?? 0) * (max(0.0, min(100.0, (float)$score)) / 100.0));
	}                              

	   
                                                                        
   
                                                          
   
                                                                                           
   
                                                     
    
	private function buildRevenueSeries(string $period): array {
		$pos = $this->findObjects(schemaKey: 'posTransaction_schema');
		$leads = $this->findObjects(schemaKey: 'lead_schema');

		$days = self::PERIOD_DAYS[$period];
		$now = new DateTimeImmutable();
		$startTs = $now->modify(sprintf('-%d days', $days))->getTimestamp();
		$nowTs = $now->getTimestamp();
		$bucketSize = $this->bucketSizeForPeriod(period: $period);

		$buckets = [];
		foreach ($pos as $txn) {
			if ($this->isRevenueTransaction(txn: $txn) === false) {
				continue;
			}

			$key = $this->bucketKey(timestamp: $this->posRevenueTimestamp(txn: $txn), startTs: $startTs, nowTs: $nowTs, bucketSize: $bucketSize);
			if ($key !== null) {
				$buckets[$key] = (($buckets[$key] ?? 0.0) + (float)($txn['total'] ?? 0));
			}
		}

		foreach ($leads as $lead) {
			if (((string)($lead['status'] ?? '')) !== 'won') {
				continue;
			}

			$key = $this->bucketKey(timestamp: $this->leadCloseTimestamp(lead: $lead), startTs: $startTs, nowTs: $nowTs, bucketSize: $bucketSize);
			if ($key !== null) {
				$buckets[$key] = (($buckets[$key] ?? 0.0) + (float)($lead['value'] ?? 0));
			}
		}

		ksort($buckets);
		$series = [];
		foreach ($buckets as $date => $value) {
			$series[] = ['date' => $date, 'value' => round((float)$value, 2)];
		}

		return ['metric' => 'revenue', 'period' => $period, 'series' => $series];
	}                          

	   
                                                                      
   
                                                                        
   
                                                                                           
   
                                                     
    
	private function buildPipelineByStageSeries(string $period): array {
		$leads = $this->findObjects(schemaKey: 'lead_schema');

		$byStage = [];
		$order = [];
		foreach ($leads as $lead) {
			if (((string)($lead['status'] ?? '')) !== 'open') {
				continue;
			}

			$stage = (string)($lead['stage'] ?? '');
			if ($stage === '') {
				$stage = 'Unassigned';
			}

			$byStage[$stage] = (($byStage[$stage] ?? 0.0) + (float)($lead['value'] ?? 0));
			if (isset($order[$stage]) === false) {
				$order[$stage] = (int)($lead['stageOrder'] ?? 999);
			}
		}

		$rows = [];
		foreach ($byStage as $stage => $value) {
			$rows[] = ['date' => (string)$stage, 'value' => round((float)$value, 2), 'order' => $order[$stage]];
		}

		usort(
			$rows,
			static function (array $left, array $right): int {
				return ($left['order'] <=> $right['order']);
			}
		);

		$series = [];
		foreach ($rows as $row) {
			$series[] = ['date' => $row['date'], 'value' => $row['value']];
		}

		return ['metric' => 'pipeline-by-stage', 'period' => $period, 'series' => $series];
	}                                  

	   
                                                              
   
                                                             
                                                                   
                                                          
   
                                                          
   
                                                                                           
   
                                                     
   
                                                                                                                  
    
	private function buildRevenueByCategorySeries(string $period): array {
		$days = self::PERIOD_DAYS[$period];
		$now = new DateTimeImmutable();
		$startTs = $now->modify(sprintf('-%d days', $days))->getTimestamp();
		$nowTs = $now->getTimestamp();

		$pos = $this->findObjects(schemaKey: 'posTransaction_schema');
		$lines = $this->findObjects(schemaKey: 'posTransactionLine_schema');
		$products = $this->findObjects(schemaKey: 'product_schema');
		$categories = $this->findObjects(schemaKey: 'productCategory_schema');

		$eligibleTxn = [];
		foreach ($pos as $txn) {
			$timestamp = $this->posRevenueTimestamp(txn: $txn);
			if ($this->isRevenueTransaction(txn: $txn) === false || $timestamp === null || $timestamp < $startTs || $timestamp > $nowTs) {
				continue;
			}

			$id = $this->objectId(row: $txn);
			if ($id !== '') {
				$eligibleTxn[$id] = true;
			}
		}

		$categoryByProduct = $this->productCategoryIndex(products: $products);
		                                                                     
		                                                                   
		                                                        
		$categoryNames = $this->categoryNameIndex(categories: $categories);

		$byCategory = [];
		foreach ($lines as $line) {
			$txnId = (string)($line['transaction'] ?? '');
			if ($txnId === '' || isset($eligibleTxn[$txnId]) === false) {
				continue;
			}

			$categoryRef = ($categoryByProduct[(string)($line['product'] ?? '')] ?? '');
			$category = ($categoryNames[$categoryRef] ?? $categoryRef);
			if ($category === '') {
				$category = 'Other';
			}

			$byCategory[$category] = (($byCategory[$category] ?? 0.0) + (float)($line['lineTotal'] ?? 0));
		}

		arsort($byCategory);
		$series = [];
		foreach ($byCategory as $category => $value) {
			$series[] = ['date' => (string)$category, 'value' => round((float)$value, 2)];
		}

		return ['metric' => 'revenue-by-product-category', 'period' => $period, 'series' => $series];
	}                                    

	   
                                                             
   
                                                          
   
                                                                                           
   
                                                     
   
                                                                                                                   
    
	private function buildTopCustomersSeries(string $period): array {
		$days = self::PERIOD_DAYS[$period];
		$now = new DateTimeImmutable();
		$startTs = $now->modify(sprintf('-%d days', $days))->getTimestamp();
		$nowTs = $now->getTimestamp();

		$leads = $this->findObjects(schemaKey: 'lead_schema');
		$pos = $this->findObjects(schemaKey: 'posTransaction_schema');
		$clients = $this->findObjects(schemaKey: 'client_schema');

		$nameByClient = $this->clientNameIndex(clients: $clients);

		$byClient = [];
		foreach ($leads as $lead) {
			$timestamp = $this->leadCloseTimestamp(lead: $lead);
			if (((string)($lead['status'] ?? '')) !== 'won' || $timestamp === null || $timestamp < $startTs || $timestamp > $nowTs) {
				continue;
			}

			$client = (string)($lead['client'] ?? '');
			$byClient[$client] = (($byClient[$client] ?? 0.0) + (float)($lead['value'] ?? 0));
		}

		foreach ($pos as $txn) {
			$timestamp = $this->posRevenueTimestamp(txn: $txn);
			if ($this->isRevenueTransaction(txn: $txn) === false || $timestamp === null || $timestamp < $startTs || $timestamp > $nowTs) {
				continue;
			}

			$client = (string)($txn['client'] ?? ($txn['customer'] ?? ''));
			$byClient[$client] = (($byClient[$client] ?? 0.0) + (float)($txn['total'] ?? 0));
		}

		arsort($byClient);
		$series = [];
		$count = 0;
		foreach ($byClient as $client => $value) {
			if ($count >= self::TOP_CUSTOMERS_LIMIT) {
				break;
			}

			if ((string)$client === '') {
				continue;
			}

			$series[] = ['date' => ($nameByClient[(string)$client] ?? (string)$client), 'value' => round((float)$value, 2)];
			$count++;
		}

		return ['metric' => 'top-customers', 'period' => $period, 'series' => $series];
	}                               

	   
                                                         
   
                                                         
   
                
    
	private function isRevenueTransaction(array $txn): bool {
		return in_array((string)($txn['status'] ?? ''), self::REVENUE_POS_STATUSES, true);
	}                            

	   
                                                          
   
                                                         
   
                    
    
	private function posRevenueTimestamp(array $txn): ?int {
		return $this->extractTimestamp(row: $txn, fields: ['settledAt', 'confirmedAt', 'created', 'createdAt']);
	}                           

	   
                                                
   
                                               
   
                    
    
	private function leadCloseTimestamp(array $lead): ?int {
		return $this->extractTimestamp(row: $lead, fields: ['stageEnteredAt', 'expectedCloseDate', 'created', 'createdAt']);
	}                          

	   
                                                                    
   
                                              
                                     
                                 
                                                   
   
                       
    
	private function bucketKey(?int $timestamp, int $startTs, int $nowTs, int $bucketSize): ?string {
		if ($timestamp === null || $timestamp < $startTs || $timestamp > $nowTs) {
			return null;
		}

		$bucketTs = ($startTs + ((int)floor((($timestamp - $startTs) / $bucketSize)) * $bucketSize));
		return date('Y-m-d', $bucketTs);
	}                 

	   
                                                                      
   
                                                          
   
               
    
	private function bucketSizeForPeriod(string $period): int {
		return match ($period) {
			'week', 'month' => 86400,
			'quarter' => (86400 * 7),
			'year' => (86400 * 30),
			default => 86400,
		};
	}                           

	   
                                                                    
   
                                                
   
                  
    
	private function objectId(array $row): string {
		if (isset($row['@self']) === true && is_array($row['@self']) === true && isset($row['@self']['id']) === true) {
			return (string)$row['@self']['id'];
		}

		if (isset($row['id']) === true) {
			return (string)$row['id'];
		}

		return '';
	}                

	   
                                           
   
                                                                   
   
                                 
    
	private function productCategoryIndex(array $products): array {
		$index = [];
		foreach ($products as $product) {
			$id = $this->objectId(row: $product);
			if ($id !== '') {
				$index[$id] = (string)($product['category'] ?? '');
			}
		}

		return $index;
	}                            

	   
                                         
   
                                                                 
   
                                 
    
	private function clientNameIndex(array $clients): array {
		$index = [];
		foreach ($clients as $client) {
			$id = $this->objectId(row: $client);
			if ($id !== '') {
				$index[$id] = (string)($client['name'] ?? $id);
			}
		}

		return $index;
	}                       

	   
                                                  
   
                                                                              
   
                                 
    
	private function categoryNameIndex(array $categories): array {
		$index = [];
		foreach ($categories as $category) {
			$id = $this->objectId(row: $category);
			if ($id !== '') {
				$index[$id] = (string)($category['name'] ?? $id);
			}
		}

		return $index;
	}                         

	   
                                                                  
   
                                                
                                                                                  
   
                                                                 
    
	private function extractTimestamp(array $row, array $fields): ?int {
		foreach ($fields as $field) {
			if (isset($row[$field]) === false) {
				continue;
			}

			$value = (string)$row[$field];
			if ($value === '') {
				continue;
			}

			$timestamp = strtotime($value);
			if ($timestamp !== false) {
				return $timestamp;
			}
		}

		return null;
	}                        
}           
