<?php

   
                              
  
                                                                               
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

   
                       
  
                                                                          
                                                                              
                         
  
                                                                           
                                                                            
                                                                         
                  
  
                                                                 
   
class CtiContactMatcher {
	   
                                                  
   
            
    
	public const MAX_MATCHES = 3;

	   
                
   
                                                                                 
                                                
                                                             
                                              
    
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private PhoneNormaliser $phoneNormaliser,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                                                  
   
                                       
                                                                         
   
                                                       
                                                              
                                                             
   
                                                                 
                                                               
   
                                                                             
   
                                                                                                                                            
   
                                                                  
    
	public function findByPhoneNumber(?string $e164Number, ?string $orgId = null): array {
		if ($e164Number === null || $e164Number === '') {
			return ['matches' => [], 'totalMatches' => 0];
		}

		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$contactSchema = $this->appConfig->getValueString(Application::APP_ID, 'contact_schema', '');
		$clientSchema = $this->appConfig->getValueString(Application::APP_ID, 'client_schema', '');

		if ($register === '') {
			return ['matches' => [], 'totalMatches' => 0];
		}

		$needles = $this->needles(e164: $e164Number);

		$matches = [];
		if ($contactSchema !== '') {
			$matches = array_merge(
				$matches,
				$this->searchSchema(register: $register, schema: $contactSchema, needles: $needles, type: 'contact')
			);
		}

		if ($clientSchema !== '') {
			$matches = array_merge(
				$matches,
				$this->searchSchema(register: $register, schema: $clientSchema, needles: $needles, type: 'client')
			);
		}

		$total = count($matches);
		return [
			'matches' => array_slice($matches, 0, self::MAX_MATCHES),
			'totalMatches' => $total,
		];
	}                         

	   
                                                                          
   
                                                
   
                             
    
	private function needles(string $e164): array {
		$digitsOnly = preg_replace('/[^0-9]/', '', $e164) ?? '';
		$needles = [$e164, $digitsOnly];
		if (strlen($digitsOnly) >= 9) {
			$needles[] = substr($digitsOnly, -9);
		}

		if (strlen($digitsOnly) >= 8) {
			$needles[] = substr($digitsOnly, -8);
		}

		return array_values(array_unique(array_filter($needles)));
	}               

	   
                                                                      
   
                                                                       
                                                                     
                           
   
                                            
                                        
                                                                      
                                                                  
   
                                          
    
	private function searchSchema(string $register, string $schema, array $needles, string $type): array {
		if (empty($needles) === true) {
			return [];
		}

		try {
			  
                                                                 
      

			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$objects = $objectService->findAll(
				[
					'filters' => [
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 500,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CTI contact matcher: OR findAll failed',
				['exception' => $e->getMessage(), 'schema' => $schema]
			);
			return [];
		}         

		if (is_array($objects) === false) {
			return [];
		}

		$matches = [];
		foreach ($objects as $object) {
			$data = $this->toArray(object: $object);
			if ($this->matchesAnyNeedle(record: $data, needles: $needles) === true) {
				$data['_matchType'] = $type;
				$matches[] = $data;
			}
		}

		return $matches;
	}                    

	   
                                                              
   
                                                                                
   
                               
    
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialised = $object->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$inner = $object->getObject();
			if (is_array($inner) === true) {
				return $inner;
			}
		}

		return [];
	}               

	   
                                                                         
   
                                                                 
                                                                      
   
                
   
                                                                                                                      
    
	private function matchesAnyNeedle(array $record, array $needles): bool {
		$candidates = [];
		foreach (['phone', 'phoneNumber', 'mobile', 'telephone', 'tel', 'msisdn'] as $key) {
			if (isset($record[$key]) === true && is_string($record[$key]) === true) {
				$candidates[] = $record[$key];
			}
		}

		$rawObject = ($record['object'] ?? null);
		if (is_array($rawObject) === true) {
			foreach (['phone', 'phoneNumber', 'mobile', 'telephone', 'tel'] as $key) {
				if (isset($rawObject[$key]) === true && is_string($rawObject[$key]) === true) {
					$candidates[] = $rawObject[$key];
				}
			}
		}

		foreach ($candidates as $candidate) {
			$cleaned = preg_replace('/[^0-9+]/', '', $candidate) ?? '';
			if ($cleaned === '') {
				continue;
			}

			foreach ($needles as $needle) {
				if (str_contains($cleaned, $needle) === true) {
					return true;
				}
			}
		}

		return false;
	}                        

	   
                                                                     
   
                                                                           
                                                                            
                                                
   
                                           
   
                                                                                                                         
                                                                                                                         
   
                                                                  
    
	public function normaliseStoredPhoneNumbers(): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$contactSchema = $this->appConfig->getValueString(Application::APP_ID, 'contact_schema', '');
		$clientSchema = $this->appConfig->getValueString(Application::APP_ID, 'client_schema', '');
		if ($register === '') {
			return ['updated' => 0, 'skipped' => 0];
		}

		$updated = 0;
		$skipped = 0;

		foreach (array_filter([$contactSchema, $clientSchema]) as $schema) {
			try {
				  
                                                                  
       

				$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
				$objects = $objectService->findAll(
					[
						'filters' => [
							'register' => $register,
							'schema' => $schema,
						],
						'limit' => 5000,
					]
				);
			} catch (\Throwable $e) {
				$this->logger->warning(
					'CTI contact matcher migration: OR findAll failed',
					['exception' => $e->getMessage(), 'schema' => $schema]
				);
				continue;
			}         

			if (is_array($objects) === false) {
				continue;
			}

			foreach ($objects as $object) {
				$data = $this->toArray(object: $object);
				$raw = (string)($data['phone'] ?? ($data['phoneNumber'] ?? ($data['mobile'] ?? '')));
				if ($raw === '') {
					$skipped++;
					continue;
				}

				$normalised = $this->phoneNormaliser->normaliseForOrg($raw);
				if ($normalised['e164'] === null) {
					$skipped++;
					continue;
				}

				if (($data['phoneE164'] ?? null) === $normalised['e164']) {
					$skipped++;
					continue;
				}

				$id = (string)($data['id'] ?? ($data['uuid'] ?? ''));
				if ($id === '') {
					$skipped++;
					continue;
				}

				try {
					$this->execAsSystem(
						objectService: $objectService,
						operation: static fn () => $objectService->saveObject(
							['phoneE164' => $normalised['e164']],
							[],
							$register,
							$schema,
							$id
						)
					);
					$updated++;
				} catch (\Throwable $e) {
					$this->logger->warning(
						'CTI contact matcher: updateObject failed',
						['exceptionClass' => get_class($e), 'exception' => $e->getMessage(), 'id' => $id]
					);
					$skipped++;
				}
			}             
		}             

		return ['updated' => $updated, 'skipped' => $skipped];
	}                                   

	   
                                                                            
   
                                                              
                                                                           
                                                                                             
                                                                              
                                                                          
                            
   
                                                      
                                                              
   
                                                                                
                                                                                
                                                 
   
                                                                                                                     
    
	private function execAsSystem(object $objectService, callable $operation): void {
		if (method_exists($objectService, 'runAsSystem') === true) {
			$objectService->runAsSystem($operation);
			return;
		}

		$operation();
	}                    
}           
