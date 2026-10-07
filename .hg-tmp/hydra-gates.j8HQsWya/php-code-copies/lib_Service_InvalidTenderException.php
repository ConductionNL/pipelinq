<?php

   
                                   
  
                                                                           
                                                                           
                                                                            
                                                                      
                                                                            
                                                                     
                                                             
  
                      
                                 
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                               
                                                               
                                                               
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use Exception;

   
                                              
  
                                                                           
                                                                             
                                                                         
                                      
  
                                                               
   
class InvalidTenderException extends Exception {
	   
                
   
                                                        
                                                                                     
    
	public function __construct(
		string $message,
		private int $statusCode = 400,
	) {
		parent::__construct(message: $message);
	}                   

	   
                                                      
   
                                
                                                                                       
                 
    
	public function getStatusCode(): int {
		return $this->statusCode;
	}                     
}           
