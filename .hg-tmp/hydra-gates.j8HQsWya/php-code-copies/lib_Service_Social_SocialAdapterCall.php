<?php

   
                              
  
                                                                             
                                                                              
                                                                              
                                       
  
                                                                          
                                                                               
                                                                           
                                                                              
                           
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                                                                               
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Social;

   
                                                          
  
                                                                                                                                               
   
class SocialAdapterCall {
	   
                
   
                                                                      
                                                                                   
                                                                                                  
                                                                                                             
   
                
    
	public function __construct(
		public readonly string $method,
		public readonly string $path,
		public readonly array $payload = [],
		public readonly array $headers = [],
	) {
	}                   

	   
                                                                          
                       
   
                                                  
   
                                                                                                                                                
    
	public function body(): ?string {
		if ($this->payload === []) {
			return null;
		}

		$encoded = json_encode($this->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($encoded === false) {
			return null;
		}

		return $encoded;
	}            

	   
                                                                               
   
                                                      
   
                                                                                                                                                
    
	public function requestHeaders(): array {
		if ($this->payload === []) {
			return $this->headers;
		}

		return array_merge(['Content-Type' => 'application/json'], $this->headers);
	}                      
}           
