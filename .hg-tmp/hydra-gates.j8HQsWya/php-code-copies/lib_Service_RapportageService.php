<?php

   
                              
  
                                                                       
                                                                      
                                                           
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                            
  
                                                                    
                                    
  
                                               
                                               
                                               
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                          
  
                                                                         
                                                                       
                                                                        
                                                            
  
                                                                                       
                                                                               
                                                                              
                                                                           
                                           
  
                                               
   
class RapportageService {
	   
                
   
                                                                        
                                                             
                                                                                         
    
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                                                          
   
                                                                                    
   
                                                                                                 
   
                                                
                                                                                             
    
	public function getStageValues(?string $pipelineId = null): array {
		$leads = $this->fetchLeads();
		$buckets = [];

		foreach ($leads as $lead) {
			if ($pipelineId !== null && $pipelineId !== '' && (string)($lead['pipeline'] ?? '') !== $pipelineId) {
				continue;
			}

			$stage = (string)($lead['stage'] ?? '');
			if ($stage === '') {
				continue;
			}

			if (isset($buckets[$stage]) === false) {
				$buckets[$stage] = ['stage' => $stage, 'count' => 0, 'totalValue' => 0.0, 'weightedValue' => 0.0];
			}

			$buckets[$stage]['count']++;
			$buckets[$stage]['totalValue'] += (float)($lead['value'] ?? 0);
			$buckets[$stage]['weightedValue'] += $this->winChanceWeightedValue(lead: $lead);
		}

		return array_values($buckets);
	}                      

	   
                                              
   
                                                                          
                                                                           
                                                                       
                                                                          
                                                
   
                                                   
   
                                     
   
                                                                                             
    
	private function winChanceWeightedValue(array $lead): float {
		$score = ($lead['qualificationScore'] ?? null);
		if (is_numeric($score) === false) {
			return 0.0;
		}

		return ((float)($lead['value'] ?? 0) * (max(0.0, min(100.0, (float)$score)) / 100.0));
	}                              

	   
                                                                            
   
                                                                                   
                                                                                 
   
                                                                                                              
   
                                                
    
	public function getSourcePerformance(?string $dateFrom = null, ?string $dateTo = null): array {
		$leads = $this->fetchLeads();
		$byCreated = $this->filterByCreated(leads: $leads, from: $dateFrom, to: $dateTo);

		$buckets = [];
		foreach ($byCreated as $lead) {
			$source = (string)($lead['source'] ?? 'unknown');
			if ($source === '') {
				$source = 'unknown';
			}

			if (isset($buckets[$source]) === false) {
				$buckets[$source] = ['source' => $source, 'total' => 0, 'won' => 0, 'wonValueSum' => 0.0];
			}

			$buckets[$source]['total']++;
			$status = (string)($lead['status'] ?? 'open');
			if ($status === 'won') {
				$buckets[$source]['won']++;
				$buckets[$source]['wonValueSum'] += (float)($lead['value'] ?? 0);
			}
		}

		$result = [];
		foreach ($buckets as $row) {
			$conversion = round(($row['won'] / $row['total']) * 100.0, 1);
			$avgWon = 0.0;

			if ($row['won'] > 0) {
				$avgWon = round($row['wonValueSum'] / $row['won'], 2);
			}

			$result[] = [
				'source' => $row['source'],
				'total' => $row['total'],
				'won' => $row['won'],
				'conversionRate' => $conversion,
				'avgWonValue' => $avgWon,
			];
		}

		return $result;
	}                            

	   
                                                                      
   
                                                                    
   
                                                                            
   
                                                
    
	public function getAgingBuckets(): array {
		$leads = $this->fetchLeads();
		$now = time();

		$buckets = [
			'0-7d' => ['bucket' => '0-7d',   'count' => 0, 'totalValue' => 0.0],
			'8-14d' => ['bucket' => '8-14d',  'count' => 0, 'totalValue' => 0.0],
			'15-30d' => ['bucket' => '15-30d', 'count' => 0, 'totalValue' => 0.0],
			'30d+' => ['bucket' => '30d+',   'count' => 0, 'totalValue' => 0.0],
		];

		foreach ($leads as $lead) {
			$status = (string)($lead['status'] ?? 'open');
			if ($status === 'won' || $status === 'lost') {
				continue;
			}

			$modified = $this->extractTimestamp(lead: $lead, key: '_dateModified');
			if ($modified === null) {
				continue;
			}

			$days = (int)floor(($now - $modified) / 86400);
			if ($days < 0) {
				$days = 0;
			}

			$key = '0-7d';
			if ($days > 30) {
				$key = '30d+';
			} elseif ($days > 14) {
				$key = '15-30d';
			} elseif ($days > 7) {
				$key = '8-14d';
			}

			$buckets[$key]['count']++;
			$buckets[$key]['totalValue'] += (float)($lead['value'] ?? 0);
		}             

		return array_values($buckets);
	}                       

	   
                                                                     
   
                                                               
                                                             
   
                                                                                                                          
   
                                                
    
