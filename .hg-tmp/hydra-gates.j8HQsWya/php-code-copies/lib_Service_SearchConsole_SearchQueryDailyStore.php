<?php

   
                                  
  
                                                                      
                                                                       
                                                                          
                                                                           
                 
  
                    
                                               
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                                                                            
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\SearchConsole;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                             
  
                                                                                                                                                                            
   
class SearchQueryDailyStore {

	   
                                       
   
               
    
	public const SCHEMA = 'searchQueryDaily';

	   
                                                                          
   
               
    
	private const DEFAULT_REGISTER_SLUG = 'pipelinq';

	   
                
   
                                                                                  
                                                     
                                          
   
                                                                                                                                                                             
    
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                                  
   
                                         
                                                                                                             
                                                             
   
                                 
   
                                                                                                                                                                             
    
	public function upsert(string $property, array $row, string $importedAt): bool {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return false;
		}

		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', self::DEFAULT_REGISTER_SLUG);
		$schema = $this->appConfig->getValueString(Application::APP_ID, (self::SCHEMA . '_schema'), self::SCHEMA);
		$identity = ['property' => $property, 'date' => $row['date'], 'query' => $row['query'], 'page' => $row['page']];
		$existingId = $this->findExistingId(objectService: $objectService, register: $register, schema: $schema, identity: $identity);
		$payload = ($identity + $row + ['source' => 'gsc', 'importedAt' => $importedAt]);

		try {
			$objectService->saveObject(
				object: $payload,
				register: $register,
				schema: $schema,
				uuid: $existingId,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('SearchQueryDailyStore: save failed', ['exception' => $e->getMessage()]);
			return false;
		}

		return true;
	}              

	   
                                                                
   
                                                                 
                                              
                                          
                                                                      
   
                                                                    
    
	private function findExistingId(object $objectService, string $register, string $schema, array $identity): ?string {
		try {
			$found = $objectService->findAll(
				config: ['filters' => (['register' => $register, 'schema' => $schema] + $identity), 'limit' => 1],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->info('SearchQueryDailyStore: lookup failed, creating', ['exception' => $e->getMessage()]);
			return null;
		}

		if (is_iterable($found) === false) {
			return null;
		}

		foreach ($found as $existing) {
			return $this->idOf(value: $existing);
		}

		return null;
	}                      

	   
                                              
   
                                        
   
                                             
    
	private function idOf(mixed $value): ?string {
		$row = $value;
		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$row = $value->jsonSerialize();
		}

		if (is_array($row) === false) {
			return null;
		}

		foreach (['uuid', 'id'] as $key) {
			$candidate = ($row[$key] ?? ($row['@self'][$key] ?? null));
			if (is_scalar($candidate) === true && (string)$candidate !== '') {
				return (string)$candidate;
			}
		}

		return null;
	}            

	   
                                                  
   
                                                                     
    
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning('SearchQueryDailyStore: OpenRegister unavailable', ['exception' => $e->getMessage()]);
			return null;
		}
	}                        
}           
