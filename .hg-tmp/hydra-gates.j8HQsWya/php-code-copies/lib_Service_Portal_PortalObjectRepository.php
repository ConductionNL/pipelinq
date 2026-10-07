<?php

   
                                   
  
                                                                        
                                                                               
                                                                            
                                                                                  
                                                                            
                                                                         
                                
  
                                                                            
                                                                             
                                                                
  
                                                                    
                                                                           
                                                                             
                                                                       
                                                                 
  
                                                                             
                                                                          
                                                                           
                                                               
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\SettingsLoadService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                                                            
                                                    
  
                                                                                 
                                                                           
  
                                                                                        
                                                                           
                                                                           
                              
   
class PortalObjectRepository {
	   
                                                           
   
               
    
	private const PORTAL_REGISTER_KEY = 'portal_register';

	   
                
   
                                                
                                              
                                                                                         
                                                                                
    
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly PortalServiceAccount $serviceAccount,
	) {
	}                   

	   
                                                                            
                                                                       
   
                                   
   
                                                          
                                                                                         
                                                                            
                                                                            
                               
    
	public function registerId(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, self::PORTAL_REGISTER_KEY, '');
		if ($register === '') {
			$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		}

		if ($register === '') {
			throw new RuntimeException('Portal register is not configured.');
		}

		return $register;
	}                  

	   
                                                                        
                                                                   
                                                   
   
                                                                      
   
                                 
   
                                                             
                                                                                         
                                                                            
                                                                            
                               
    
	public function schemaId(string $schemaSlug): string {
		$schema = $this->appConfig->getValueString(
			Application::APP_ID,
			(SettingsLoadService::SCHEMA_CONFIG_KEYS[$schemaSlug] ?? $schemaSlug . '_schema'),
			''
		);
		if ($schema === '') {
			throw new RuntimeException("Portal schema '{$schemaSlug}' is not configured.");
		}

		return $schema;
	}                

	   
                                                      
   
                                                                       
                                                                        
                                             
   
                                              
                                         
   
                                                                      
                                                                                         
                                                                            
                                                                            
                               
    
	public function find(string $schemaSlug, string $id): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$object = $this->objectService()->find(
				id: $id,
				register: $this->registerId(),
				schema: $this->schemaId(schemaSlug: $schemaSlug),
				_rbac: false,
				_multitenancy: false
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
		$base = [
			'register' => $this->registerId(),
			'schema' => $this->schemaId(schemaSlug: $schemaSlug),
		];

		try {
			$results = $this->objectService()->findAll(
				config: ['filters' => array_merge($base, $filters)],
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq portal: findAll failed',
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

	   
                                                                     
   
                                              
                                                          
   
                                                               
                                                                                         
                                                                            
                                                                            
                               
    
	public function findOneBy(string $schemaSlug, array $filters): ?array {
		$matches = $this->findAll(schemaSlug: $schemaSlug, filters: $filters);
		return ($matches[0] ?? null);
	}                 

	   
                                                                            
   
                                              
                                                         
                                                       
   
                                                              
   
                                                  
                                                                                         
                                                                            
                                                                            
                               
    
	public function save(string $schemaSlug, array $data, ?string $id = null): array {
		                                              
		unset($data['@self']);

		$register = $this->registerId();
		$schema = $this->schemaId(schemaSlug: $schemaSlug);

		                                                                       
		                                                   
		try {
			$saved = $this->serviceAccount->runAs(
				fn (): mixed => $this->objectService()->saveObject(
					object: $data,
					extend: [],
					register: $register,
					schema: $schema,
					uuid: $id
				)
			);
		} catch (PortalException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$this->logger->error(
				'Pipelinq portal: saveObject failed',
				['schema' => $schemaSlug, 'exception' => $e->getMessage()]
			);
			throw new RuntimeException('Failed to persist portal object.');
		}

		return $this->toArray(object: $saved);
	}            

	   
                                                                           
                                                                           
                                                         
   
                
   
                                                  
                                                                                         
                                                                            
                                                                            
                               
    
	public function requireWritable(): void {
		$this->serviceAccount->require();
	}                       

	   
                                                                     
   
                                                         
   
                                                    
                                                                                         
                                                                            
                                                                            
                               
    
	public function idOf(array $object): ?string {
		if (isset($object['@self']) === true && is_array($object['@self']) === true) {
			$self = $object['@self'];
			if (isset($self['id']) === true) {
				return (string)$self['id'];
			}

			if (isset($self['uuid']) === true) {
				return (string)$self['uuid'];
			}
		}

		if (isset($object['id']) === true) {
			return (string)$object['id'];
		}

		if (isset($object['uuid']) === true) {
			return (string)$object['uuid'];
		}

		return null;
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
