<?php

   
                           
  
                                                                              
                                                                             
                                                   
  
                    
                                      
  
                                                              
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                                                 
  
                                                                                                      
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Demo;

use DateTimeImmutable;
use DateTimeInterface;

   
                                                                   
  
                                                                                                      
   
class DemoSeedValues {
	   
                                                                        
                               
   
               
    
	public const DEMO_PREFIX = '[Demo]';

	   
                                                                     
   
                                                          
   
                                                                      
   
                                                                                                       
    
	public function resolvePlaceholders(array $data): array {
		foreach ($data as $field => $value) {
			if (is_string($value) === false) {
				continue;
			}

			if (preg_match('/^@(days|datetime):(-?\d+)$/', $value, $matches) !== 1) {
				continue;
			}

			$offset = (int)$matches[2];
			$moment = (new DateTimeImmutable())->modify(sprintf('%+d days', $offset));

			$data[$field] = $moment->format(DateTimeInterface::ATOM);
			if ($matches[1] === 'days') {
				$data[$field] = $moment->format('Y-m-d');
			}
		}

		return $data;
	}                           

	   
                                                                        
                                                     
   
                                                      
                                                            
                                                                                      
                                                                               
                                                                              
                                               
   
                                                                                  
   
                                                                                                       
    
	public function linkReference(array $data, array $definition, array $uuids, string $keyName, string $section, string $field): array {
		if (isset($definition[$keyName]) === false) {
			return $data;
		}

		$uuid = ($uuids[$section . ':' . $definition[$keyName]] ?? null);
		if ($uuid !== null) {
			$data[$field] = $uuid;
		}

		return $data;
	}                     
}           
