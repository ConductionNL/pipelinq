<?php

   
                          
  
                                                   
  
                    
                                            
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Cti\Result;

   
                                                          
  
                                                                                         
                                    
   
final class CtiCallResult {
	   
                
   
                                                                    
                                                                           
                                                                   
                                                             
    
	public function __construct(
		public readonly bool $success,
		public readonly ?string $externalCallId = null,
		public readonly ?string $error = null,
		public readonly ?string $platform = null,
	) {
	}                   
}           
