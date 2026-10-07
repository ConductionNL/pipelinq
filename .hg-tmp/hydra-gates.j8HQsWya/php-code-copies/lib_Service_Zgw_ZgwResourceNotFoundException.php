<?php

   
                                         
  
                                                                           
                                                                     
                                                                                     
                                                                  
                                                            
  
                      
                                     
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Zgw;

   
                                                                    
  
                                                                                      
                
   
class ZgwResourceNotFoundException extends ZgwException {
	   
                
   
                                                          
    
	public function __construct(
		public readonly string $url,
	) {
		parent::__construct(message: sprintf('ZGW: resource not found at URL "%s"', $url));
	}                   
}           
