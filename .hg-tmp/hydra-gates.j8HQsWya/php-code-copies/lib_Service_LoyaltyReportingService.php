<?php

   
                                    
  
                                                                                 
                                                                              
                                                                            
                                                                          
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                              
                                                              
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;

   
                               
  
                                                                             
                                                                   
                                                                           
                                                                        
                                                                              
                                                                           
                                                                        
                                               
  
                                                              
   
class LoyaltyReportingService {
	   
                
   
                                                       
                                                                     
                                                                 
                                                                           
                                              
                                                                                         
                                                                                
    
	public function __construct(
		private IAppConfig $appConfig,
		private LoyaltyAccountService $accountService,
		private PointsLedgerService $ledgerService,
		private LoyaltyProgrammeService $programmeService,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly AggregationRunner $aggregationRunner,
	) {
	}                   

	   
                                                                 
   
                                                  
                                                          
                                                        
   
                                
   
                                                                  
    
	public function getKpis(string $programmeId, ?string $from = null, ?string $to = null): array {
		$accounts = $this->accountService->listAccountsForProgramme(programmeId: $programmeId, limit: 10000);

		$activeAccounts = 0;
		$tierDistribution = [];
		$outstandingPoints = 0;
		foreach ($accounts as $a) {
			if ((string)($a['status'] ?? '') === 'active') {
				$activeAccounts++;
			}

			$tierId = (string)($a['currentTierId'] ?? 'unassigned');
			$tierDistribution[$tierId] = ($tierDistribution[$tierId] ?? 0) + 1;
			$outstandingPoints += max(0, (int)($a['currentBalance'] ?? 0));
		}

		$credits = $this->ledgerService->getLedgerEntriesForProgramme(
			programmeId: $programmeId,
			type: 'credit',
			from: $from,
			to: $to
		);
		$debits = $this->ledgerService->getLedgerEntriesForProgramme(
			programmeId: $programmeId,
			type: 'debit',
			from: $from,
			to: $to
		);
		$expiries = $this->ledgerService->getLedgerEntriesForProgramme(
			programmeId: $programmeId,
			type: 'expiry',
			from: $from,
			to: $to
		);

		$pointsIssued = (int)array_sum(array_map(fn (array $e): int => (int)($e['count'] ?? 0), $credits));
		$pointsRedeemed = (int)array_sum(array_map(fn (array $e): int => abs((int)($e['count'] ?? 0)), $debits));
		$pointsExpired = (int)array_sum(array_map(fn (array $e): int => abs((int)($e['count'] ?? 0)), $expiries));

		$breakagePercent = 0.0;
		if ($pointsIssued > 0) {
			$breakagePercent = round($pointsExpired / $pointsIssued * 100, 2);
		}

		$redemptionRate = 0.0;
		if ($pointsIssued > 0) {
			$redemptionRate = round($pointsRedeemed / $pointsIssued * 100, 2);
		}

		$programme = $this->programmeService->getProgramme(programmeId: $programmeId);
		$pointValue = (float)($programme['pointValue'] ?? 0.01);
		$estimatedLiability = round($outstandingPoints * $pointValue, 2);
		$programmeCostPercent = $this->estimateProgrammeCostPercent(debits: $debits);

		return [
			'activeAccounts' => $activeAccounts,
			'pointsIssued' => $pointsIssued,
			'pointsRedeemed' => $pointsRedeemed,
			'pointsExpired' => $pointsExpired,
			'breakagePercent' => $breakagePercent,
			'redemptionRate' => $redemptionRate,
			'programmeCostPercent' => $programmeCostPercent,
			'tierDistribution' => $tierDistribution,
			'outstandingPoints' => $outstandingPoints,
			'estimatedLiability' => $estimatedLiability,
			'pointValue' => $pointValue,
			'period' => ['from' => $from, 'to' => $to],
		];
	}               

	   
                                            
   
                                                  
   
                                
   
                                                                  
    
	public function getLiabilitySnapshot(string $programmeId): array {
		$accounts = $this->accountService->listAccountsForProgramme(programmeId: $programmeId, limit: 10000);

		$outstanding = 0;
		foreach ($accounts as $a) {
			$outstanding += max(0, (int)($a['currentBalance'] ?? 0));
		}

		$programme = $this->programmeService->getProgramme(programmeId: $programmeId);
		$pointValue = (float)($programme['pointValue'] ?? 0.01);

		return [
			'outstandingPoints' => $outstanding,
			'estimatedLiability' => round($outstanding * $pointValue, 2),
			'pointValue' => $pointValue,
			'calculationDate' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d'),
		];
	}                            

	   
                                                     
   
                                                  
   
                                            
   
                                                                           
                                                                            
           
   
                                           
    
	public function getTierReport(string $programmeId): array {
		                                                                        
		                                                                    
		                                                                      
		                                                                      
		                                                                    
		                                                                        
		                                                                       
		                                                                         
		                                                                         
		                        
		try {
			[$register, $schema] = $this->accountConfig();
			$query = AggregationQuery::create(
				metric: 'count',
				filter: ['programmeId' => $programmeId],
				groupBy: ['field' => 'currentTierId'],
			);
			$result = $this->getAggregationRunner()->runAdhocByRef(
				registerRef: $register,
				schemaRef: $schema,
				query: $query
			);
		} catch (\Throwable $e) {
			$this->logger->debug('Pipelinq: tier-report aggregation failed; using PHP fallback', ['exception' => $e->getMessage()]);
			return $this->getTierReportPhp(programmeId: $programmeId);
		}

		$byTier = [];
		foreach (($result['groups'] ?? []) as $group) {
			                                                              
			                                                                   
			                                                              
			$key = $group['key'] ?? null;
			$tierId = (string)$key;
			if ($key === null || $key === '') {
				$tierId = 'unassigned';
			}

			$byTier[$tierId] = ($byTier[$tierId] ?? 0) + (int)($group['value'] ?? 0);
		}

		$report = [];
		foreach ($byTier as $tierId => $count) {
			$report[] = ['tierId' => $tierId, 'accountCount' => $count];
		}

		return $report;
	}                     

	   
                                                                             
                                                                          
                                      
   
                                                  
   
                                            
    
	private function getTierReportPhp(string $programmeId): array {
		$accounts = $this->accountService->listAccountsForProgramme(programmeId: $programmeId, limit: 10000);

		$byTier = [];
		foreach ($accounts as $a) {
			$tierId = (string)($a['currentTierId'] ?? 'unassigned');
			$byTier[$tierId] = ($byTier[$tierId] ?? 0) + 1;
		}

		$result = [];
		foreach ($byTier as $tierId => $count) {
			$result[] = ['tierId' => $tierId, 'accountCount' => $count];
		}

		return $result;
	}                        

	   
                                                                                    
   
                                                  
                                                      
   
                                                            
   
                                           
    
