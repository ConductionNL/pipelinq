<?php

   
                            
  
                                                                          
                                                                               
                                                                             
                                                                            
  
                      
                                        
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use Exception;

   
                                     
  
                                                                                      
                
   
class PortalException extends Exception {
	   
                
   
                                            
                                                           
                                                        
                                                                                 
    
	public function __construct(
		private int $status,
		private string $errorCode,
		string $message,
		private array $context = [],
	) {
		parent::__construct(message: $message);
	}                   

	   
                         
   
                           
                                                                                       
                 
    
	public function getStatus(): int {
		return $this->status;
	}                 

	   
                                  
   
                                  
                                                                                       
                 
    
	public function getErrorCode(): string {
		return $this->errorCode;
	}                    

	   
                                                    
   
                                             
                                                                                       
                 
    
	public function getContext(): array {
		return $this->context;
	}                  

	   
                                               
   
                                          
                                                                                       
                 
    
	public function toBody(): array {
		return array_merge(['errorCode' => $this->errorCode, 'message' => $this->getMessage()], $this->context);
	}              
}           
