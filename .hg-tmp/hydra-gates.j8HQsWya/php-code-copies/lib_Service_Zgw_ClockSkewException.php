<?php

   
                               
  
                                                                          
                                                                         
                                                                         
                                                                        
                                                                      
                       
  
                                                                
  
                      
                                     
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Zgw;

   
                                                                 
  
                                                                                      
                
   
class ClockSkewException extends ZgwException {
	   
                
   
                                                        
                                                                          
                                                                        
    
	public function __construct(
		string $message,
		public readonly int $observedTime,
		public readonly int $serverTime = 0,
	) {
		parent::__construct(message: $message);
	}                   
}           
