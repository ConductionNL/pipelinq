<?php

   
                             
  
                                                                               
                                                                           
                                                                            
                                                           
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                                    
                                               
  
                         
  
                                                 
  
                                                                                
                                                                                
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Export;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

   
                                                                  
  
                                                                         
                                                                            
  
                                                                                
   
class ExportJobService extends AbstractExportService {
	   
                                    
   
               
    
	private const SCHEMA_KEY = 'exportJob_schema';

	   
                
   
                                                          
                                                
                                                                                     
                                                                          
                                                               
                                                      
                                              
    
	public function __construct(
		ContainerInterface $container,
		IAppConfig $appConfig,
		ObjectServiceInterface $objectService,
		private ExportDestinationService $destinations,
		private ExportDataService $data,
		private CronExpressionHelper $cron,
		private LoggerInterface $logger,
	) {
		parent::__construct(
			container: $container,
			appConfig: $appConfig,
			objectService: $objectService
		);
	}                   

	   
                         
   
                                                      
   
                                                                                 
    
	public function listJobs(): array {
		return $this->findAllObjects(schemaKey: self::SCHEMA_KEY);
	}                

	   
                    
   
                                   
   
                                         
   
                                             
                                                                                                                                   
    
	public function getJob(string $id): array {
		$job = $this->findObjectById(schemaKey: self::SCHEMA_KEY, id: $id);
		if ($job === null) {
			throw new OCSNotFoundException('Export job not found.');
		}

		return $job;
	}              

	   
                                                          
   
                                                     
                                                
   
                                                 
   
                                                         
   
                                                                                    
    
	public function createJob(array $data, string $userId): array {
		$this->validateJob(data: $data);

		$data = $this->sanitize(data: $data);
		$data['enabled'] = false;
		$data['createdBy'] = $userId;
		$data['createdAt'] = $this->now();
		$data['scheduleCron'] = $this->cron->normalize(expression: (string)($data['scheduleCron'] ?? ''));

		return $this->saveObjectData(schemaKey: self::SCHEMA_KEY, data: $data, id: null);
	}                 

	   
                                                             
   
                                   
                                                     
   
                                                 
   
                                                         
                                             
                                                                                                                                   
    
	public function updateJob(string $id, array $data): array {
		$existing = $this->getJob(id: $id);
		$merged = array_merge($existing, $this->sanitize(data: $data));
		$this->validateJob(data: $merged);

		$merged['enabled'] = false;
		$merged['scheduleCron'] = $this->cron->normalize(expression: (string)($merged['scheduleCron'] ?? ''));

		return $this->saveObjectData(schemaKey: self::SCHEMA_KEY, data: $merged, id: $id);
	}                 

	   
                 
   
                                   
   
                
   
                                             
                                                                                                                                   
    
	public function deleteJob(string $id): void {
		$this->getJob(id: $id);
		$this->deleteObjectById(schemaKey: self::SCHEMA_KEY, id: $id);
	}                 

	   
                                         
   
                                                                          
   
                                   
   
                                                 
   
                                                                   
                                                        
   
                                                                                 
                                                                                    
    
	public function enableJob(string $id): array {
		$job = $this->getJob(id: $id);
		$destinationId = (string)($job['destinationId'] ?? '');

		if ($this->destinations->isValid(id: $destinationId) === false) {
			throw new OCSBadRequestException('Destination is invalid. Test connection first.');
		}

		$job['enabled'] = true;

		return $this->saveObjectData(schemaKey: self::SCHEMA_KEY, data: $job, id: $id);
	}                 

	   
                  
   
                                   
   
                                                  
   
                                             
                                                                                                                                   
    
	public function disableJob(string $id): array {
		$job = $this->getJob(id: $id);
		$job['enabled'] = false;

		return $this->saveObjectData(schemaKey: self::SCHEMA_KEY, data: $job, id: $id);
	}                  

	   
                                                                          
                                                     
   
                                                                             
                                                                
   
                                                                       
   
                                                                                 
   
                                                                                    
    
	public function testRun(array $job): array {
		$errors = [];
		$samples = [];
		$sampleRows = 0;

		$schemas = $job['sourceSchemas'] ?? [];
		if (is_array($schemas) === false || $schemas === []) {
			return ['success' => false, 'sample_rows' => 0, 'errors' => ['No source schemas selected.'], 'samples' => []];
		}

		foreach ($schemas as $schemaSlug) {
			$slug = (string)$schemaSlug;
			try {
				$file = $this->data->extractSchema(
					job: $job,
					schemaSlug: $slug,
					watermarkFrom: null,
					rowCap: ExportDataService::TEST_RUN_ROW_CAP
				);
				$sampleRows += (int)$file['rows'];
				$samples[] = [
					'schema' => $slug,
					'rows' => (int)$file['rows'],
					'format' => (string)($job['format'] ?? 'csv'),
					'dropped_columns' => $file['dropped_columns'],
					'preview' => $this->preview(contents: (string)$file['contents']),
				];
			} catch (\Throwable $e) {
				$errors[] = $e->getMessage();
			}         
		}             

		return [
			'success' => ($errors === []),
			'sample_rows' => $sampleRows,
			'errors' => $errors,
			'samples' => $samples,
		];
	}               

	   
                                                       
   
                                                  
                                       
   
                                    
   
                                                                                    
    
	public function describeSchedule(string $expression, string $locale = 'en'): string {
		return $this->cron->describe(expression: $expression, locale: $locale);
	}                        

	   
                                 
   
                                                 
   
                
   
                                                             
   
                                                                            
                                                                              
                                                                                    
   
                                                                                 
    
	private function validateJob(array $data): void {
		if (trim((string)($data['name'] ?? '')) === '') {
			throw new OCSBadRequestException('Job name is required.');
		}

		$schemas = $data['sourceSchemas'] ?? [];
		if (is_array($schemas) === false || $schemas === []) {
			throw new OCSBadRequestException('At least one source schema is required.');
		}

		foreach ($schemas as $schemaSlug) {
			if ($this->data->schemaExists(slug: (string)$schemaSlug) === false) {
				throw new OCSBadRequestException("Schema '{$schemaSlug}' does not exist.");
			}
		}

		$format = (string)($data['format'] ?? '');
		if (in_array($format, ['csv', 'parquet', 'jsonl'], true) === false) {
			throw new OCSBadRequestException("Unsupported format '{$format}'.");
		}

		$mode = (string)($data['mode'] ?? '');
		if (in_array($mode, ['full', 'incremental'], true) === false) {
			throw new OCSBadRequestException("Unsupported mode '{$mode}'.");
		}

		if ($mode === 'incremental' && trim((string)($data['incrementalWatermarkColumn'] ?? '')) === '') {
			throw new OCSBadRequestException('Watermark column is required for incremental mode.');
		}

		if ($this->cron->isValid(expression: (string)($data['scheduleCron'] ?? '')) === false) {
			throw new OCSBadRequestException('Invalid cron expression.');
		}

		if (trim((string)($data['destinationId'] ?? '')) === '') {
			throw new OCSBadRequestException('A destination is required.');
		}
	}                   

	   
                                                  
   
                                                    
   
                                                       
    
	private function sanitize(array $data): array {
		unset($data['@self'], $data['id'], $data['uuid']);

		return $data;
	}                

	   
                                                          
   
                                                  
   
                               
    
	private function preview(string $contents): string {
		if (strlen($contents) <= 500) {
			return $contents;
		}

		return substr($contents, 0, 500) . '…';
	}               
}           
