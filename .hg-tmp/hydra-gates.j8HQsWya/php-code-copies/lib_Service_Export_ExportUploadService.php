<?php

   
                                
  
                                                                            
                                                                               
                                                                              
                                                                           
                                                                              
                                                               
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                                    
                                               
  
                         
  
                                                 
  
                                                                             
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Export;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Adapter\ExportSinkRegistry;
use OCA\Pipelinq\Service\ConnectorSourceRegister;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

   
                                                                          
  
                                                                             
   
class ExportUploadService extends AbstractExportService {
	   
                                                           
   
                        
    
	public const BACKOFF_SECONDS = [1, 2, 4, 8, 16];

	   
                                       
   
                                                               
                                                                              
                                                                             
                                                                             
                             
   
               
    
	private const OPENCONNECTOR_SOURCE_SCHEMA_SLUG = 'source';

	   
                                                                    
                                                                       
                                                                         
                                                      
   
                           
    
	private const OPENCONNECTOR_LEGACY_SECRET_FIELDS = ['apikey', 'secret', 'password', 'jwt'];

	   
                
   
                                                          
                                                
                                                                                     
                                                               
                                                                                                     
                                              
    
	public function __construct(
		ContainerInterface $container,
		IAppConfig $appConfig,
		ObjectServiceInterface $objectService,
		private ExportSinkRegistry $sinks,
		private ConnectorSourceRegister $connectorRegister,
		private LoggerInterface $logger,
	) {
		parent::__construct(
			container: $container,
			appConfig: $appConfig,
			objectService: $objectService
		);
	}                   

	   
                                              
   
                                                                        
                                   
   
                
    
	private bool $sleepBetweenRetries = true;

	   
                                                        
   
                                                        
   
                
   
                                                                             
   
                                                                              
    
	public function setSleepBetweenRetries(bool $sleep): void {
		$this->sleepBetweenRetries = $sleep;
	}                              

	   
                                                     
   
                                                                           
                                                                                               
                                                                                                    
   
                                                                                         
                                                                                                  
   
                                                                              
    
	public function uploadFiles(array $destination, array $files, array $context = []): array {
		$type = (string)($destination['type'] ?? '');
		if ($this->sinks->supports(type: $type) === false) {
			return $this->failAll(files: $files, error: "Unsupported destination type '{$type}'.");
		}

		$sink = $this->sinks->get(type: $type);
		$credentials = $this->resolveCredentials(destination: $destination);

		$manifest = [];
		$byteCount = 0;
		$lastAck = null;
		$failures = [];
		$succeeded = 0;

		foreach ($files as $file) {
			$remotePath = $this->resolvePath(destination: $destination, file: $file, context: $context);
			$entry = [
				'path' => $remotePath,
				'size_bytes' => (int)($file['size_bytes'] ?? 0),
				'rows_in_file' => (int)($file['rows'] ?? 0),
				'sha256' => (string)($file['sha256'] ?? ''),
				'compression_used' => (string)($file['compression_used'] ?? 'none'),
			];

			try {
				$ack = $this->uploadWithRetry(
					sink: $sink,
					credentials: $credentials,
					destination: $destination,
					remotePath: $remotePath,
					contents: (string)($file['contents'] ?? '')
				);

				$entry['upload_status'] = 'success';
				$entry['destination_ack'] = $ack;
				$byteCount += $entry['size_bytes'];
				$lastAck = $ack;
				$succeeded++;
			} catch (\Throwable $e) {
				$entry['upload_status'] = 'failed';
				$entry['error'] = $e->getMessage();
				$failures[] = $remotePath . ': ' . $e->getMessage();
			}         

			$manifest[] = $entry;
		}             

		return $this->buildResult(
			manifest: $manifest,
			total: count($files),
			succeeded: $succeeded,
			failures: $failures,
			byteCount: $byteCount,
			lastAck: $lastAck
		);
	}                   

	   
                                                             
   
                                         
                                                                      
                                                                           
                                                       
                                           
   
                                                   
   
                                                     
   
                                                                              
    
	private function uploadWithRetry(
		object $sink,
		array $credentials,
		array $destination,
		string $remotePath,
		string $contents,
	): string {
		$attempts = count(self::BACKOFF_SECONDS);
		$lastError = 'unknown error';

		for ($attempt = 0; $attempt < $attempts; $attempt++) {
			try {
				return $sink->upload($credentials, $destination, $remotePath, $contents);
			} catch (\Throwable $e) {
				$lastError = $e->getMessage();
				$this->logger->warning(
					'Pipelinq: export upload attempt failed',
					['path' => $remotePath, 'attempt' => ($attempt + 1), 'error' => $lastError]
				);

				$isLast = ($attempt === ($attempts - 1));
				if ($isLast === false && $this->sleepBetweenRetries === true) {
					sleep(self::BACKOFF_SECONDS[$attempt]);
				}
			}         
		}         

		throw new RuntimeException("Upload failed after {$attempts} attempts: {$lastError}");
	}                       

	   
                                                                               
   
                                                                          
   
                                                                           
                                                          
                                                                  
   
                                            
   
                                                                              
    
	public function resolvePath(array $destination, array $file, array $context): string {
		$schema = (string)($file['schema'] ?? 'data');
		$runId = (string)($context['run_id'] ?? '');
		$timestamp = (string)($context['timestamp'] ?? gmdate('Ymd\THis\Z'));
		$partition = (string)($context['partition'] ?? '');

		$replacements = [
			'{schema}' => $schema,
			'{partition}' => $partition,
			'{run_id}' => $runId,
			'{timestamp}' => $timestamp,
		];

		$template = (string)($destination['pathTemplate'] ?? '');
		$base = strtr($template, $replacements);

		$naming = (string)($destination['namingConvention'] ?? '');
		if ($naming === '') {
			$naming = '{schema}_{run_id}_{timestamp}';
		}

		$filename = strtr($naming, $replacements);

		                                                                     
		return rtrim($base, '/') . '/' . ltrim($filename, '/');
	}                   

	   
                                                                   
   
                                                                      
                                                                        
                                                                     
                                                                          
                                                                          
                                                                     
                                                                        
                                                             
   
                                                                             
                                                                             
                        
   
                                                                           
   
                                                                                   
   
                                                                              
    
