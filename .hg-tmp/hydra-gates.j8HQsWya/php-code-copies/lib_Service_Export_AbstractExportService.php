<?php

   
                                  
  
                                                                           
                                                                              
                                                                            
                                                                         
           
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                                    
                                               
  
                         
  
                                                 
  
                                                                                
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Export;

use Psr\Container\ContainerInterface;
use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                         
  
                                                                           
                                                                       
                                                                        
                                                                              
                                                                   
  
                                                                                
   
abstract class AbstractExportService {
	   
                
   
                                                          
                                                
                                                                                         
    
	public function __construct(
		protected ContainerInterface $container,
		protected IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                                                     
   
                                                                               
   
                                                                   
   
                                                                             
    
	protected function config(string $schemaKey): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');

		if ($register === '' || $schema === '') {
			throw new OCSNotFoundException('Export register of schema is niet geconfigureerd.');
		}

		return [$register, $schema];
	}              

	   
                                                                
   
                                                   
                                      
   
                                                                                  
    
	protected function findObjectById(string $schemaKey, string $id): ?array {
		[$register, $schema] = $this->config(schemaKey: $schemaKey);

		try {
			$object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			return null;
		}

		if ($object === null) {
			return null;
		}

		return $this->toArray(object: $object);
	}                      

	   
                                                                             
   
                                                   
                                                                                           
   
                                                                            
    
	protected function findAllObjects(string $schemaKey, array $filters = []): array {
		[$register, $schema] = $this->config(schemaKey: $schemaKey);

		$allFilters = array_merge(
			['register' => $register, 'schema' => $schema],
			$filters
		);

		try {
			$results = $this->getObjectService()->findAll(config: ['filters' => $allFilters]);
		} catch (\Throwable $e) {
			return [];
		}

		$objects = [];
		foreach (($results ?? []) as $result) {
			$objects[] = $this->toArray(object: $result);
		}

		return $objects;
	}                      

	   
                                                                          
   
                                                   
                                                      
                                                                  
   
                                                              
    
	protected function saveObjectData(string $schemaKey, array $data, ?string $id = null): array {
		[$register, $schema] = $this->config(schemaKey: $schemaKey);

		unset($data['@self']);

		$saved = $this->getObjectService()->saveObject(
			object: $data,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: $id
		);

		return $this->toArray(object: $saved);
	}                      

	   
                                                             
   
                                                   
                                      
   
                
    
	protected function deleteObjectById(string $schemaKey, string $id): void {
		[$register, $schema] = $this->config(schemaKey: $schemaKey);

		$this->getObjectService()->deleteObject(register: $register, schema: $schema, uuid: $id);
	}                        

	   
                                       
   
                                      
   
                                                              
                                                                                 
    
	protected function getObjectService(): object {
		                                                                 
		                                                              
		return $this->objectService;
	}                        

	   
                                                                
   
                                       
   
                                                        
    
	protected function toArray(mixed $object): array {
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

	   
                                       
   
                                         
    
	protected function now(): string {
		return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
	}           

	   
                       
   
                            
    
	protected function uuid(): string {
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3F) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}            
}           
