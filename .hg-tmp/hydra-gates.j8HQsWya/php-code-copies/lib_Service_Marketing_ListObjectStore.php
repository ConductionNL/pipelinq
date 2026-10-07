<?php

   
                            
  
                                                                       
                                                                        
                                                                             
                          
  
                                                                   
                                                                             
                                                                          
                                                                             
                                                                             
                                                                        
                                     
  
                    
                                           
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                 
  
                                                                                                                          
   
class ListObjectStore {
	   
                                                                   
   
               
    
	private const DEFAULT_REGISTER_SLUG = 'pipelinq';

	   
                
   
                                                                                   
                                                     
                                          
   
                                                                                                                           
    
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                                     
   
                                                                
                                                                   
   
                               
   
                                                                                                                           
    
	public function schemaSlug(string $configKey, string $default): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, $configKey, '');
		if ($slug !== '') {
			return $slug;
		}

		return $default;
	}                  

	   
                                     
   
                                                      
                                          
   
                                                                           
                                                                           
   
                                                                                                                           
    
	public function find(string $schemaSlug, string $id): ?array {
		$context = $this->context(schemaSlug: $schemaSlug);
		if ($context === null || $id === '') {
			return null;
		}

		try {
			$entity = $context['service']->find(
				id: $id,
				register: $context['register'],
				schema: $context['schema'],
				_rbac: false,
				_multitenancy: false,
			);
		} catch (Throwable $e) {
			$this->logger->info(
				'ListObjectStore.find: not found',
				['schema' => $schemaSlug, 'id' => $id, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $this->toArray(value: $entity);
	}            

	   
                                             
   
                                                                     
                                                                         
                                                                       
                   
   
                                                      
                                                            
   
                                                            
   
                                                                                                                           
    
	public function findAll(string $schemaSlug, array $filters = []): array {
		$context = $this->context(schemaSlug: $schemaSlug);
		if ($context === null) {
			return [];
		}

		try {
			$rows = $context['service']->findAll(
				config: [
					'filters' => array_merge(
						$filters,
						['register' => $context['register'], 'schema' => $context['schema']],
					),
				],
				_rbac: false,
				_multitenancy: false,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ListObjectStore.findAll: query failed',
				['schema' => $schemaSlug, 'filters' => array_keys($filters), 'exception' => $e->getMessage()]
			);
			return [];
		}

		$out = [];
		foreach (($rows ?? []) as $row) {
			$payload = $this->toArray(value: $row);
			if ($payload === [] || $this->matches(payload: $payload, filters: $filters) === false) {
				continue;
			}

			$out[] = $payload;
		}

		return $out;
	}               

	   
                                                       
   
                                                     
                                                              
                                                     
   
                                                                    
   
                                                                                                                           
    
	public function save(string $schemaSlug, array $payload, ?string $id = null): ?array {
		$context = $this->context(schemaSlug: $schemaSlug);
		if ($context === null) {
			return null;
		}

		$uuid = $id;
		if ($uuid === '') {
			$uuid = null;
		}

		try {
			$saved = $context['service']->saveObject(
				object: $payload,
				register: $context['register'],
				schema: $context['schema'],
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ListObjectStore.save: write failed',
				['schema' => $schemaSlug, 'id' => $id, 'exception' => $e->getMessage()]
			);
			return null;
		}

		return $this->toArray(value: $saved);
	}            

	   
                      
   
                                                               
                                  
   
                                                     
   
                                                                                                       
    
	public function delete(string $schemaSlug, string $id): bool {
		$context = $this->context(schemaSlug: $schemaSlug);
		if ($context === null || $id === '') {
			return false;
		}

		try {
			return (bool)$context['service']->deleteObject(
				uuid: $id,
				register: $context['register'],
				schema: $context['schema'],
				_rbac: false,
				_multitenancy: false,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ListObjectStore.delete: delete failed',
				['schema' => $schemaSlug, 'id' => $id, 'exception' => $e->getMessage()]
			);
			return false;
		}
	}              

	   
                                                    
   
                                                             
   
                                                  
   
                                                                                                                           
   
                                                                                                                   
    
	public function idOf(?array $payload): string {
		if ($payload === null) {
			return '';
		}

		foreach (['uuid', 'id', 'slug'] as $key) {
			$value = ($payload[$key] ?? null);
			if (is_scalar($value) === true && (string)$value !== '') {
				return (string)$value;
			}
		}

		if (isset($payload['@self']) === true && is_array($payload['@self']) === true) {
			foreach (['uuid', 'id', 'slug'] as $key) {
				$value = ($payload['@self'][$key] ?? null);
				if (is_scalar($value) === true && (string)$value !== '') {
					return (string)$value;
				}
			}
		}

		return '';
	}            

	   
                                                                        
   
                                                   
   
                                                                         
                                                 
    
	private function context(string $schemaSlug): ?array {
		if ($schemaSlug === '') {
			return null;
		}

		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($register === '') {
			$register = self::DEFAULT_REGISTER_SLUG;
		}

		try {
			$service = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'ListObjectStore.context: OpenRegister unavailable',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		return ['register' => $register, 'schema' => $schemaSlug, 'service' => $service];
	}               

	   
                                                           
   
                                                 
                                                            
   
                                                
    
	private function matches(array $payload, array $filters): bool {
		foreach ($filters as $field => $value) {
			if ((string)($payload[$field] ?? '') !== (string)$value) {
				return false;
			}
		}

		return true;
	}               

	   
                                                                    
   
                                               
   
                                               
    
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialised = $value->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($value) === true && method_exists($value, 'getObject') === true) {
			$payload = $value->getObject();
			if (is_array($payload) === true) {
				return $payload;
			}
		}

		return [];
	}               
}           
