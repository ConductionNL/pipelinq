<?php

   
                                    
  
                                                                              
                                       
                                                                           
                                                                            
         
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                    
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;

   
                                                                         
  
                                                                            
                                                                              
                                                                             
                                                               
  
                                                                         
                                                                                
                                                                           
                                                                                
                                                         
  
                                                                         
   
class RegisterResolverService {
	   
                                               
   
               
    
	private const REGISTER_CONFIG_KEY = 'register';

	   
                                                                               
   
                                                                        
   
                              
    
	private array $cache = [];

	   
                
   
                                                                          
    
	public function __construct(
		private IAppConfig $appConfig,
	) {
	}                   

	   
                                                               
   
                                                                            
                                                                            
                                                                             
   
                                                                             
                                                                        
                                                                            
                                                                           
   
                                                                          
                                                                   
   
                                                                          
    
	public function resolve(string $logicalName = 'default'): string {
		if (array_key_exists($logicalName, $this->cache) === true) {
			return $this->cache[$logicalName];
		}

		$registerId = $this->appConfig->getValueString(
			Application::APP_ID,
			self::REGISTER_CONFIG_KEY,
			''
		);

		$this->cache[$logicalName] = $registerId;

		return $registerId;
	}               

	  
                    
   
                                                                          
                                                            
                                                                               
                                                             
                                                                            
                                                                          
                                                  
    
}           
