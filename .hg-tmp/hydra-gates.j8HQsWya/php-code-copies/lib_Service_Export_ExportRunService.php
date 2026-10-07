<?php

   
                             
  
                                                                               
                                                                           
                                                                               
                               
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                                    
                                               
  
                         
  
                                                 
  
                                                                                
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Export;

use OCP\AppFramework\OCS\OCSNotFoundException;

   
                                                                     
  
                                                                                
   
class ExportRunService extends AbstractExportService {
	   
                                    
   
               
    
	private const RUN_SCHEMA_KEY = 'exportRun_schema';

	   
                                               
   
               
    
	private const SNAPSHOT_SCHEMA_KEY = 'exportSchemaSnapshot_schema';

	   
                                   
   
                                                                  
                                                                                   
   
                                                       
   
                                                                                 
    
	public function createPendingRun(array $job, ?string $watermarkFrom = null): array {
		$run = [
			'jobId' => (string)($job['id'] ?? $job['uuid'] ?? ''),
			'status' => 'pending',
			'modeUsed' => (string)($job['mode'] ?? 'full'),
			'watermarkFrom' => $watermarkFrom,
			'fileManifestJson' => [],
		];

		return $this->saveObjectData(schemaKey: self::RUN_SCHEMA_KEY, data: $run, id: null);
	}                        

	   
                                                             
   
                                      
   
                                         
   
                                                             
                                                                                                                                   
    
	public function getRun(string $runId): array {
		$run = $this->findObjectById(schemaKey: self::RUN_SCHEMA_KEY, id: $runId);
		if ($run === null) {
			throw new OCSNotFoundException('Export run not found.');
		}

		return $run;
	}              

	   
                                                                          
   
                                                                            
   
                                                               
   
                                                                                    
    
	public function listRuns(array $filters = []): array {
		$objectFilters = [];
		if (isset($filters['job_id']) === true && (string)$filters['job_id'] !== '') {
			$objectFilters['jobId'] = (string)$filters['job_id'];
		}

		if (isset($filters['status']) === true && (string)$filters['status'] !== '') {
			$objectFilters['status'] = (string)$filters['status'];
		}

		$runs = $this->findAllObjects(schemaKey: self::RUN_SCHEMA_KEY, filters: $objectFilters);

		$dateFrom = (string)($filters['date_from'] ?? '');
		$dateTo = (string)($filters['date_to'] ?? '');
		if ($dateFrom !== '' || $dateTo !== '') {
			$runs = $this->filterByDate(runs: $runs, dateFrom: $dateFrom, dateTo: $dateTo);
		}

		usort(
			$runs,
			static function (array $a, array $b): int {
				return strcmp((string)($b['startedAt'] ?? ''), (string)($a['startedAt'] ?? ''));
			}
		);

		return $runs;
	}                

	   
                                              
   
                                      
   
                                                 
                                                                                                                                   
    
	public function markRunning(string $runId): array {
		$run = $this->getRun(runId: $runId);
		$run['status'] = 'running';
		$run['startedAt'] = $this->now();

		return $this->saveObjectData(schemaKey: self::RUN_SCHEMA_KEY, data: $run, id: $runId);
	}                   

	   
                                                                     
   
                                      
                                          
   
                                                 
   
                                                                                    
    
	public function markSkippedOverlap(string $runId, string $reason): array {
		$run = $this->getRun(runId: $runId);
		$run['status'] = 'skipped_overlap';
		$run['endedAt'] = $this->now();
		$run['errorMessage'] = $reason;

		return $this->saveObjectData(schemaKey: self::RUN_SCHEMA_KEY, data: $run, id: $runId);
	}                          

	   
                                                                      
   
                                      
                                                                                
                                                                                             
   
                                                   
   
                                                                                    
    
	public function completeRun(string $runId, array $outcome): array {
		$run = $this->getRun(runId: $runId);

		$run['status'] = (string)($outcome['status'] ?? 'failed');
		$run['endedAt'] = $this->now();
		$run['rowCount'] = (int)($outcome['rowCount'] ?? 0);
		$run['byteCount'] = (int)($outcome['byteCount'] ?? 0);
		$run['fileCount'] = (int)($outcome['fileCount'] ?? 0);
		$run['fileManifestJson'] = ($outcome['manifest'] ?? []);

		if (array_key_exists('watermarkTo', $outcome) === true) {
			$run['watermarkTo'] = $outcome['watermarkTo'];
		}

		if (array_key_exists('destinationAck', $outcome) === true) {
			$run['destinationAck'] = $outcome['destinationAck'];
		}

		if (array_key_exists('errorMessage', $outcome) === true) {
			$run['errorMessage'] = $outcome['errorMessage'];
		}

		return $this->saveObjectData(schemaKey: self::RUN_SCHEMA_KEY, data: $run, id: $runId);
	}                   

	   
                                                      
   
                                      
                                                       
                                                                           
                                                                          
   
                                                      
   
                                                                                 
    
	public function createSnapshot(
		string $runId,
		string $schemaName,
		array $columnDefinitions,
		array $comparedToPrevious = [],
	): array {
		$snapshot = [
			'runId' => $runId,
			'pipelinqSchemaName' => $schemaName,
			'columnDefinitionsJson' => $columnDefinitions,
			'comparedToPrevious' => $comparedToPrevious,
		];

		return $this->saveObjectData(schemaKey: self::SNAPSHOT_SCHEMA_KEY, data: $snapshot, id: null);
	}                      

	   
                                        
   
                                      
   
                                                           
                                                                                                                                   
    
	public function listSnapshots(string $runId): array {
		return $this->findAllObjects(
			schemaKey: self::SNAPSHOT_SCHEMA_KEY,
			filters: ['runId' => $runId]
		);
	}                     

	   
                                                                          
   
                                                                          
                                      
   
                                      
                                              
   
                                                                     
   
                                                                                 
    
	public function findPreviousSnapshot(string $jobId, string $schemaName): ?array {
		$runs = $this->listRuns(filters: ['job_id' => $jobId]);

		foreach ($runs as $run) {
			$runId = (string)($run['id'] ?? $run['uuid'] ?? '');
			if ($runId === '') {
				continue;
			}

			foreach ($this->listSnapshots(runId: $runId) as $snapshot) {
				if ((string)($snapshot['pipelinqSchemaName'] ?? '') === $schemaName) {
					return $snapshot;
				}
			}
		}

		return null;
	}                            

	   
                                                                        
   
                                      
   
                                                                      
   
                                                                                    
    
	public function lastSucceededRun(string $jobId): ?array {
		$runs = $this->listRuns(filters: ['job_id' => $jobId, 'status' => 'succeeded']);
		if ($runs === []) {
			return null;
		}

		return $runs[0];
	}                        

	   
                                                                             
   
                                                           
                                                            
                                                          
   
                                                               
    
	private function filterByDate(array $runs, string $dateFrom, string $dateTo): array {
		$filtered = [];
		foreach ($runs as $run) {
			$started = (string)($run['startedAt'] ?? '');
			if ($dateFrom !== '' && ($started === '' || strcmp($started, $dateFrom) < 0)) {
				continue;
			}

			if ($dateTo !== '' && ($started === '' || strcmp($started, $dateTo) > 0)) {
				continue;
			}

			$filtered[] = $run;
		}

		return $filtered;
	}                    
}           
