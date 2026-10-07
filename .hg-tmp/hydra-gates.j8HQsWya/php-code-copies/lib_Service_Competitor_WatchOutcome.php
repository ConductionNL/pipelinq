<?php

   
                         
  
                                                                          
                                                                            
                                                                       
                                                                               
           
  
                                                                              
                                                                        
                                                
  
                    
                                            
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                                                                                              
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Competitor;

   
                               
  
                                                                                                                                                              
   
final class WatchOutcome {

	   
                                                        
   
               
    
	public const OK = 'ok';

	   
                
   
                                                                   
                                                                         
                                                                      
                                                                         
   
                                                                                                                                                               
    
	public function __construct(
		public readonly string $outcome,
		public readonly string $reason = '',
		public readonly array $items = [],
		public readonly array $state = [],
	) {
	}                   

	   
                     
   
                                                                      
                                                        
   
                
   
                                                                                                                                                               
    
	public static function seen(array $items, array $state = []): self {
		return new self(outcome: self::OK, items: $items, state: $state);
	}            

	   
                                                      
   
                                                           
                              
   
                
   
                                                                                                                                                               
    
	public static function failed(string $outcome, string $reason): self {
		return new self(outcome: $outcome, reason: $reason);
	}              

	   
                              
   
                
   
                                                                                                                                                               
    
	public function succeeded(): bool {
		return ($this->outcome === self::OK);
	}                 
}           
