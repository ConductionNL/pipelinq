<?php

   
                               
  
                                                                                 
                                                                         
                                                                                   
                                                                            
                                                                    
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                            
  
                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                                             
  
                                                                       
                                                                       
                                                                            
                                                                       
                                                                           
                                                                        
            
  
                                                                         
                                                                   
                                                            
  
                                                  
                                                                                                                                      
  
                                                   
   
class EligibilityService {
	   
                                                  
   
               
    
	public const SERVICE_SCHEMA_KEY = 'service_schema';

	   
                                                   
   
               
    
	public const RESOURCE_SCHEMA_KEY = 'resource_schema';

	   
                
   
                                                       
                                                                                      
                                              
                                                                                         
    
	public function __construct(
		private IAppConfig $appConfig,
		private AvailabilityService $availabilityService,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                                            
   
                                                                        
                                                                          
                                                                             
                                                                    
   
                                                                                   
   
                                            
   
                                                    
    
	public function getEligibleResources(string $serviceId): array {
		if ($serviceId === '') {
			return [];
		}

		$service = $this->loadService(serviceId: $serviceId);
		if ($service === null) {
			return [];
		}

		$required = $this->normaliseSkillList(value: ($service['requiredSkills'] ?? []));
		$resources = $this->loadResources();

		$eligible = [];
		foreach ($resources as $resource) {
			if ($this->matchesSkillRequirements(resource: $resource, requiredSkills: $required) === true) {
				$eligible[] = $resource;
			}
		}

		return $eligible;
	}                            

	   
                                                         
   
                                                                         
                                                                            
                                                                         
                                                                            
                                      
   
                                                                                   
   
                                                                  
                                              
                                                             
   
                                                    
    
	public function getEligibleResourcesPerStep(string $serviceId): array {
		if ($serviceId === '') {
			return [];
		}

		$service = $this->loadService(serviceId: $serviceId);
		if ($service === null) {
			return [];
		}

		$resources = $this->loadResources();
		$steps = $this->normaliseSteps(
			multiStep: ($service['multiStep'] ?? []),
			fallbackSkills: $this->normaliseSkillList(value: ($service['requiredSkills'] ?? [])),
			fallbackDuration: (int)($service['durationMinutes'] ?? 0)
		);

		$out = [];
		foreach ($steps as $index => $step) {
			$required = [];
			if ($step['skillRequired'] !== '') {
				$required = [$step['skillRequired']];
			}

			$eligibleForStep = [];
			if ($step['allowGap'] === false) {
				foreach ($resources as $resource) {
					if ($this->matchesSkillRequirements(resource: $resource, requiredSkills: $required) === true) {
						$eligibleForStep[] = $resource;
					}
				}
			}

			$out[] = [
				'stepIndex' => $index,
				'durationMinutes' => $step['durationMinutes'],
				'skillRequired' => $step['skillRequired'],
				'allowGap' => $step['allowGap'],
				'eligibleResources' => $eligibleForStep,
			];
		}             

		return $out;
	}                                   

	   
                                                                                   
   
                                                                             
                                                                         
                                                                          
                                       
   
                                               
                                              
                                                            
   
                                            
   
                                                    
    
	public function getEligibleResourcesForSlot(string $serviceId, string $date, int $durationMinutes): array {
		if ($serviceId === '' || $date === '' || $durationMinutes <= 0) {
			return [];
		}

		$eligible = $this->getEligibleResources(serviceId: $serviceId);
		if ($eligible === []) {
			return [];
		}

		$withSlots = [];
		foreach ($eligible as $resource) {
			$resourceId = $this->idOf(object: $resource);
			if ($resourceId === '') {
				continue;
			}

			$slots = $this->availabilityService->computeAvailability(
				resourceId: $resourceId,
				date: $date,
				serviceDurationMinutes: $durationMinutes
			);
			if ($slots === []) {
				continue;
			}

			$withSlots[] = $resource;
		}

		return $withSlots;
	}                                   

	   
                                                                     
   
                                                                       
                                                                   
                                                                           
                          
   
                                                                        
                                   
   
                                                              
                                                                   
   
                
   
                                                    
    
	public function matchesSkillRequirements(array $resource, array $requiredSkills): bool {
		if ($requiredSkills === []) {
			return true;
		}

		$resourceSkills = $this->normaliseSkillList(value: ($resource['skills'] ?? []));
		if ($resourceSkills === []) {
			return false;
		}

		foreach ($requiredSkills as $skill) {
			if (in_array($skill, $resourceSkills, true) === false) {
				return false;
			}
		}

		return true;
	}                                

	   
                                     
   
                                               
   
                                     
    
	private function loadService(string $serviceId): ?array {
		$register = $this->registerId();
		$schema = $this->schemaId(key: self::SERVICE_SCHEMA_KEY);
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$result = $this->getObjectService()->find(
				id: $serviceId,
				register: $register,
				schema: $schema
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: service load failed for eligibility',
				['service' => $serviceId]
			);
			return null;
		}

		return $this->toArray(object: $result);
	}                   

	   
                                                                  
   
                                            
    
	private function loadResources(): array {
		$register = $this->registerId();
		$schema = $this->schemaId(key: self::RESOURCE_SCHEMA_KEY);
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
					],
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: resource list failed for eligibility');
			return [];
		}

		$out = [];
		foreach (($rows ?? []) as $row) {
			$resource = $this->toArray(object: $row);
			if ($resource !== null) {
				$out[] = $resource;
			}
		}

		return $out;
	}                     

	   
                                                      
   
                                                                           
                                                                            
                                                                         
                                                                          
                                                       
   
                                                      
                                                                                                     
                                                                                
   
                                                                                                                
    
	private function normaliseSteps(mixed $multiStep, array $fallbackSkills, int $fallbackDuration): array {
		if (is_array($multiStep) === true && $multiStep !== []) {
			$out = [];
			foreach ($multiStep as $step) {
				if (is_array($step) === false) {
					continue;
				}

				$out[] = [
					'durationMinutes' => max(0, (int)($step['durationMinutes'] ?? 0)),
					'skillRequired' => (string)($step['skillRequired'] ?? ''),
					'resourceType' => (string)($step['resourceType'] ?? ''),
					'allowGap' => (bool)($step['allowGap'] ?? false),
				];
			}

			return $out;
		}

		$skill = '';
		if ($fallbackSkills !== []) {
			$skill = (string)$fallbackSkills[0];
		}

		return [
			[
				'durationMinutes' => max(0, $fallbackDuration),
				'skillRequired' => $skill,
				'resourceType' => '',
				'allowGap' => false,
			],
		];
	}                      

	   
                                                                          
   
                                                                 
   
                                                           
   
                              
    
	private function normaliseSkillList(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$out = [];
		foreach ($value as $entry) {
			if (is_string($entry) === false) {
				continue;
			}

			$trimmed = trim($entry);
			if ($trimmed === '') {
				continue;
			}

			$out[] = $trimmed;
		}

		return $out;
	}                          

	   
                                                     
   
                                                    
   
                  
    
	private function idOf(array $object): string {
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

		return '';
	}            

	   
                                                                 
   
                                                
   
                                     
    
	private function toArray(mixed $object): ?array {
		if ($object === null) {
			return null;
		}

		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true) {
			if (method_exists($object, 'jsonSerialize') === true) {
				$serialised = $object->jsonSerialize();
				if (is_array($serialised) === true) {
					return $serialised;
				}
			}

			if (method_exists($object, 'toArray') === true) {
				$arr = $object->toArray();
				if (is_array($arr) === true) {
					return $arr;
				}
			}

			return (array)$object;
		}

		return null;
	}               

	   
                                                     
   
                                                                       
                                                                      
                                                                           
                                                                            
                                                                           
                                                                      
   
                                                                       
    
	private function registerId(): string {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($registerId === '') {
			$this->logger->warning(
				'Pipelinq: app-config "register" is not configured; OpenRegister calls are refused, not run unscoped'
			);
		}

		return $registerId;
	}                  

	   
                                          
   
                                                             
   
                  
    
	private function schemaId(string $key): string {
		return $this->appConfig->getValueString(Application::APP_ID, $key, '');
	}                

	   
                                                                
   
                                              
    
	private function getObjectService(): object {
		                                                                 
		                                                              
		return $this->objectService;
	}                        
}           
