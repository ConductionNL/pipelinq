<?php

   
                             
  
                                                                        
                                                                       
  
                    
                                            
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Cti\Result;

   
                                        
  
                                                                             
                                                       
  
                                                                 
   
final class CtiWebhookResult {
	   
                
   
                                                                                                    
                                                                                      
                                                                     
                                                                                      
                                                                                    
                                                                        
                                                                                     
                                                                     
                                                                                 
                                                                                      
                                                                          
                                                              
                                             
                                                                                   
   
                                                                  
   
                                                                                                                                   
    
	public function __construct(
		public readonly string $eventType,
		public readonly string $externalCallId,
		public readonly ?string $direction = null,
		public readonly ?string $fromNumber = null,
		public readonly ?string $toNumber = null,
		public readonly ?string $extension = null,
		public readonly ?string $userId = null,
		public readonly ?int $durationSeconds = null,
		public readonly ?string $recordingUrl = null,
		public readonly ?string $recordingExpiresAt = null,
		public readonly ?string $presenceState = null,
		public readonly ?string $queueName = null,
		public readonly ?string $agentSkill = null,
		public readonly array $raw = [],
	) {
	}                   
}           