	public function getWinLossAnalysis(?string $dateFrom = null, ?string $dateTo = null): array {
		$leads = $this->fetchLeads();
		$closed = [];
		foreach ($leads as $lead) {
			$status = (string)($lead['status'] ?? '');
			if ($status === 'won' || $status === 'lost') {
				$closed[] = $lead;
			}
		}

		$closed = $this->filterByCreated(leads: $closed, from: $dateFrom, to: $dateTo);
		$totals = $this->accumulateWinLossTotals(leads: $closed);

		return $this->buildWinLossSummary(totals: $totals);
	}                          

	   
                                                                                      
   
                                                                               
   
                                                                                                                   
    
	private function accumulateWinLossTotals(array $leads): array {
		$wonCount = 0;
		$lostCount = 0;
		$wonValueSum = 0.0;
		$lostValueSum = 0.0;
		$daysSum = 0.0;
		$daysCount = 0;

		foreach ($leads as $lead) {
			$status = (string)($lead['status'] ?? '');
			$value = (float)($lead['value'] ?? 0);

			if ($status === 'won') {
				$wonCount++;
				$wonValueSum += $value;
			}

			if ($status !== 'won') {
				$lostCount++;
				$lostValueSum += $value;
			}

			$created = $this->extractTimestamp(lead: $lead, key: '_dateCreated');
			$modified = $this->extractTimestamp(lead: $lead, key: '_dateModified');
			if ($created !== null && $modified !== null && $modified >= $created) {
				$daysSum += (($modified - $created) / 86400);
				$daysCount++;
			}
		}             

		return [
			'wonCount' => $wonCount,
			'lostCount' => $lostCount,
			'wonValueSum' => $wonValueSum,
			'lostValueSum' => $lostValueSum,
			'daysSum' => $daysSum,
			'daysCount' => $daysCount,
		];
	}                               

	   
                                                                   
   
                                                                                                                                                  
   
                                                                                                                          
    
	private function buildWinLossSummary(array $totals): array {
		$wonCount = $totals['wonCount'];
		$lostCount = $totals['lostCount'];
		$total = ($wonCount + $lostCount);

		$winRate = 0.0;
		if ($total > 0) {
			$winRate = round(($wonCount / $total) * 100.0, 1);
		}

		$avgWon = 0.0;
		if ($wonCount > 0) {
			$avgWon = round($totals['wonValueSum'] / $wonCount, 2);
		}

		$avgLost = 0.0;
		if ($lostCount > 0) {
			$avgLost = round($totals['lostValueSum'] / $lostCount, 2);
		}

		$avgDays = 0.0;
		if ($totals['daysCount'] > 0) {
			$avgDays = round($totals['daysSum'] / $totals['daysCount'], 1);
		}

		return [
			'wonCount' => $wonCount,
			'lostCount' => $lostCount,
			'winRate' => $winRate,
			'avgWonValue' => $avgWon,
			'avgLostValue' => $avgLost,
			'avgDaysToClose' => $avgDays,
		];
	}                           

	   
                                                              
   
                                                             
                                                              
                                                            
   
                                            
    
	private function filterByCreated(array $leads, ?string $from, ?string $to): array {
		if (($from === null || $from === '') && ($to === null || $to === '')) {
			return $leads;
		}

		$fromTs = $this->parseDateBound(value: $from);
		$toTs = $this->parseDateBound(value: $to);

		$result = [];
		foreach ($leads as $lead) {
			if ($this->isWithinCreatedBounds(lead: $lead, fromTs: $fromTs, toTs: $toTs) === true) {
				$result[] = $lead;
			}
		}

		return $result;
	}                       

	   
                                                                
   
                                                                        
   
                                                                           
    
	private function parseDateBound(?string $value): ?int {
		if ($value === null || $value === '') {
			return null;
		}

		$parsed = strtotime($value);
		if ($parsed === false) {
			return null;
		}

		return $parsed;
	}                      

	   
                                                                                
   
                                                   
                                                             
                                                           
   
                
    
	private function isWithinCreatedBounds(array $lead, ?int $fromTs, ?int $toTs): bool {
		$created = $this->extractTimestamp(lead: $lead, key: '_dateCreated');
		if ($created === null) {
			return false;
		}

		if ($fromTs !== null && $created < $fromTs) {
			return false;
		}

		if ($toTs !== null && $created > $toTs) {
			return false;
		}

		return true;
	}                             

	   
                                                                    
                                            
   
                                                   
                                         
   
                    
    
	private function extractTimestamp(array $lead, string $key): ?int {
		$candidates = [$lead[$key] ?? null];

		$self = $lead['@self'] ?? null;
		if (is_array($self) === true) {
			if ($key === '_dateModified') {
				$candidates[] = $self['updated'] ?? null;
			} elseif ($key === '_dateCreated') {
				$candidates[] = $self['created'] ?? null;
			}
		}

		foreach ($candidates as $candidate) {
			if (is_string($candidate) === true && $candidate !== '') {
				$timestamp = strtotime($candidate);
				if ($timestamp !== false) {
					return $timestamp;
				}
			} elseif (is_int($candidate) === true) {
				return $candidate;
			}
		}

		return null;
	}                        

	   
                                                                     
                                                                       
   
                                            
    
	private function fetchLeads(): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'lead_schema', '');
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$service = $this->getObjectService();
			$results = $service->findAll(config: ['filters' => ['register' => $register, 'schema' => $schema]]);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: rapportage lead fetch failed', ['exception' => $e->getMessage()]);
			return [];
		}

		$leads = [];
		foreach (($results ?? []) as $result) {
			$leads[] = $this->toArray(object: $result);
		}

		return $leads;
	}                  

	   
                                                              
   
                  
    
	private function getObjectService(): object {
		                                                                 
		                                                              
		return $this->objectService;

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
}           
