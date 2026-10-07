<?php

   
                         
  
                                                                           
                                                                             
                                                                              
                                                                      
                                                   
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                                                                                                         
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Egress;

   
                                    
  
                                                                                                                                                                         
   
final class EgressResult {

	   
                                                   
   
               
    
	public const NOT_CONFIGURED = 'not_configured';

	   
                                                                            
   
               
    
	public const UNAVAILABLE = 'unavailable';

	   
                                                   
   
               
    
	public const REFUSED = 'refused';

	   
                                                              
   
               
    
	public const UNPARSABLE = 'unparsable';

	   
                                                                      
   
                           
    
	public const FAILURES = [
		self::NOT_CONFIGURED,
		self::UNAVAILABLE,
		self::REFUSED,
		self::UNPARSABLE,
	];

	   
                
   
                                                      
                                                                
                                                            
                                                                           
                                                                         
   
                                                                                                                                                                          
    
	public function __construct(
		public readonly bool $succeeded,
		public readonly int $status = 0,
		public readonly string $body = '',
		public readonly ?string $failure = null,
		public readonly string $reason = '',
	) {
	}                   

	   
                      
   
                                          
                                       
   
                
   
                                                                                                                                                                          
    
	public static function success(string $body, int $status = 200): self {
		return new self(succeeded: true, status: $status, body: $body);
	}               

	   
                                
   
                                                  
                                                       
                                                           
   
                
   
                                                                                                                                                                          
    
	public static function failed(string $failure, string $reason, int $status = 0): self {
		return new self(succeeded: false, status: $status, failure: $failure, reason: $reason);
	}              

	   
                                                       
   
                                         
   
                                                                                                                                                                          
    
	public function json(): ?array {
		if ($this->succeeded === false || $this->body === '') {
			return null;
		}

		$decoded = json_decode($this->body, true);
		if (is_array($decoded) === false) {
			return null;
		}

		return $decoded;
	}            
}           
