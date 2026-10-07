<?php

   
                                
  
                                                                              
                            
  
                                                                              
                                                                              
                                                                              
                                                                            
                                                                              
                                                                         
                                                                          
                                  
  
                                                                              
                                                                          
                                                          
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                                                                       
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Social;

   
                                                        
  
                                                                                                                                       
   
class SocialGatewayResult {
	   
                                                                      
                                            
   
               
    
	public const NOT_CONFIGURED = 'not_configured';

	   
                                                                         
                                                           
   
               
    
	public const RELINK_NEEDED = 'relink_needed';

	   
                                                                       
               
   
               
    
	public const BUDGET_EXHAUSTED = 'budget_exhausted';

	   
                                                                          
                                                                  
   
               
    
	public const NOT_PERMITTED = 'not_permitted';

	   
                                                                          
   
               
    
	public const REJECTED_BY_NETWORK = 'rejected_by_network';

	   
                                                                         
   
               
    
	public const UNAVAILABLE = 'unavailable';

	   
                                                                   
   
                           
    
	public const CODES = [
		self::NOT_CONFIGURED,
		self::RELINK_NEEDED,
		self::BUDGET_EXHAUSTED,
		self::NOT_PERMITTED,
		self::REJECTED_BY_NETWORK,
		self::UNAVAILABLE,
	];

	   
                                                
   
                           
    
	public const RETRYABLE = [
		self::REJECTED_BY_NETWORK,
		self::UNAVAILABLE,
	];

	   
                                                                            
   
                                                                       
                                                                            
                                                                
                                                                          
                                                                                         
   
                
    
	private function __construct(
		public readonly bool $accepted,
		public readonly int $status,
		public readonly array $body,
		public readonly string $failureCode,
		public readonly string $failureReason,
	) {
	}                   

	   
                                  
   
                                                
                                                                
   
                                    
   
                                                                                                                                        
    
	public static function succeeded(int $status, array $body): self {
		return new self(accepted: true, status: $status, body: $body, failureCode: '', failureReason: '');
	}                 

	   
                                                               
   
                                                       
                                                         
                                                                      
   
                                    
   
                                                                                                                                        
    
	public static function failed(string $code, string $reason, int $status = 0): self {
		$known = $code;
		if (in_array($code, self::CODES, true) === false) {
			                                                                    
			                                                                    
			                                                       
			$known = self::UNAVAILABLE;
			$reason = $reason . ' (unmapped failure: ' . $code . ')';
		}

		return new self(accepted: false, status: $status, body: [], failureCode: $known, failureReason: $reason);
	}              

	   
                                                           
   
                                                     
   
                                                                                                                                        
    
	public function isRetryable(): bool {
		return ($this->accepted === false && in_array($this->failureCode, self::RETRYABLE, true) === true);
	}                   
}           
