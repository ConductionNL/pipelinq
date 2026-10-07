<?php

   
                                     
  
                                                                            
                                                                               
                                                                        
                                                                            
                     
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                                    
                                               
  
                         
  
                                                 
  
                                                                             
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Export;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Adapter\ExportSinkRegistry;
use OCA\Pipelinq\Service\ConnectorSourceRegister;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

   
                                                       
  
                                                                             
   
class ExportDestinationService extends AbstractExportService {
	   
                                            
   
               
    
	private const SCHEMA_KEY = 'exportDestination_schema';

	   
                                
   
                           
    
	public const TYPES = [
		's3',
		'azure_data_lake',
		'gcs',
		'bigquery',
		'snowflake',
		'sftp',
		'postgres',
	];

	   
                                       
   
                                                               
                                                                              
                                                                             
                                                                             
                             
   
               
    
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

	   
                                                                         
   
                                                              
   
                                                                              
    
	public function listDestinations(): array {
		return $this->findAllObjects(schemaKey: self::SCHEMA_KEY);
	}                        

	   
                            
   
                                           
   
                                                 
   
                                             
   
                                                                              
    
	public function getDestination(string $id): array {
		$destination = $this->findObjectById(schemaKey: self::SCHEMA_KEY, id: $id);
		if ($destination === null) {
			throw new OCSNotFoundException('Export destination not found.');
		}

		return $destination;
	}                      

	   
                                                                         
   
                                                             
   
                                                         
   
                                                                              
   
                                                                              
    
	public function createDestination(array $data): array {
		$this->validate(data: $data);

		$data = $this->sanitize(data: $data);
		$data['validationStatus'] = 'untested';

		$created = $this->saveObjectData(schemaKey: self::SCHEMA_KEY, data: $data, id: null);

		                                                                     
		$id = (string)($created['id'] ?? $created['uuid'] ?? '');
		if ($id !== '') {
			$this->testConnection(id: $id);
			return $this->getDestination(id: $id);
		}

		return $created;
	}                         

	   
                                                      
   
                                           
                                                     
   
                                                         
   
                                                    
                                             
   
                                                                              
    
	public function updateDestination(string $id, array $data): array {
		$existing = $this->getDestination(id: $id);
		$merged = array_merge($existing, $this->sanitize(data: $data));
		$this->validate(data: $merged);

		$merged['validationStatus'] = 'untested';
		$this->saveObjectData(schemaKey: self::SCHEMA_KEY, data: $merged, id: $id);

		$this->testConnection(id: $id);
		return $this->getDestination(id: $id);
	}                         

	   
                         
   
                                           
   
                
   
                                             
   
                                                                              
    
	public function deleteDestination(string $id): void {
		$this->getDestination(id: $id);
		$this->deleteObjectById(schemaKey: self::SCHEMA_KEY, id: $id);
	}                         

	   
                                                            
   
                                                                            
                                                                        
                                                             
   
                                           
   
                                                        
   
                                                                
   
                                                                              
    
	public function testConnection(string $id): bool {
		$destination = $this->getDestination(id: $id);
		$type = (string)($destination['type'] ?? '');

		$valid = false;
		if ($this->sinks->supports(type: $type) === true) {
			$credentials = $this->resolveCredentials(destination: $destination);
			$valid = $this->sinks->get(type: $type)->testConnection($credentials, $destination);
		}

		$validationStatus = 'invalid';
		if ($valid === true) {
			$validationStatus = 'valid';
		}

		$destination['validationStatus'] = $validationStatus;
		$destination['lastValidatedAt'] = $this->now();
		$this->saveObjectData(schemaKey: self::SCHEMA_KEY, data: $destination, id: $id);

		return $valid;
	}                      

	   
                                                                       
   
                                           
   
                                                       
   
                                                                              
    
	public function isValid(string $id): bool {
		try {
			$destination = $this->getDestination(id: $id);
		} catch (\Throwable $e) {
			return false;
		}

		return (string)($destination['validationStatus'] ?? '') === 'valid';
	}               

	   
                               
   
                                                 
   
                
   
                                                    
    
	private function validate(array $data): void {
		if (trim((string)($data['name'] ?? '')) === '') {
			throw new OCSBadRequestException('Destination name is required.');
		}

		$type = (string)($data['type'] ?? '');
		if (in_array($type, self::TYPES, true) === false) {
			throw new OCSBadRequestException("Unsupported destination type '{$type}'.");
		}

		if (trim((string)($data['connectorSourceId'] ?? '')) === '') {
			throw new OCSBadRequestException('An OpenConnector source is required for credentials.');
		}

		if (trim((string)($data['pathTemplate'] ?? '')) === '') {
			throw new OCSBadRequestException('A path template is required.');
		}
	}                

	   
                                                                     
   
                                                                            
                                                                        
   
                                                    
   
                                                       
    
	private function sanitize(array $data): array {
		                                                      
		  
		                                                             
		                                                                    
		                                                                      
		                                                                    
		                                                                    
		                                                
		  
		                                                              
		                                                                        
		                                                                      
		                                                                    
		                                                                       
		                                                                  
		                                    
		  
		                                                              
		                                                                       
		                                                                     
		                                                                        
		$allowed = [
			'name',
			'type',
			'connectorSourceId',
			'pathTemplate',
			'compression',
			'encryptionEnabled',
			'namingConvention',
			'validationStatus',
			'lastValidatedAt',
		];

		return array_intersect_key($data, array_flip($allowed));
	}                

	   
                                                                         
   
                                                                      
                                                                        
                                                                     
                                                                          
                                                                          
                                                                     
                                                                        
                                                             
   
                                                                    
   
                                                                          
   
                                                                              
    
	private function resolveCredentials(array $destination): array {
		$sourceId = (string)($destination['connectorSourceId'] ?? '');
		if ($sourceId === '') {
			return [];
		}

		                                                                     
		                                                                     
		                                                                        
		                                                        
		                                             
		$registerSlug = $this->connectorRegister->slugOrNull(
			operation: 'ExportDestinationService.resolveCredentials',
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
				'Pipelinq: export destination credential resolution failed',
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
}           
