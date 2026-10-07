<?php

   
                       
  
                                                                       
                                                                     
                                                                
                                                                         
                                                 
  
                    
                                                     
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                                             
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing\Transport;

   
                                                             
  
                                                                                                                                             
   
final class SendResult {
	   
                
   
                                                                                  
                                                                                            
                                                                                 
    
	public function __construct(
		public readonly bool $accepted,
		public readonly ?string $providerId = null,
		public readonly ?string $error = null,
	) {
	}                   
}           
