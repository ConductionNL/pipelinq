<?php

   
                            
  
                                                                             
                                                  
  
                    
                                            
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Cti\Result;

   
                      
  
                                                                                        
                                                                             
                                                                               
  
                                                                                         
                                    
   
final class ScreenPopResult {
	public const ACTION_NAVIGATE = 'navigate';
	public const ACTION_CHOOSER = 'chooser';
	public const ACTION_INTAKE = 'intake';

	   
                
   
                                                
                                                                                            
                                                           
                                                         
    
	public function __construct(
		public readonly string $action,
		public readonly array $matches = [],
		public readonly ?string $e164 = null,
		public readonly ?string $rawNumber = null,
	) {
	}                   

	   
                                                    
   
                               
                                                                                          
                                     
    
	public function toArray(): array {
		return [
			'action' => $this->action,
			'matches' => $this->matches,
			'e164' => $this->e164,
			'rawNumber' => $this->rawNumber,
		];
	}               
}           
