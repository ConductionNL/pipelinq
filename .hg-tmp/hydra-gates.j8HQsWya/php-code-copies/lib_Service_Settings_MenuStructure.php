<?php

   
                                                                 
  
                                                                          
                                                                        
                                                                               
                                                                      
                                                                
  
                                                                       
                                                                              
                                                                              
                                                        
  
                                                                       
                                                                             
  
                                                           
                                                                           
                                               
  
                    
                                          
  
                                                              
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                              
  
                                                                              
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Settings;

   
                                                                                 
  
                                                                              
   
class MenuStructure {

	   
                                                                    
   
               
    
	public const KEY = 'menu_structure';

	   
                          
   
               
    
	public const SIMPLE = 'simple';

	   
                                                       
   
               
    
	public const FULL = 'full';

	   
                                                                      
   
               
    
	public const MODULES_KEY = 'menu_modules';

	   
                                                                 
   
                                                     
                                  
   
                     
    
	public const MODULES = [
		'sales',
		'marketing',
		'pos',
		'products',
		'contracts',
		'loyalty',
	];

	   
                                            
   
                                                                           
                                                                         
                                                                             
   
                                                     
   
                                      
   
                                                                                           
    
	public function normalise(string $stored): string {
		if (strtolower(trim($stored)) === self::FULL) {
			return self::FULL;
		}

		return self::SIMPLE;
	}                 

	   
                                                                           
   
                                                                         
                                                                          
                                                                
   
                                                     
   
                                                                          
   
                                                                                           
    
	public function normaliseModules(string $stored): string {
		$wanted = array_map(
			static fn (string $word): string => strtolower(trim($word)),
			explode(',', $stored)
		);

		return implode(',', array_values(array_filter(
			self::MODULES,
			static fn (string $module): bool => in_array(needle: $module, haystack: $wanted, strict: true)
		)));
	}                        
}           
