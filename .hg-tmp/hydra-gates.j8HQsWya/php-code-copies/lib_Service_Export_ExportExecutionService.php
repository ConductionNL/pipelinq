<?php

   
                                   
  
                                                                              
                                                                               
                                                                              
                                                                            
                                                                             
                 
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                                    
                                               
  
                         
  
                                                 
  
                                                                                
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Export;

use OCA\Pipelinq\AppInfo\Application;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

   
                                                   
  
                                                                               
                                                                             
  
                                                                                
   
class ExportExecutionService {
	   
                                                                     
   
            
    
	private const LOCK_TTL = 3600;

	   
                
   
                                                  
                                                  
                                                               
                                                           
                                                                          
                                                                          
                                                                            
                                              
    
	public function __construct(
		private ExportJobService $jobs,
		private ExportRunService $runs,
		private ExportDataService $data,
		private ExportUploadService $uploads,
		private ExportDestinationService $destinations,
		private SchemaEvolutionService $evolution,
		private ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                 
   
                                                             
                                                                          
                                                                            
                                       
   
                                                           
   
                                                                
   
                                                                                    
                                                                                    
    
	public function executeRun(array $run): array {
		$runId = (string)($run['id'] ?? $run['uuid'] ?? '');
		$jobId = (string)($run['jobId'] ?? '');

		if ($this->acquireLock(jobId: $jobId) === false) {
			$this->logger->info('Pipelinq: export run skipped (overlap)', ['runId' => $runId, 'jobId' => $jobId]);
			return $this->runs->markSkippedOverlap(runId: $runId, reason: 'A run for this job is already executing.');
		}

		try {
			return $this->runLocked(runId: $runId, jobId: $jobId, run: $run);
		} catch (\Throwable $e) {
			$this->logger->error('Pipelinq: export run failed', ['runId' => $runId, 'error' => $e->getMessage()]);
			return $this->runs->completeRun(
				runId: $runId,
				outcome: ['status' => 'failed', 'errorMessage' => $e->getMessage()]
			);
		} finally {
			$this->releaseLock(jobId: $jobId);
		}         
	}                  

	   
                                     
   
                                      
                                      
                                                     
   
                                                   
    
	private function runLocked(string $runId, string $jobId, array $run): array {
		$job = $this->jobs->getJob(id: $jobId);
		$this->runs->markRunning(runId: $runId);

		$destination = $this->destinations->getDestination(id: (string)($job['destinationId'] ?? ''));
		$destination['compression'] = ($destination['compression'] ?? 'none');

		$watermarkFrom = (string)($run['watermarkFrom'] ?? '');
		$files = [];
		$totalRows = 0;
		$watermarkTo = null;

		foreach (($job['sourceSchemas'] ?? []) as $schemaSlug) {
			$slug = (string)$schemaSlug;

			                                                                  
			$jobForExtract = array_merge($job, ['compression' => $destination['compression']]);

			$extractWatermark = null;
			if ($watermarkFrom !== '') {
				$extractWatermark = $watermarkFrom;
			}

			$file = $this->data->extractSchema(
				job: $jobForExtract,
				schemaSlug: $slug,
				watermarkFrom: $extractWatermark,
				rowCap: null
			);

			$files[] = $file;
			$totalRows += (int)$file['rows'];
			if ($file['watermark_to'] !== null
				&& ($watermarkTo === null || strcmp((string)$file['watermark_to'], (string)$watermarkTo) > 0)
			) {
				$watermarkTo = $file['watermark_to'];
			}

			$this->snapshotSchema(runId: $runId, jobId: $jobId, slug: $slug, file: $file);
		}             

		$context = [
			'run_id' => $runId,
			'timestamp' => gmdate('Ymd\THis\Z'),
			'partition' => (string)($job['partitionBy'] ?? ''),
		];

		$upload = $this->uploads->uploadFiles(destination: $destination, files: $files, context: $context);
		$status = $this->mapUploadStatus(uploadStatus: (string)$upload['status']);

		return $this->runs->completeRun(
			runId: $runId,
			outcome: [
				'status' => $status,
				'rowCount' => $totalRows,
				'byteCount' => (int)$upload['byte_count'],
				'fileCount' => (int)$upload['file_count'],
				'manifest' => $upload['manifest'],
				'watermarkTo' => $watermarkTo,
				'destinationAck' => $upload['destination_ack'],
				'errorMessage' => $upload['error_message'],
			]
		);
	}                 

	   
                                                                   
   
                                      
                                      
                                        
                                                                    
   
                
   
                                                                                 
    
	private function snapshotSchema(string $runId, string $jobId, string $slug, array $file): void {
		$columns = ($file['column_definitions'] ?? []);
		$previous = $this->runs->findPreviousSnapshot(jobId: $jobId, schemaName: $slug);
		$changes = $this->evolution->compareToSnapshot(current: $columns, previous: $previous);

		if ($changes !== []) {
			$this->logger->info('Pipelinq: export schema drift detected', ['schema' => $slug, 'changes' => $changes]);
		}

		$this->runs->createSnapshot(
			runId: $runId,
			schemaName: $slug,
			columnDefinitions: $columns,
			comparedToPrevious: $changes
		);
	}                      

	   
                                                                 
   
                                                                 
   
                                  
    
	private function mapUploadStatus(string $uploadStatus): string {
		switch ($uploadStatus) {
			case 'all_succeeded':
				return 'succeeded';
			case 'partial':
				return 'partial';
			case 'all_failed':
			default:
				return 'failed';
		}
	}                       

	   
                                                         
   
                                      
   
                                                 
    
	private function acquireLock(string $jobId): bool {
		if ($jobId === '') {
			return true;
		}

		$cache = $this->cacheFactory->createDistributed(Application::APP_ID . '-export-lock-');
		return $cache->add($jobId, '1', self::LOCK_TTL);
	}                   

	   
                                         
   
                                      
   
                
    
	private function releaseLock(string $jobId): void {
		if ($jobId === '') {
			return;
		}

		$cache = $this->cacheFactory->createDistributed(Application::APP_ID . '-export-lock-');
		$cache->remove($jobId);
	}                   
}           
