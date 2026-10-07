<?php

   
                                               
  
                                                                         
                                                                          
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                      
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\AppFramework\Http;
use RuntimeException;

   
                                                  
  
                                                                                                      
   
class ServiceAccountUnavailableException extends RuntimeException {
	   
                                         
   
                           
                                                                                                       
    
	public function getStatus(): int {
		return Http::STATUS_SERVICE_UNAVAILABLE;
	}                 
}           