	public function getExpiryForecast(string $programmeId, int $days = 30): array {
		$accounts = $this->accountService->listAccountsForProgramme(programmeId: $programmeId, limit: 10000);

		$programme = $this->programmeService->getProgramme(programmeId: $programmeId);
		$policy = $programme['expiryPolicy'] ?? [];
		$type = 'none';
		if (is_array($policy) === true) {
			$type = (string)($policy['type'] ?? 'none');
		}

		if ($type !== 'inactivityMonths') {
			return [
				'points' => 0,
				'accounts' => 0,
				'until' => (new DateTimeImmutable("+{$days} days", new DateTimeZone('UTC')))->format('c'),
			];
		}

		$months = 12;
		if (is_array($policy) === true) {
			$months = (int)($policy['value'] ?? 12);
		}

		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$cutoff = $now->modify('-' . max(0, $months - ((int)round($days / 30))) . ' months');

		$points = 0;
		$count = 0;
		foreach ($accounts as $a) {
			$last = (string)($a['lastActivityDate'] ?? '');
			if ($last === '') {
				continue;
			}

			if ($last < $cutoff->format('c') && (int)($a['currentBalance'] ?? 0) > 0) {
				$points += (int)($a['currentBalance'] ?? 0);
				$count++;
			}
		}

		return [
			'points' => $points,
			'accounts' => $count,
			'until' => $now->modify("+{$days} days")->format('c'),
		];
	}                         

	   
                                                                                                
   
                                                                             
                                                                          
                                                         
   
                                                                                       
   
                 
    
	private function estimateProgrammeCostPercent(array $debits): float {
		if ($debits === []) {
			return 0.0;
		}

		$totalCost = 0.0;
		foreach ($debits as $entry) {
			$source = $entry['sourceDocument'] ?? [];
			if (is_array($source) === false || isset($source['optionId']) === false) {
				continue;
			}

			$option = $this->getOption(optionId: (string)$source['optionId']);
			if ($option === null) {
				continue;
			}

			$totalCost += (float)($option['costBasisEur'] ?? 0);
		}

		                                                                          
		                                                                       
		                                                             
		if ($totalCost <= 0) {
			return 0.0;
		}

		return round(($totalCost / max(0.01, $totalCost / 0.10)) * 100, 2);
	}                                    

	   
                               
   
                                            
   
                                 
    
	private function getOption(string $optionId): ?array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'redemptionOption_schema', '');
		if ($register === '' || $schema === '' || $optionId === '') {
			return null;
		}

		try {
			$object = $this->getObjectService()->find(id: $optionId, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			return null;
		}

		return $this->normalizeOptionResult(object: $object);
	}                 

	   
                                                                
   
                                                                       
                                                          
                                                                        
                                         
   
                                                              
   
                                                                            
                                                                          
    
	private function normalizeOptionResult(mixed $object): ?array {
		if ($object === null) {
			return null;
		}

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

		return null;
	}                             

	   
                          
   
                  
    
	private function getObjectService(): object {
		                                                                 
		                                                              
		return $this->objectService;
	}                        

	   
                                                  
   
                                                                               
                                                                          
                                                                      
                                                                             
                                                         
   
                                          
    
	private function getAggregationRunner(): object {
		return $this->aggregationRunner;
	}                            

	   
                                                                              
   
                                                                             
                                                        
   
                                                                    
   
                                                                           
    
	private function accountConfig(): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'klantLoyaltyAccount_schema', '');
		if ($register === '' || $schema === '') {
			throw new RuntimeException('CustomerLoyaltyAccount register/schema is not configured.');
		}

		return [$register, $schema];
	}                     
}           
