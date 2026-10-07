<?php

   
                                  
  
                                                                             
                                                                      
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                  
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use RuntimeException;

   
                                                                    
  
                                                                                           
  
                                                                          
   
class HaalCentraalException extends RuntimeException {

	   
                                                                             
   
                
    
	private int $statusCode;

	   
                                                         
   
                    
    
	private ?string $correlationId;

	   
                
   
                                                                                           
                                                                                       
                                                                                  
                                               
    
	public function __construct(
		string $message,
		int $statusCode = 0,
		?string $correlationId = null,
		?\Throwable $previous = null,
	) {
		parent::__construct(message: $message, code: $statusCode, previous: $previous);
		$this->statusCode = $statusCode;
		$this->correlationId = $correlationId;
	}                   

	   
                                                   
   
               
                                                                                       
                 
    
	public function getStatusCode(): int {
		return $this->statusCode;
	}                     

	   
                                     
   
                       
                                                                                       
                 
    
	public function getCorrelationId(): ?string {
		return $this->correlationId;
	}                        
}           
