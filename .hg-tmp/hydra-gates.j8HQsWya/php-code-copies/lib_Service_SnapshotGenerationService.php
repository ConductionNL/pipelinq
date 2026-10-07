<?php

   
                                      
  
                                                                               
                                                                          
                                                                  
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                           
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                     
  
                                                                           
                                                                               
                                                                            
  
                                                                           
                                                       
  
                                                                              
                                                                          
                                                                                  
                                                                                     
  
                                                                         
   
class SnapshotGenerationService {
	   
                                                                           
   
               
    
	public const TEAMS_KEY = 'forecast_team_groups';

	   
                                                   
   
               
    
	public const DIVISION_MAP_KEY = 'forecast_division_map';

	   
                                       
   
               
    
	public const COMPANY_OWNER = 'company';

	   
                
   
                                                       
                                                            
                                                                       
                                                                                
                                                                 
                                                        
                                                                  
                                              
                                                                                         
    
	public function __construct(
		private IAppConfig $appConfig,
		private IGroupManager $groupManager,
		private ForecastRollupService $rollup,
		private ExchangeRateService $exchangeRate,
		private FiscalPeriodService $period,
		private QuotaService $quotaService,
		private NotificationService $notifier,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                                       
   
                                                                              
   
                                                                             
   
                                                                                  
    
	public function generate(?DateTimeImmutable $asOf = null): array {
		$asOf = $asOf ?? new DateTimeImmutable();
		$periodId = $this->period->currentPeriodId($asOf);
		$asOfDate = $asOf->format('Y-m-d');

		$hierarchy = $this->resolveHierarchy();
		$dealsByRep = $this->fetchDealsByRep();

		$snapshots = $this->buildSnapshots(
			periodId: $periodId,
			asOfDate: $asOfDate,
			hierarchy: $hierarchy,
			dealsByRep: $dealsByRep
		);

		$errors = [];
		$persisted = 0;
		foreach ($snapshots as $snapshot) {
			try {
				$this->persistSnapshot(snapshot: $snapshot);
				$persisted++;
			} catch (\Throwable $e) {
				$errors[] = sprintf('%s/%s: %s', $snapshot['level'], $snapshot['owner_id'], $e->getMessage());
				$this->logger->error(
					'Pipelinq: failed to persist forecast snapshot',
					[
						'level' => $snapshot['level'],
						'owner_id' => $snapshot['owner_id'],
						'exception' => $e->getMessage(),
					]
				);
			}
		}

		if ($errors !== []) {
			$this->notifyAdmin(periodId: $periodId, errors: $errors);
		}

		$hierarchyReport = $this->quotaService->validateQuotaHierarchy(
			periodId: $periodId,
			teamMembers: ($hierarchy['teams'] ?? [])
		);
		foreach ($hierarchyReport as $row) {
			if (abs((float)($row['variance_percent'] ?? 0.0)) > 0.0) {
				$this->logger->info(
					'Pipelinq: forecast quota hierarchy variance',
					[
						'period_id' => $periodId,
						'team_id' => $row['team_id'],
						'team_quota' => $row['team_quota'],
						'rep_quotas_sum' => $row['rep_quotas_sum'],
						'variance_percent' => $row['variance_percent'],
					]
				);
			}
		}

		return [
			'period_id' => $periodId,
			'as_of' => $asOfDate,
			'generated' => $persisted,
			'errors' => $errors,
			'hierarchy_report' => $hierarchyReport,
		];
	}                

	   
                                                                     
   
                                                                        
                                                               
   
                                          
                                                  
                                                                                           
                                                                                             
   
                                                                  
   
                                                                                  
    
	public function buildSnapshots(string $periodId, string $asOfDate, array $hierarchy, array $dealsByRep): array {
		$currency = $this->exchangeRate->getReportingCurrency();
		$teams = $hierarchy['teams'] ?? [];
		$divisions = $hierarchy['divisions'] ?? [];

		$snapshots = [];
		$repTotals = [];

		             
		foreach ($teams as $repIds) {
			foreach ($repIds as $repId) {
				$bucket = $this->rollup->bucketDeals($dealsByRep[$repId] ?? []);
				$repTotals[$repId] = $bucket['totals'];
				$snapshots[] = $this->snapshotRecord(
					periodId: $periodId,
					asOfDate: $asOfDate,
					ownerId: $repId,
					level: 'rep',
					totals: $bucket['totals'],
					currency: $currency,
					dealIds: $bucket['deal_ids'],
					partial: false,
					missing: []
				);
			}
		}

		              
		$teamTotals = [];
		foreach ($teams as $teamId => $repIds) {
			$collected = $this->collectChildTotals(ids: $repIds, sourceTotals: $repTotals);
			$totals = $this->rollup->sumChildTotals($collected['children']);
			$teamTotals[$teamId] = $totals;
			$snapshots[] = $this->snapshotRecord(
				periodId: $periodId,
				asOfDate: $asOfDate,
				ownerId: (string)$teamId,
				level: 'team',
				totals: $totals,
				currency: $currency,
				dealIds: [],
				partial: ($collected['missing'] !== []),
				missing: $collected['missing']
			);
		}             

		                  
		$divisionTotals = [];
		foreach ($divisions as $divisionId => $teamIds) {
			$collected = $this->collectChildTotals(ids: $teamIds, sourceTotals: $teamTotals);
			$totals = $this->rollup->sumChildTotals($collected['children']);
			$divisionTotals[$divisionId] = $totals;
			$snapshots[] = $this->snapshotRecord(
				periodId: $periodId,
				asOfDate: $asOfDate,
				ownerId: (string)$divisionId,
				level: 'division',
				totals: $totals,
				currency: $currency,
				dealIds: [],
				partial: ($collected['missing'] !== []),
				missing: $collected['missing']
			);
		}             

		                                                                        
		$companyChildren = array_values($teamTotals);
		if ($divisionTotals !== []) {
			$companyChildren = array_values($divisionTotals);
		}

		$snapshots[] = $this->snapshotRecord(
			periodId: $periodId,
			asOfDate: $asOfDate,
			ownerId: self::COMPANY_OWNER,
			level: 'company',
			totals: $this->rollup->sumChildTotals($companyChildren),
			currency: $currency,
			dealIds: [],
			partial: false,
			missing: []
		);

		return $snapshots;
	}                      

	   
                                                                         
   
                                                        
                                                                                      
   
                                                                                          
    
	private function collectChildTotals(array $ids, array $sourceTotals): array {
		$children = [];
		$missing = [];
		foreach ($ids as $id) {
			if (isset($sourceTotals[$id]) === false) {
				$missing[] = $id;
				continue;
			}

			$children[] = $sourceTotals[$id];
		}

		return ['children' => $children, 'missing' => $missing];
	}                          

	   
                                      
   
                                          
                                         
                                        
                                   
                                                         
                                                   
                                                             
                                                     
                                                         
   
                                                     
    
	private function snapshotRecord(
		string $periodId,
		string $asOfDate,
		string $ownerId,
		string $level,
		array $totals,
		string $currency,
		array $dealIds,
		bool $partial,
		array $missing,
	): array {
		$quota = $this->quotaService->getQuotaAmount(ownerId: $ownerId, periodId: $periodId, level: $level);
		$reference = new DateTimeImmutable($asOfDate);
		$daysRemaining = $this->period->daysRemaining(periodId: $periodId, now: $reference);
		$periodClosed = $this->period->isClosed(periodId: $periodId, now: $reference);
		$projected = $this->quotaService->projectedAttainment(
			closedWon: (float)$totals['closed_won_amount'],
			commit: (float)$totals['commit_amount'],
			bestCase: (float)$totals['best_case_amount']
		);
		$atRisk = false;
		if ($quota !== null && $quota > 0) {
			$atRisk = $this->quotaService->isAtRisk(projected: $projected, quota: $quota, daysRemaining: $daysRemaining);
		}

		return [
			'period_id' => $periodId,
			'as_of_date' => $asOfDate,
			'owner_id' => $ownerId,
			'level' => $level,
			'commit_amount' => $totals['commit_amount'],
			'best_case_amount' => $totals['best_case_amount'],
			'pipeline_amount' => $totals['pipeline_amount'],
			'closed_won_amount' => $totals['closed_won_amount'],
			'quota_amount' => $quota,
			'currency' => $currency,
			'deal_snapshot_ids' => $dealIds,
			'partial' => $partial,
			'missing_reps' => $missing,
			'days_remaining' => $daysRemaining,
			'period_closed' => $periodClosed,
			'at_risk' => $atRisk,
		];
	}                      

	   
                                                                    
   
                                                                                 
    
	private function resolveHierarchy(): array {
		$teamsRaw = $this->appConfig->getValueString(Application::APP_ID, self::TEAMS_KEY, '');
		$teamIds = array_values(array_filter(array_map('trim', explode(',', $teamsRaw)), static fn (string $t): bool => $t !== ''));

		$teams = [];
		foreach ($teamIds as $teamId) {
			$group = $this->groupManager->get($teamId);
			$members = [];
			if ($group !== null) {
				foreach ($group->getUsers() as $user) {
					$members[] = $user->getUID();
				}
			}

			$teams[$teamId] = $members;
		}

		$divisions = [];
		$mapRaw = $this->appConfig->getValueString(Application::APP_ID, self::DIVISION_MAP_KEY, '');
		if ($mapRaw !== '') {
			$decoded = json_decode($mapRaw, true);
			if (is_array($decoded) === true) {
				foreach ($decoded as $teamId => $divisionId) {
					$divisions[(string)$divisionId][] = (string)$teamId;
				}
			}
		}

		return ['teams' => $teams, 'divisions' => $divisions];
	}                        

	   
                                                   
   
                                                                                  
    
	private function fetchDealsByRep(): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'lead_schema', '');
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$results = $this->getObjectService()->findAll(
				config: [
					'filters' => ['register' => $register, 'schema' => $schema],
				]
			);
		} catch (\Throwable $e) {
			$this->logger->error('Pipelinq: snapshot deal fetch failed', ['exception' => $e->getMessage()]);
			return [];
		}

		$byRep = [];
		foreach (($results ?? []) as $result) {
			$data = $this->toArray(object: $result);
			$rep = (string)($data['assignee'] ?? '');
			if ($rep === '') {
				continue;
			}

			$byRep[$rep][] = $data;
		}

		return $byRep;
	}                       

	   
                                              
   
                                                              
   
                
    
	private function persistSnapshot(array $snapshot): void {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'forecastSnapshot_schema', '');
		if ($register === '' || $schema === '') {
			throw new RuntimeException('Forecast snapshot schema is not configured.');
		}

		$this->getObjectService()->saveObject(
			object: $snapshot,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: null
		);
	}                       

	   
                                                              
   
                                          
                                                      
   
                
    
	private function notifyAdmin(string $periodId, array $errors): void {
		try {
			$adminGroup = $this->groupManager->get('admin');
			if ($adminGroup === null) {
				return;
			}

			foreach ($adminGroup->getUsers() as $admin) {
				$this->notifier->sendNotification(
					userId: $admin->getUID(),
					subject: 'forecast_snapshot_partial_failure',
					parameters: [
						'period' => $periodId,
						'errors' => implode('; ', $errors),
					]
				);
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: failed to notify admin of snapshot failures',
				[
					'exception' => $e->getMessage(),
				]
			);
		}         
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
}           
