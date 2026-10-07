<?php

   
                            
  
                                                                              
                                                                      
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                        
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                               
  
                                                                              
                                                                  
                                                      
  
                                                                                        
                                                     
   
class ForecastService {
	   
                                                                   
                                                                   
   
               
    
	public const PIPELINE_TARGET_KEY = 'pipeline_target';

	   
                                                         
   
               
    
	public const ACCURACY_GREEN_KEY = 'forecast_accuracy_green';

	   
                                                         
   
               
    
	public const ACCURACY_AMBER_KEY = 'forecast_accuracy_amber';

	   
                                                 
   
              
    
	public const ACCURACY_GREEN_DEFAULT = 0.90;

	   
                                                 
   
              
    
	public const ACCURACY_AMBER_DEFAULT = 0.75;

	   
                
   
                                                       
                                                        
                                                                       
                                              
                                                                                         
    
	public function __construct(
		private IAppConfig $appConfig,
		private QuotaService $quotaService,
		private ForecastRollupService $rollup,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                                                             
   
                                                
                                                         
                                             
   
                                                              
   
                                                                                  
    
	public function computeRollUp(string $ownerId, string $periodId, string $level): array {
		$snapshot = $this->latestSnapshot(ownerId: $ownerId, periodId: $periodId, level: $level);

		$commit = (float)($snapshot['commit_amount'] ?? 0);
		$bestCase = (float)($snapshot['best_case_amount'] ?? 0);
		$pipeline = (float)($snapshot['pipeline_amount'] ?? 0);
		$closedWon = (float)($snapshot['closed_won_amount'] ?? 0);
		$originalCommit = $commit;
		$originalBest = $bestCase;

		$result = [
			'owner_id' => $ownerId,
			'period_id' => $periodId,
			'level' => $level,
			'commit' => $commit,
			'original_commit' => $originalCommit,
			'commit_override_id' => null,
			'commit_override_reason' => null,
			'best_case' => $bestCase,
			'original_best_case' => $originalBest,
			'best_case_override_id' => null,
			'best_case_override_reason' => null,
			'pipeline' => $pipeline,
			'closed_won' => $closedWon,
			'quota' => $this->quotaService->getQuotaAmount(ownerId: $ownerId, periodId: $periodId, level: $level),
			'deal_snapshot_ids' => ($snapshot['deal_snapshot_ids'] ?? []),
			'partial' => (bool)($snapshot['partial'] ?? false),
		];

		$commitOverride = $this->latestOverride(ownerId: $ownerId, periodId: $periodId, level: $level, category: 'commit');
		if ($commitOverride !== null) {
			$result['commit'] = (float)($commitOverride['override_amount'] ?? $commit);
			$result['commit_override_id'] = $this->objectId(object: $commitOverride);
			$result['commit_override_reason'] = (string)($commitOverride['reason'] ?? '');
		}

		$bestOverride = $this->latestOverride(ownerId: $ownerId, periodId: $periodId, level: $level, category: 'best_case');
		if ($bestOverride !== null) {
			$result['best_case'] = (float)($bestOverride['override_amount'] ?? $bestCase);
			$result['best_case_override_id'] = $this->objectId(object: $bestOverride);
			$result['best_case_override_reason'] = (string)($bestOverride['reason'] ?? '');
		}

		return $result;
	}                     

	   
                                                            
   
                                                                              
                                                                      
   
                                                                                    
                                                                                         
                                                            
   
                                                          
   
                                                                                                  
    
	public function computeAccuracyScore(?float $weekOneCommit, ?float $actualClosedWon, bool $periodClosed): ?float {
		if ($periodClosed === false || $weekOneCommit === null || $actualClosedWon === null || $actualClosedWon === 0.0) {
			return null;
		}

		$score = 1.0 - (abs($weekOneCommit - $actualClosedWon) / $actualClosedWon);
		return round($score, 4);
	}                            

	   
                                                                             
   
                                                         
   
                                                           
   
                                                                                  
    
	public function averageAccuracy(array $scores): ?float {
		$values = array_values(array_filter($scores, static fn ($value): bool => is_numeric($value)));
		if ($values === []) {
			return null;
		}

		return round(array_sum($values) / count($values), 4);
	}                       

	   
                                                        
   
                                                                           
                                     
   
                                                                          
   
                                                                       
   
                                                                                  
    
	public function computeTrailingQuartersAccuracy(array $orderedScores): float {
		$values = array_values(array_filter($orderedScores, static fn ($value): bool => is_numeric($value)));
		if (count($values) < 4) {
			return 0.0;
		}

		$window = array_slice($values, 0, 4);
		return round(array_sum($window) / 4, 4);
	}                                       

	   
                                                  
   
                                           
   
                                            
   
                                                                                  
    
	public function accuracyBand(float $score): string {
		$green = $this->appConfig->getValueString(Application::APP_ID, self::ACCURACY_GREEN_KEY, (string)self::ACCURACY_GREEN_DEFAULT);
		$amber = $this->appConfig->getValueString(Application::APP_ID, self::ACCURACY_AMBER_KEY, (string)self::ACCURACY_AMBER_DEFAULT);

		$greenThreshold = self::ACCURACY_GREEN_DEFAULT;
		if ($green !== '') {
			$greenThreshold = (float)$green;
		}

		$amberThreshold = self::ACCURACY_AMBER_DEFAULT;
		if ($amber !== '') {
			$amberThreshold = (float)$amber;
		}

		if ($score > $greenThreshold) {
			return 'green';
		}

		if ($score >= $amberThreshold) {
			return 'amber';
		}

		return 'red';
	}                    

	   
                                                             
   
                                                
                                                         
                                             
   
                                                                               
    
	private function latestSnapshot(string $ownerId, string $periodId, string $level): array {
		                                                                   
		                                                                    
		                                              
		$rows = $this->findObjects(
			schemaKey: 'forecastSnapshot_schema',
			filters: [
				'owner_id' => $ownerId,
				'period_id' => $periodId,
				'level' => $level,
			],
			sort: ['as_of_date' => 'DESC'],
			limit: 1
		);

		if ($rows === []) {
			return $this->rollup->emptyTotals();
		}

		return $rows[0];
	}                      

	   
                                                                      
   
                                                                  
                                                         
                                             
                                                    
   
                                                                   
    
	private function latestOverride(string $ownerId, string $periodId, string $level, string $category): ?array {
		                                                                   
		                                                
		$rows = $this->findObjects(
			schemaKey: 'forecastOverride_schema',
			filters: [
				'override_owner_id' => $ownerId,
				'period_id' => $periodId,
				'level' => $level,
				'category' => $category,
			],
			sort: ['created_at' => 'DESC'],
			limit: 1
		);

		if ($rows === []) {
			return null;
		}

		return $rows[0];
	}                      

	   
                                                               
   
                                                       
                                                                     
                                                                                          
                                                           
   
                                                                            
    
	private function findObjects(string $schemaKey, array $filters, array $sort = [], ?int $limit = null): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
		if ($register === '' || $schema === '') {
			return [];
		}

		$allFilters = array_merge(['register' => $register, 'schema' => $schema], $filters);

		$config = ['filters' => $allFilters];
		if ($sort !== []) {
			$config['sort'] = $sort;
		}

		if ($limit !== null) {
			$config['limit'] = $limit;
		}

		try {
			$results = $this->getObjectService()->findAll(config: $config);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: forecast query failed', ['exception' => $e->getMessage()]);
			return [];
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

	   
                                   
   
                                                        
   
                                             
    
	private function objectId(array $object): ?string {
		$self = $object['@self'] ?? [];
		if (is_array($self) === true) {
			$candidate = $self['uuid'] ?? $self['id'] ?? null;
			if (is_string($candidate) === true && $candidate !== '') {
				return $candidate;
			}
		}

		$candidate = $object['uuid'] ?? $object['id'] ?? null;
		if (is_string($candidate) === true) {
			return $candidate;
		}

		return null;
	}                

	   
                                       
   
                                      
    
	private function getObjectService(): object {
		                                                                 
		                                                              
		return $this->objectService;
	}                        
}           
