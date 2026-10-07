<?php

   
                                 
  
                                                                              
                                                                
  
                                                                            
                                                                               
                                                             
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                                                                       
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Social;

   
                                                    
  
                                                                                                                                       
   
class SocialPublishOutcome {
	   
                                                            
   
                                                                
                                                                          
                                                                
                                                      
                                                                                          
                                                                
   
                
    
	private function __construct(
		public readonly bool $accepted,
		public readonly string $externalId,
		public readonly string $url,
		public readonly float $cost,
		public readonly string $failureCode,
		public readonly string $failureReason,
	) {
	}                   

	   
                                  
   
                                                                
                                                                                  
                                                   
   
                             
   
                                                                                                                                        
    
	public static function published(string $externalId, string $url = '', float $cost = 0.0): self {
		return new self(
			accepted: true,
			externalId: $externalId,
			url: $url,
			cost: $cost,
			failureCode: '',
			failureReason: '',
		);
	}                 

	   
                                                
   
                                                                       
                                                         
                                                                                     
   
                             
   
                                                                                                                                        
    
	public static function refused(string $code, string $reason, float $cost = 0.0): self {
		return new self(
			accepted: false,
			externalId: '',
			url: '',
			cost: $cost,
			failureCode: $code,
			failureReason: $reason,
		);
	}               

	   
                                                                                
   
                                                             
   
                             
   
                                                                                                                                        
    
	public static function fromGatewayFailure(SocialGatewayResult $result): self {
		return self::refused(code: $result->failureCode, reason: $result->failureReason);
	}                          
}           
