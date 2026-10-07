<?php

   
                            
  
                                                                                
  
                    
                                            
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Cti\Result;

   
                                                                     
  
                                                                                         
                                    
   
final class OriginateResult {
	   
                
   
                                                                    
                                                                           
                                                                     
                                                                   
                                                                
    
	public function __construct(
		public readonly bool $success,
		public readonly ?string $externalCallId = null,
		public readonly ?string $interactionId = null,
		public readonly ?string $error = null,
		public readonly ?string $platform = null,
	) {
	}                   

	   
                                           
   
                               
                                                                  
    
	public function toArray(): array {
		return [
			'success' => $this->success,
			'externalCallId' => $this->externalCallId,
			'interactionId' => $this->interactionId,
			'error' => $this->error,
			'platform' => $this->platform,
		];
	}               
}           
