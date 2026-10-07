<?php

   
                                      
  
                                                                   
                                                             
                                                                 
                           
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                
  
                       
                                                                  
                                     
                                                       
                                                                    
                                                                
  
                                                                   
   
class ChannelProviderRepository {
	   
                                        
    
	private const DEFAULT_SCHEMA_SLUG = 'channelProvider';

	   
                                   
    
	private const DEFAULT_REGISTER_SLUG = 'pipelinq';

	   
                
   
                                                      
                                            
                                          
   
                                                                    
    
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                                
   
                                                                      
   
                                                           
   
                                                                    
    
	public function listActive(string $kind): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		try {
			$rows = $objectService->findAll(
				config: [
					'filters' => [
						'kind' => $kind,
						'active' => true,
						'register' => $this->getRegisterSlug(),
						'schema' => $this->getSchemaSlug(),
					],
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ChannelProviderRepository.listActive: findAll failed',
				['kind' => $kind, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$out = [];
		foreach (($rows ?? []) as $row) {
			$arr = $this->toArray(value: $row);
			                                                                
			                                                          
			                                
			if (((bool)($arr['active'] ?? true)) === false) {
				continue;
			}

			$out[] = $arr;
		}

		usort(
			$out,
			static function (array $a, array $b): int {
				$priorityA = (int)($a['priority'] ?? 100);
				$priorityB = (int)($b['priority'] ?? 100);
				return ($priorityA <=> $priorityB);
			}
		);

		return $out;
	}                  

	   
                                                         
   
                                           
   
                                                  
                                                              
    
	public function findById(string $id): ?array {
		return $this->lookup(id: $id, scope: []);
	}                

	   
                                                                            
   
                                                                       
                                                                         
                                                                         
                                    
   
                                           
   
                                                  
                                                              
    
	public function findByIdForWebhook(string $id): ?array {
		return $this->lookup(id: $id, scope: ['_rbac' => false, '_multitenancy' => false]);
	}                          

	   
                                                                         
   
                                           
                                                                                
   
                                                  
    
	private function lookup(string $id, array $scope): ?array {
		if ($id === '') {
			return null;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$entity = $objectService->find(
				id: $id,
				register: $this->getRegisterSlug(),
				schema: $this->getSchemaSlug(),
				_rbac: ($scope['_rbac'] ?? true),
				_multitenancy: ($scope['_multitenancy'] ?? true),
			);
		} catch (Throwable $e) {
			$this->logger->info(
				'ChannelProviderRepository.findById: not found',
				['id' => $id, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $this->toArray(value: $entity);
	}              

	   
                                            
   
                                      
                                                                  
   
                                                  
                                                              
    
	public function findByVendor(string $kind, string $vendor): ?array {
		foreach ($this->listActive(kind: $kind) as $row) {
			if ((string)($row['vendor'] ?? '') === $vendor) {
				return $row;
			}
		}

		return null;
	}                    

	   
                                       
   
                                        
    
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'ChannelProviderRepository.getObjectService: OpenRegister unavailable',
				['exception' => $e->getMessage()]
			);
			return null;
		}
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

	   
                                           
   
                        
    
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_REGISTER_SLUG;
	}                       

	   
                                         
   
                        
    
	private function getSchemaSlug(): string {
		$slug = $this->appConfig->getValueString(
			Application::APP_ID,
			'channelProvider_schema',
			''
		);

		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_SCHEMA_SLUG;
	}                     
}           
