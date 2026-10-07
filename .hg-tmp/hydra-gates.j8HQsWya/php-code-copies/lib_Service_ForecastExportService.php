<?php

   
                                  
  
                                                                              
                                                                   
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                              
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                           
  
                                                                        
                                                                           
                                                                        
                                                
  
                                                                                        
                                                     
   
class ForecastExportService {
	   
                       
   
                           
    
	private const CSV_HEADER = ['as_of_date', 'owner_id', 'level', 'commit', 'best_case', 'pipeline', 'closed_won', 'quota'];

	   
                
   
                                                       
                                                                             
                                                                        
                                              
                                                                                         
    
	public function __construct(
		private IAppConfig $appConfig,
		private ForecastService $forecastService,
		private ReportingService $reportingService,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                                
   
                                                 
                                             
                                                      
                                    
                                       
   
                                                                                                   
   
                                                                                                  
    
	public function exportSnapshots(string $periodId, string $level, ?string $ownerId, int $limit, int $offset): array {
		$filters = ['period_id' => $periodId, 'level' => $level];
		if ($ownerId !== null && $ownerId !== '') {
			$filters['owner_id'] = $ownerId;
		}

		                                                                      
		                                                                        
		                                       
		$total = $this->countObjects(schemaKey: 'forecastSnapshot_schema', filters: $filters);
		$page = $this->findObjects(
			schemaKey: 'forecastSnapshot_schema',
			filters: $filters,
			sort: ['as_of_date' => 'ASC'],
			limit: $limit,
			offset: $offset
		);

		$snapshots = [];
		foreach ($page as $row) {
			$snapshots[] = $this->presentSnapshot(row: $row, periodId: $periodId);
		}

		return [
			'snapshots' => $snapshots,
			'total' => $total,
			'limit' => $limit,
			'offset' => $offset,
		];
	}                       

	   
                                           
   
                                                                               
   
                                
   
                                                                                  
    
	public function toCsv(array $snapshots): string {
		$rows = [];
		foreach ($snapshots as $snapshot) {
			$rows[] = [
				(string)($snapshot['as_of_date'] ?? ''),
				(string)($snapshot['owner_id'] ?? ''),
				(string)($snapshot['level'] ?? ''),
				(string)($snapshot['commit'] ?? 0),
				(string)($snapshot['best_case'] ?? 0),
				(string)($snapshot['pipeline'] ?? 0),
				(string)($snapshot['closed_won'] ?? 0),
				(string)($snapshot['quota'] ?? ''),
			];
		}

		return $this->reportingService->generateCsv(headers: self::CSV_HEADER, rows: $rows);
	}             

	   
                                                                          
   
                                                      
                                          
   
                                                        
    
	private function presentSnapshot(array $row, string $periodId): array {
		$self = $row['@self'] ?? [];
		$id = null;
		if (is_array($self) === true) {
			$id = ($self['uuid'] ?? $self['id'] ?? null);
		}

		$level = (string)($row['level'] ?? '');

		$quota = null;
		if (isset($row['quota_amount']) === true) {
			$quota = (float)$row['quota_amount'];
		}

		return [
			'id' => $id,
			'owner_id' => (string)($row['owner_id'] ?? ''),
			'as_of_date' => (string)($row['as_of_date'] ?? ''),
			'level' => $level,
			'commit' => (float)($row['commit_amount'] ?? 0),
			'best_case' => (float)($row['best_case_amount'] ?? 0),
			'pipeline' => (float)($row['pipeline_amount'] ?? 0),
			'closed_won' => (float)($row['closed_won_amount'] ?? 0),
			'quota' => $quota,
			'currency' => (string)($row['currency'] ?? 'EUR'),
			'partial' => (bool)($row['partial'] ?? false),
			'calculation_audit' => $this->buildAudit(row: $row, periodId: $periodId, level: $level),
		];
	}                       

	   
                                                                    
   
                                                                          
                                                                              
   
                                                      
                                          
                                            
   
                                                            
    
	private function buildAudit(array $row, string $periodId, string $level): array {
		$childLevel = $this->childLevel(level: $level);
		if ($childLevel === null) {
			return [];
		}

		$audit = [];
		$children = $this->childOwners(row: $row, level: $level, periodId: $periodId, childLevel: $childLevel);
		foreach ($children as $childId) {
			$rollUp = $this->forecastService->computeRollUp(ownerId: $childId, periodId: $periodId, level: $childLevel);
			$overrideAmount = null;
			if ($rollUp['commit_override_id'] !== null) {
				$overrideAmount = (float)($rollUp['commit'] ?? 0);
			}

			$audit[] = [
				'source_id' => $childId,
				'commit' => (float)($rollUp['commit'] ?? 0),
				'override_amount' => $overrideAmount,
				'override_reason' => $rollUp['commit_override_reason'],
			];
		}

		return $audit;
	}                  

	   
                                                       
   
                                          
   
                                                                 
    
	private function childLevel(string $level): ?string {
		return match ($level) {
			'company' => 'division',
			'division' => 'team',
			'team' => 'rep',
			default => null,
		};
	}                  

	   
                                                           
   
                                                                           
                                                                 
   
                                                         
                                          
                                          
                                              
   
                                                   
    
	private function childOwners(array $row, string $level, string $periodId, string $childLevel): array {
		$childRows = $this->findObjects(
			schemaKey: 'forecastSnapshot_schema',
			filters: [
				'period_id' => $periodId,
				'level' => $childLevel,
			]
		);

		$owners = [];
		foreach ($childRows as $childRow) {
			$owner = (string)($childRow['owner_id'] ?? '');
			if ($owner !== '') {
				$owners[$owner] = true;
			}
		}

		unset($row, $level);
		return array_keys($owners);
	}                   

	   
                                                               
   
                                                       
                                                                     
                                                                                          
                                                           
                                                         
   
                                                                            
    
	private function findObjects(string $schemaKey, array $filters, array $sort = [], ?int $limit = null, ?int $offset = null): array {
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

		if ($offset !== null) {
			$config['offset'] = $offset;
		}

		try {
			$results = $this->getObjectService()->findAll(config: $config);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: forecast export query failed', ['exception' => $e->getMessage()]);
			return [];
		}

		$objects = [];
		foreach (($results ?? []) as $result) {
			$objects[] = $this->toArray(object: $result);
		}

		return $objects;
	}                   

	   
                                                                                
   
                                                       
                                                                     
   
                                                                    
   
                                                                          
    
	private function countObjects(string $schemaKey, array $filters): int {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
		if ($register === '' || $schema === '') {
			return 0;
		}

		$allFilters = array_merge(['register' => $register, 'schema' => $schema], $filters);

		try {
			return $this->getObjectService()->count(config: ['filters' => $allFilters]);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: forecast export count failed', ['exception' => $e->getMessage()]);
			return 0;
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
