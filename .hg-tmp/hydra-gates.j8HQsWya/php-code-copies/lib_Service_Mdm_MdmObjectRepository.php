<?php

   
                                
  
                                                                                
                                                                       
                                                                   
                                                                          
                                                         
  
                    
                                     
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                     
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Mdm;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                                  
  
                                                                           
                                                                     
                                                                                
                                                                                 
                                                                                
                                                                            
  
                                                  
                                                
  
                                                                     
   
class MdmObjectRepository {
	   
                                                    
   
               
    
	private const REGISTER_KEY = 'register';

	   
                                                                                 
   
               
    
	public const SCHEMA_MASTER_ENTITY = 'masterEntity';

	   
                                                                                 
   
               
    
	public const SCHEMA_SOURCE_RECORD = 'sourceRecord';

	   
                
   
                                                                      
                                              
                                                                                         
    
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                       
   
                                   
   
                                                               
   
                                                                      
    
	public function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, self::REGISTER_KEY, '');
		if ($register === '') {
			throw new RuntimeException('Pipelinq register is not configured.');
		}

		return $register;
	}                

	   
                                                                         
   
                                                                    
   
                                 
   
                                                             
                                                                                                                
    
	public function schema(string $schemaSlug): string {
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaSlug . '_schema', '');
		if ($schema === '') {
			throw new RuntimeException("MDM schema '{$schemaSlug}' is not configured.");
		}

		return $schema;
	}              

	   
                                                 
   
                                              
                                      
   
                                                                             
                                                                                                                
    
	public function find(string $schemaSlug, string $id): ?array {
		try {
			$object = $this->objectService()->find(
				id: $id,
				register: $this->register(),
				schema: $this->schema(schemaSlug: $schemaSlug)
			);
		} catch (\Throwable $e) {
			return null;
		}

		if ($object === null) {
			return null;
		}

		return $this->toArray(object: $object);
	}            

	   
                                                      
   
                                              
                                                                
   
                                                                  
                                                                                                                
    
	public function findAll(string $schemaSlug, array $filters = []): array {
		$baseFilters = [
			'register' => $this->register(),
			'schema' => $this->schema(schemaSlug: $schemaSlug),
		];

		try {
			$results = $this->objectService()->findAll(
				config: ['filters' => array_merge($baseFilters, $filters)]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq MDM: findAll failed',
				['schema' => $schemaSlug, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$objects = [];
		foreach (($results ?? []) as $result) {
			$objects[] = $this->toArray(object: $result);
		}

		return $objects;
	}               

	   
                                               
   
                                              
                                                        
                                                       
   
                                                           
                                                                                                                
    
	public function save(string $schemaSlug, array $object, ?string $uuid = null): array {
		unset($object['@self']);

		$saved = $this->objectService()->saveObject(
			object: $object,
			extend: [],
			register: $this->register(),
			schema: $this->schema(schemaSlug: $schemaSlug),
			uuid: $uuid
		);

		return $this->toArray(object: $saved);
	}            

	   
                             
   
                                                                         
                                      
   
                
                                                                                                                
    
	public function delete(string $schemaSlug, string $id): void {
		                                                                
		$this->schema(schemaSlug: $schemaSlug);
		$this->objectService()->deleteObject($id);
	}              

	   
                                                                              
   
                                                                 
                                                                 
                                                                            
                                                                       
   
                                                   
   
                                                                    
   
                                                       
    
	public function findMasterEntity(string $masterId): ?array {
		return $this->find(schemaSlug: self::SCHEMA_MASTER_ENTITY, id: $masterId);
	}                        

	   
                                                                       
   
                                                               
                                                      
   
                                                                   
   
                                                       
    
	public function findMasterEntities(?string $entityType = null, ?string $status = null): array {
		$filters = [];
		if ($entityType !== null && $entityType !== '') {
			$filters['entityType'] = $entityType;
		}

		if ($status !== null && $status !== '') {
			$filters['status'] = $status;
		}

		return $this->findAll(schemaSlug: self::SCHEMA_MASTER_ENTITY, filters: $filters);
	}                          

	   
                                                                 
   
                                                   
   
                                                                       
   
                                                       
    
	public function linkedSourceRecords(string $masterId): array {
		return $this->findAll(
			schemaSlug: self::SCHEMA_SOURCE_RECORD,
			filters: ['currentMasterEntity' => $masterId]
		);
	}                           

	   
                                 
   
                            
                                                                                                                
    
	public function uuid(): string {
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3F) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}            

	   
                                      
   
                                 
                                                                                                                
    
	public function now(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
	}           

	   
                                                              
   
                                       
   
                                                
                                                                                                                
    
	public function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialized = $object->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		if (is_object($object) === true) {
			return (array)$object;
		}

		return [];
	}               

	   
                                       
   
                                      
    
	private function objectService(): object {
		                                                                 
		                                                              
		return $this->objectService;
	}                     
}           
