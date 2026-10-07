<?php

   
                              
  
                                                                          
                                                                               
  
                                                                        
                                                                        
                                                                     
  
                                                                                
                                    
                                            
                                                              
  
                                                                         
                                                                     
                                                                    
  
                    
                                     
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                                     
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Zgw;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                    
  
                                                         
   
class ZgwRegisterAccess {
	   
                                                              
    
	public const REGISTER = 'pipelinq';

	public const SCHEMA_ENDPOINT = 'zgwEndpoint';
	public const SCHEMA_CLIENT = 'zgwClient';
	public const SCHEMA_ABONN = 'nrcAbonnement';
	public const SCHEMA_MAPPING = 'zgwResourceMapping';

	   
                                                                   
   
                    
    
	private ?object $objectService = null;

	   
                                                                                 
   
                
    
	private bool $resolved = false;

	   
                
   
                                                                            
                                                
    
	public function __construct(
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                            
   
                                                                      
                                   
   
                                                                                    
   
                                                                      
    
	public function find(string $schema, string $id): ?array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$result = $objectService->find(id: $id, register: self::REGISTER, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->info('ZGW: find failed', ['schema' => $schema, 'id' => $id, 'err' => $e->getMessage()]);
			return null;
		}

		return $this->toArray(candidate: $result);
	}            

	   
                                       
   
                                      
                                                                                    
   
                                            
   
                                                                      
    
	public function findAll(string $schema, array $filters = []): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		try {
			                                                                        
			                                                                                 
			                                                                              
			                                                                                
			                                                                           
			$rows = $objectService->findAll(
				config: [
					'filters' => array_merge(
						$filters,
						[
							'register' => self::REGISTER,
							'schema' => $schema,
						]
					),
				]
			);
		} catch (Throwable $e) {
			$this->logger->info('ZGW: findAll failed', ['schema' => $schema, 'err' => $e->getMessage()]);
			return [];
		}         

		$out = [];
		$rowList = [];
		if (is_array($rows) === true) {
			$rowList = $rows;
		}

		foreach ($rowList as $row) {
			$arr = $this->toArray(candidate: $row);
			if ($arr !== null) {
				$out[] = $arr;
			}
		}

		return $out;
	}               

	   
                                         
   
                                      
                                                                       
                                                                        
   
                                                                        
   
                                                                      
    
	public function save(string $schema, array $data, ?string $uuid = null): ?array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		                                                                                       
		if (array_key_exists('@self', $data) === true) {
			unset($data['@self']);
		}

		try {
			$saved = $objectService->saveObject(
				object: $data,
				extend: [],
				register: self::REGISTER,
				schema: $schema,
				uuid: $uuid
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ZGW: saveObject failed',
				['schema' => $schema, 'uuid' => $uuid, 'err' => $e->getMessage()]
			);
			return null;
		}

		return $this->toArray(candidate: $saved);
	}            

	   
                                                                   
   
                                                                                   
   
                                     
                                                          
    
	public function findClientForEndpoint(array $endpoint): ?array {
		$clientId = (string)($endpoint['clientId'] ?? '');
		if ($clientId === '') {
			return null;
		}

		return $this->find(schema: self::SCHEMA_CLIENT, id: $clientId);
	}                             

	   
                                                   
   
                       
    
	private function getObjectService(): ?object {
		if ($this->resolved === true) {
			return $this->objectService;
		}

		$this->resolved = true;
		try {
			$this->objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->info('ZGW: OR ObjectService unavailable', ['err' => $e->getMessage()]);
			$this->objectService = null;
		}

		return $this->objectService;
	}                        

	   
                                                   
   
                                                                          
                                                                         
                                    
   
                                                  
   
                                     
    
	private function toArray(mixed $candidate): ?array {
		if (is_array($candidate) === true) {
			return $candidate;
		}

		if (is_object($candidate) === true) {
			if (method_exists($candidate, 'jsonSerialize') === true) {
				$serialised = $candidate->jsonSerialize();
				if (is_array($serialised) === true) {
					return $serialised;
				}
			}

			if (method_exists($candidate, 'toArray') === true) {
				$arr = $candidate->toArray();
				if (is_array($arr) === true) {
					return $arr;
				}
			}

			return (array)$candidate;
		}

		return null;
	}               
}           
