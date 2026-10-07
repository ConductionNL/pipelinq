<?php

   
                                    
  
                                                                                 
                                                                    
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                              
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Lifecycle\SchemaLifecycleGraph;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                             
  
                                                              
   
class LoyaltyProgrammeService {
	   
                                                                                     
   
               
    
	private const PROGRAMME_SCHEMA_SLUG = 'loyaltyProgramme';

	   
                
   
                                                       
                                              
                                                                                         
                                                                                                 
    
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private SchemaLifecycleGraph $lifecycleGraph = new SchemaLifecycleGraph(),
	) {
	}                   

	   
                                        
   
                                                  
   
                                                                            
   
                                                                  
    
	public function validateForActivation(string $programmeId): array {
		$errors = [];
		$programme = $this->getProgramme(programmeId: $programmeId);
		if ($programme === null) {
			return ['Programme not found.'];
		}

		              
		$start = (string)($programme['startDate'] ?? '');
		$end = (string)($programme['endDate'] ?? '');
		if ($start !== '' && $end !== '' && $start > $end) {
			$errors[] = 'Cannot activate: einddatum must be after startdatum';
		}

		                     
		$rules = $this->countByProgramme(schemaKey: 'pointsRule_schema', programmeId: $programmeId);
		if ($rules === 0) {
			$errors[] = 'Cannot activate: no points rules configured';
		}

		                                  
		$options = $this->countByProgramme(schemaKey: 'redemptionOption_schema', programmeId: $programmeId);
		if ($options === 0) {
			$errors[] = 'Cannot activate: no redemption options configured';
		}

		return $errors;
	}                             

	   
                                                        
   
                                                  
                                                                    
   
                                                         
   
                                                   
                                                               
    
	public function activate(string $programmeId, string $activatedBy): array {
		$programme = $this->getProgramme(programmeId: $programmeId);
		if ($programme === null) {
			throw new RuntimeException('Programme not found.');
		}

		                                                                        
		                                                                          
		                                                                          
		                                                                        
		                                                                
		$current = (string)($programme['status'] ?? 'draft');
		$this->assertTransitionAllowed(from: $current, to: 'active');

		                                                                       
		                                                                             
		                                                                       
		$errors = $this->validateForActivation(programmeId: $programmeId);
		if ($errors !== []) {
			throw new RuntimeException(implode('; ', $errors));
		}

		$programme['status'] = 'active';
		$this->logger->info(
			'Pipelinq: loyalty programme activated',
			['programmeId' => $programmeId, 'activatedBy' => $activatedBy]
		);

		return $this->persist(payload: $programme, uuid: $programmeId);
	}                

	   
                                                                                
   
                                                                
                                                                             
                                                                               
                                           
   
                                       
                                    
   
                
   
                                                                
    
	private function assertTransitionAllowed(string $from, string $to): void {
		$graph = $this->lifecycleGraph->adjacencyFor(schemaSlug: self::PROGRAMME_SCHEMA_SLUG);
		if ($graph === []) {
			                                                        
			$graph = ['draft' => ['active']];
		}

		$allowed = ($graph[$from] ?? []);
		if (in_array($to, $allowed, true) === false) {
			throw new RuntimeException(
				sprintf("Cannot activate: transition from '%s' to '%s' is not allowed", $from, $to)
			);
		}
	}                               

	   
                            
   
                                                  
   
                                     
                                                                                       
                              
    
	public function getProgramme(string $programmeId): ?array {
		[$register, $schema] = $this->config(schemaKey: 'loyaltyProgramme_schema');
		if ($register === '' || $schema === '' || $programmeId === '') {
			return null;
		}

		try {
			$object = $this->getObjectService()->find(id: $programmeId, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			return null;
		}

		if ($object === null) {
			return null;
		}

		return $this->toArray(object: $object);
	}                    

	   
                                                      
   
                                                             
                                                  
   
               
    
	private function countByProgramme(string $schemaKey, string $programmeId): int {
		[$register, $schema] = $this->config(schemaKey: $schemaKey);
		if ($register === '' || $schema === '') {
			return 0;
		}

		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'programmeId' => $programmeId,
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 1000,
				]
			);
		} catch (\Throwable $e) {
			return 0;
		}

		if (is_array($rows) === true) {
			return count($rows);
		}

		return 0;
	}                        

	   
                        
   
                                                            
                                       
   
                                
    
	private function persist(array $payload, ?string $uuid): array {
		[$register, $schema] = $this->config(schemaKey: 'loyaltyProgramme_schema');
		if ($register === '' || $schema === '') {
			throw new RuntimeException('LoyaltyProgramme schema is not configured.');
		}

		$saved = $this->getObjectService()->saveObject(
			object: $payload,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: $uuid
		);

		return $this->toArray(object: $saved);
	}               

	   
                                 
   
                                                                        
                                                                            
                                                                             
                                                                             
                                                                           
                                                                      
   
                                                   
   
                                                                           
                                                          
    
	private function config(string $schemaKey): array {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schemaId = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
		if ($registerId === '' || $schemaId === '') {
			$this->logger->warning(
				'Pipelinq: register/schema not configured; OpenRegister calls are refused, not run unscoped',
				['schemaKey' => $schemaKey]
			);
		}

		return [$registerId, $schemaId];
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

		return [];
	}               

	   
                                       
   
                  
    
	private function getObjectService(): object {
		                                                                 
		                                                              
		return $this->objectService;
	}                        
}           
