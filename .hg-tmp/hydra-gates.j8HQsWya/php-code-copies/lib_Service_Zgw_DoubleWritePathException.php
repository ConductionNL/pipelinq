<?php

   
                                     
  
                                                                       
                                                                          
                                                                         
                                                                          
                                                                      
                               
  
                      
                                     
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Zgw;

   
                                                                  
  
                                                                                      
                
   
class DoubleWritePathException extends ZgwException {
	   
                
   
                                                        
                                                                                                   
    
	public function __construct(
		string $message,
		public readonly array $conflictEndpointIds = [],
	) {
		parent::__construct(message: $message);
	}                   
}           