	private function resolveCredentials(array $destination): array {
		$sourceId = (string)($destination['connectorSourceId'] ?? '');
		if ($sourceId === '') {
			return [];
		}

		                                                                     
		                                                                     
		                                                                        
		                                                                           
		                
		$registerSlug = $this->connectorRegister->slugOrNull(
			operation: 'ExportUploadService.resolveCredentials',
			sourceId: $sourceId
		);
		if ($registerSlug === null) {
			return [];
		}

		try {
			$source = $this->getObjectService()->find(
				id: $sourceId,
				register: $registerSlug,
				schema: self::OPENCONNECTOR_SOURCE_SCHEMA_SLUG,
				_rbac: true,
				_multitenancy: true,
				_render: false,
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: failed to resolve export credentials',
				['sourceId' => $sourceId, 'error' => $e->getMessage()]
			);
			return [];
		}

		if ($source === null) {
			return [];
		}

		return $this->extractSourceCredentials(source: $this->toArray(object: $source));
	}                          

	   
                                                                       
                                                                  
   
                                                                           
   
                                                           
   
                                                                              
    
	private function extractSourceCredentials(array $source): array {
		$credentials = [];
		foreach (self::OPENCONNECTOR_LEGACY_SECRET_FIELDS as $field) {
			$value = $source[$field] ?? null;
			if (is_string($value) === true && $value !== '') {
				$credentials[$field] = $value;
			}
		}

		$authentication = $source['configuration']['authentication'] ?? null;
		if (is_array($authentication) === true && $authentication !== []) {
			$credentials['authentication'] = $authentication;
		}

		return $credentials;
	}                                

	   
                                                           
   
                                                                            
                                            
                                                      
                                                                 
                                               
                                                        
   
                                                  
    
	private function buildResult(
		array $manifest,
		int $total,
		int $succeeded,
		array $failures,
		int $byteCount,
		?string $lastAck,
	): array {
		$status = 'partial';
		if ($total === 0 || $succeeded === $total) {
			$status = 'all_succeeded';
		}

		if ($total > 0 && $succeeded === 0) {
			$status = 'all_failed';
		}

		$error = null;
		if ($failures !== []) {
			$error = implode('; ', $failures);
		}

		return [
			'status' => $status,
			'manifest' => $manifest,
			'file_count' => $succeeded,
			'byte_count' => $byteCount,
			'destination_ack' => $lastAck,
			'error_message' => $error,
		];
	}                   

	   
                                                             
   
                                                                        
                                    
   
                                                             
    
	private function failAll(array $files, string $error): array {
		$manifest = [];
		foreach ($files as $file) {
			$manifest[] = [
				'path' => (string)($file['schema'] ?? ''),
				'size_bytes' => (int)($file['size_bytes'] ?? 0),
				'upload_status' => 'failed',
				'error' => $error,
			];
		}

		return [
			'status' => 'all_failed',
			'manifest' => $manifest,
			'file_count' => 0,
			'byte_count' => 0,
			'destination_ack' => null,
			'error_message' => $error,
		];
	}               
}           
