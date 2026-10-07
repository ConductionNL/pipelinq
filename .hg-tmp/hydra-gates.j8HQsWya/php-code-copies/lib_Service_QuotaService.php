<?php

   
                         
  
                                                                             
                        
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                              
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                          
  
                                                                               
                                                                               
  
                                                                                     
                                                
   
class QuotaService {
	   
                                                                        
   
               
    
	public const AT_RISK_PERCENT_KEY = 'forecast_at_risk_percent';

	   
                                                            
   
               
    
	public const AT_RISK_DAYS_KEY = 'forecast_at_risk_days';

	   
                                               
   
            
    
	public const AT_RISK_PERCENT_DEFAULT = 90;

	   
                                                       
   
            
    
	public const AT_RISK_DAYS_DEFAULT = 30;

	   
                                                                 
   
              
    
	public const BEST_CASE_WEIGHT = 0.5;

	   
                
   
                                                       
                                              
                                                                                         
    
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                                  
   
                                                
                                                         
                                             
   
                                                                         
   
                                                                                  
    
	public function getQuota(string $ownerId, string $periodId, string $level): ?array {
		$rows = $this->findObjects(
			schemaKey: 'salesQuota_schema',
			filters: [
				'owner_id' => $ownerId,
				'period_id' => $periodId,
				'level' => $level,
			]
		);

		if ($rows === []) {
			return null;
		}

		return $rows[0];
	}                

	   
                                                       
   
                                                
                                                         
                                             
   
                                                            
                                                                                      
                                                 
    
	public function getQuotaAmount(string $ownerId, string $periodId, string $level): ?float {
		$quota = $this->getQuota(ownerId: $ownerId, periodId: $periodId, level: $level);
		if ($quota === null || isset($quota['quota_amount']) === false) {
			return null;
		}

		return (float)$quota['quota_amount'];
	}                      

	   
                                                                        
   
                                                  
                                                              
                                                
   
                                           
   
                                                                                  
    
	public function projectedAttainment(float $closedWon, float $commit, float $bestCase): float {
		return round($closedWon + $commit + (self::BEST_CASE_WEIGHT * $bestCase), 2);
	}                           

	   
                                                
   
                                                                              
                                                                            
                           
   
                                                     
                                         
                                                           
   
                                                   
   
                                                                                                                  
    
	public function isAtRisk(float $projected, float $quota, int $daysRemaining): bool {
		if ($quota <= 0) {
			return false;
		}

		$thresholdPercent = $this->getAtRiskPercent();
		$thresholdDays = $this->getAtRiskDays();

		$attainmentPercent = ($projected / $quota) * 100;
		return $attainmentPercent < $thresholdPercent && $daysRemaining < $thresholdDays;
	}                

	   
                                                          
   
                                                                         
                                                                        
             
   
                                                         
                                                                                   
   
                                                                               
   
                                                                               
    
	public function validateQuotaHierarchy(string $periodId, array $teamMembers): array {
		$report = [];
		foreach ($teamMembers as $teamId => $repIds) {
			$teamQuota = $this->getQuotaAmount(ownerId: (string)$teamId, periodId: $periodId, level: 'team') ?? 0.0;
			$repSum = 0.0;
			foreach ($repIds as $repId) {
				$repSum += $this->getQuotaAmount(ownerId: $repId, periodId: $periodId, level: 'rep') ?? 0.0;
			}

			$variance = 0.0;
			if ($teamQuota > 0) {
				$variance = round((($repSum - $teamQuota) / $teamQuota) * 100, 1);
			}

			$report[] = [
				'team_id' => (string)$teamId,
				'team_quota' => $teamQuota,
				'rep_quotas_sum' => round($repSum, 2),
				'variance_percent' => $variance,
			];
		}

		return $report;
	}                              

	   
                                                       
   
                                      
                                                                                      
                                                 
    
	public function getAtRiskPercent(): int {
		$value = $this->appConfig->getValueInt(Application::APP_ID, self::AT_RISK_PERCENT_KEY, self::AT_RISK_PERCENT_DEFAULT);
		if ($value > 0) {
			return $value;
		}

		return self::AT_RISK_PERCENT_DEFAULT;
	}                        

	   
                                                 
   
                                   
                                                                                      
                                                 
    
	public function getAtRiskDays(): int {
		$value = $this->appConfig->getValueInt(Application::APP_ID, self::AT_RISK_DAYS_KEY, self::AT_RISK_DAYS_DEFAULT);
		if ($value > 0) {
			return $value;
		}

		return self::AT_RISK_DAYS_DEFAULT;
	}                     

	   
                                                               
   
                                                       
                                                                     
   
                                                                            
    
	private function findObjects(string $schemaKey, array $filters): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
		if ($register === '' || $schema === '') {
			return [];
		}

		$allFilters = array_merge(['register' => $register, 'schema' => $schema], $filters);

		try {
			$results = $this->getObjectService()->findAll(config: ['filters' => $allFilters]);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: quota query failed', ['exception' => $e->getMessage()]);
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

	   
                                       
   
                                      
    
	private function getObjectService(): object {
		                                                                 
		                                                              
		return $this->objectService;
	}                        
}           
