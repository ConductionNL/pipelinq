<?php

   
                           
  
                                                                            
                                                                              
                                                                            
                                                                              
                                                                            
                                                                              
                                                    
  
                    
                                      
  
                                                              
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                                                 
  
                                                                                                                            
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Demo;

use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCP\IUserManager;
use OCP\IUserSession;

   
                                                             
  
                                                                                                                            
   
class DemoUserFields {
	   
                
   
                                                                        
                                                                                       
                                                                                             
    
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IUserManager $userManager,
		private readonly ConfigFileLoaderService $configLoader,
	) {
	}                   

	   
                                                                       
   
                                                                          
                                                                            
   
                                                                         
   
                                                                     
   
                                                                                                                             
    
	public function withRealUsers(array $objects): array {
		$schemas = (array)($this->configLoader->loadConfigurationFile()['components']['schemas'] ?? []);

		return $this->assign(
			objects: $objects,
			schemas: $schemas,
			userExists: fn (string $uid): bool => $this->userManager->userExists($uid),
			actingUid: $this->userSession->getUser()?->getUID()
		);
	}                     
	   
                                                                
   
                                                                               
                                                                                             
                                                                                           
                                                                                           
   
                                                                              
   
                                                                                                                             
    
	public function assign(array $objects, array $schemas, callable $userExists, ?string $actingUid): array {
		foreach ($objects as $index => $object) {
			$schemaSlug = (string)($object['@self']['schema'] ?? '');
			$properties = (array)($schemas[$schemaSlug]['properties'] ?? []);

			foreach ($properties as $key => $property) {
				if (array_key_exists($key, $object) === false || is_array($property) === false) {
					continue;
				}

				if ($this->isUserProperty(property: $property) === true) {
					$object = $this->assignOne(object: $object, key: $key, userExists: $userExists, actingUid: $actingUid);
					continue;
				}

				if (($property['type'] ?? '') === 'array'
					&& $this->isUserProperty(property: (array)($property['items'] ?? [])) === true
					&& is_array($object[$key]) === true
				) {
					$object[$key] = $this->assignList(values: $object[$key], userExists: $userExists, actingUid: $actingUid);
				}
			}

			$objects[$index] = $object;
		}             

		return $objects;
	}              

	   
                                                        
   
                                                              
   
                                                                                    
    
	private function isUserProperty(array $property): bool {
		return in_array(($property['format'] ?? ''), ['user', 'username'], true) === true
			|| ($property['referenceType'] ?? '') === 'nextcloud-user';
	}                      

	   
                              
   
                                                         
                                                        
                                                                              
                                                                     
   
                                            
    
	private function assignOne(array $object, string $key, callable $userExists, ?string $actingUid): array {
		$value = $object[$key];
		if ($value === null || $value === '' || (is_string($value) === true && $userExists($value) === true)) {
			return $object;
		}

		if ($actingUid === null || $actingUid === '') {
			unset($object[$key]);
			return $object;
		}

		$object[$key] = $actingUid;
		return $object;
	}                 

	   
                                
   
                                                              
                                                                              
                                                                     
   
                                                                      
    
	private function assignList(array $values, callable $userExists, ?string $actingUid): array {
		$out = [];
		foreach ($values as $value) {
			if (is_string($value) === true && $userExists($value) === true) {
				$out[] = $value;
				continue;
			}

			if ($actingUid !== null && $actingUid !== '') {
				$out[] = $actingUid;
			}
		}

		return array_values(array_unique($out));
	}                  
}           
