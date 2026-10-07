<?php

   
                         
  
                                                                           
                                                                         
                                                                     
                                                                        
                                                                  
  
                    
                                                     
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                                                             
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing\Transport;

   
                                                                   
  
                                                                                                                                                             
   
final class RenderedMail {
	   
                
   
                                                  
                                                               
                                                                                        
                                                                                 
                                            
                                           
                                                 
                                                                       
                                                                              
                                                                               
                                                                               
                                                          
                                                                                        
    
	public function __construct(
		public readonly string $fromEmail,
		public readonly string $fromName,
		public readonly string $replyTo,
		public readonly string $toEmail,
		public readonly string $subject,
		public readonly string $html,
		public readonly string $text,
		public readonly array $headers,
		public readonly string $deliveryId,
	) {
	}                   
}           
