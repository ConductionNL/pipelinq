<?php

   
                                    
  
                                                                                           
                                                                           
                                                                          
                                                                         
                                                      
  
                                                                          
                                                                           
               
  
                      
                                     
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Zgw;

   
                                           
  
                                                                                      
                
   
class OptimisticLockException extends ZgwException {
	   
                
   
                                                        
                                                                             
                                                                                 
                                                                        
    
	public function __construct(
		string $message,
		public readonly array $staleRepresentation,
		public readonly array $freshRepresentation,
		public readonly string $conflictingField = '',
	) {
		parent::__construct(message: $message);
	}                   
}           
