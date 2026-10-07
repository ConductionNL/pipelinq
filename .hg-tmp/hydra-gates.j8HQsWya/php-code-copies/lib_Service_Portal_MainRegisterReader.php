<?php

   
                               
  
                                                                               
                                                                               
                                                                                
                                                                              
                                                                            
                                                                   
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\SettingsLoadService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                                 
  
                                                                           
                                                               
  
                                                                                        
                                                                           
                                                                           
                              
   
class MainRegisterReader {
	   
                
   
                                                
                                              
                                                                                         
    
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                                                        
   
                                                                   
   
                                                                   
                                                                                         
                                                                            
                                                                            
                               
    
	public function hasSchema(string $schemaKey): bool {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->schemaIdFor(schemaKey: $schemaKey);
		return $register !== '' && $schema !== '';
	}                 

	   
                                                                         
   
                                                                             
                                                               
   
                                             
                                                                
   
                                                                   
                                                                                         
                                                                            
                                                                            
                               
    
	public function findAll(string $schemaKey, array $filters = []): array {
		if ($this->hasSchema(schemaKey: $schemaKey) === false) {
			return [];
		}

		[$register, $schema] = $this->config(schemaKey: $schemaKey);

		try {
			                                                                     
			                                   
			$results = $this->objectService()->findAll(
				config: ['filters' => array_merge(['register' => $register, 'schema' => $schema], $filters)],
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq portal: main-register findAll failed',
				['schemaKey' => $schemaKey, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$objects = [];
		foreach (($results ?? []) as $result) {
			$objects[] = $this->toArray(object: $result);
		}

		return $objects;
	}               

	   
                                                      
   
                                             
                                    
   
                                                          
                                                                                         
                                                                            
                                                                            
                               
    
	public function find(string $schemaKey, string $id): ?array {
		if ($id === '' || $this->hasSchema(schemaKey: $schemaKey) === false) {
			return null;
		}

		[$register, $schema] = $this->config(schemaKey: $schemaKey);

		try {
			$object = $this->objectService()->find(id: $id, register: $register, schema: $schema, _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			return null;
		}

		if ($object === null) {
			return null;
		}

		return $this->toArray(object: $object);
	}            

	   
                                                                            
                                                                             
                                                            
   
                                             
                                                         
                                                         
   
                                                  
   
                                                    
                                                                                         
                                                                            
                                                                            
                               
    
	public function save(string $schemaKey, array $data, ?string $id = null): array {
		[$register, $schema] = $this->config(schemaKey: $schemaKey);
		unset($data['@self']);

		try {
			                                                                      
			                                                                 
			                                                                    
			                                             
			$saved = $this->objectService()->saveObject(
				object: $data,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: $id,
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Pipelinq portal: main-register save failed',
				['schemaKey' => $schemaKey, 'exception' => $e->getMessage()]
			);
			throw new RuntimeException('Failed to persist object.');
		}

		return $this->toArray(object: $saved);
	}            

	   
                                                
   
                                             
   
                                                                    
   
                                               
    
	private function config(string $schemaKey): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->schemaIdFor(schemaKey: $schemaKey);
		if ($register === '' || $schema === '') {
			throw new RuntimeException("Main register schema '{$schemaKey}' is not configured.");
		}

		return [$register, $schema];
	}              

	   
                                                                        
                                                                                                   
   
                                             
   
                                                            
    
	private function schemaIdFor(string $schemaKey): string {
		return $this->appConfig->getValueString(
			Application::APP_ID,
			(SettingsLoadService::SCHEMA_CONFIG_KEYS[$schemaKey] ?? $schemaKey . '_schema'),
			''
		);
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

		return (array)$object;
	}               

	   
                                       
   
                                      
    
	private function objectService(): object {
		                                                                 
		                                                              
		return $this->objectService;
	}                     
}           
