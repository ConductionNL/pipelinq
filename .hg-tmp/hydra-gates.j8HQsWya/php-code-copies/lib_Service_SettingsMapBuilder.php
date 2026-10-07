<?php

   
                               
  
                                                                     
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

   
                                                                     
  
                                                         
   
class SettingsMapBuilder {
	   
                           
   
               
    
	private const REGISTER_SLUG = 'pipelinq';

	   
                                                 
   
                                               
   
                                     
                                                                            
    
	public function buildSchemaSlugMap(array $schemas): array {
		$schemaMap = [];
		foreach ($schemas as $schema) {
			$this->addSchemaToMap(
				schema: $schema,
				schemaMap: $schemaMap
			);
		}

		return $schemaMap;
	}                          

	   
                                                          
   
                                                   
   
                                          
                                                                            
    
	public function findRegisterIdBySlug(array $registers): mixed {
		foreach ($registers as $register) {
			$registerId = $this->extractRegisterIdIfMatch(register: $register);
			if ($registerId !== null) {
				return $registerId;
			}
		}

		return null;
	}                            

	   
                                                 
   
                                                                        
   
                                           
   
                                        
                                                                            
    
	public function findDefaultViewId(array $views): ?string {
		$defaultId = $this->findMarkedDefaultViewId(views: $views);
		if ($defaultId !== null) {
			return $defaultId;
		}

		return $this->getFirstViewId(views: $views);
	}                         

	   
                                              
   
                                           
   
                                        
    
	private function findMarkedDefaultViewId(array $views): ?string {
		foreach ($views as $view) {
			$viewArray = $this->normalizeToArray(value: $view);
			if ($viewArray === null) {
				continue;
			}

			$isDefault = $viewArray['isDefault'] ?? false;
			if ($isDefault === true) {
				return $viewArray['id'] ?? $viewArray['uuid'] ?? null;
			}
		}

		return null;
	}                               

	   
                                             
   
                                           
   
                                        
    
	private function getFirstViewId(array $views): ?string {
		if (empty($views) === true) {
			return null;
		}

		$firstView = $this->normalizeToArray(value: reset($views));
		if ($firstView === null) {
			return null;
		}

		return $firstView['id'] ?? $firstView['uuid'] ?? null;
	}                      

	   
                                              
   
                                                    
                                                
   
                
    
	private function addSchemaToMap(mixed $schema, array &$schemaMap): void {
		$schemaArray = $this->normalizeToArray(value: $schema);
		if ($schemaArray === null) {
			return;
		}

		if (isset($schemaArray['slug']) === false) {
			return;
		}

		$schemaMap[$schemaArray['slug']] = $schemaArray['id'] ?? $schemaArray['uuid'] ?? null;
	}                      

	   
                                                                  
   
                                                        
   
                                          
    
	private function extractRegisterIdIfMatch(mixed $register): mixed {
		$registerArray = $this->normalizeToArray(value: $register);
		if ($registerArray === null) {
			return null;
		}

		if (isset($registerArray['slug']) === false) {
			return null;
		}

		if ($registerArray['slug'] !== self::REGISTER_SLUG) {
			return null;
		}

		return $registerArray['id'] ?? $registerArray['uuid'] ?? null;
	}                                

	   
                                                   
   
                                               
   
                                                         
    
	private function normalizeToArray(mixed $value): ?array {
		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			return $value->jsonSerialize();
		}

		if (is_array($value) === true) {
			return $value;
		}

		return null;
	}                        
}           
