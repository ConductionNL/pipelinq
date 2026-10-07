<?php

   
                                
  
                                                                                     
  
                    
                                     
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Cti;

use OCA\Pipelinq\Service\Cti\Result\CtiCallResult;
use OCA\Pipelinq\Service\Cti\Result\CtiWebhookResult;

   
                                               
  
                                                                        
                                                                       
                                                                             
  
                                                                 
   
interface CtiAdapterInterface {
	   
                                                                      
   
                                       
                                                                                                                
    
	public function getPlatform(): string;

	   
                                                   
   
                                                                 
   
                                              
                                                                                                                
    
	public function handleInboundWebhook(array $payload): CtiWebhookResult;

	   
                               
   
                                                                              
                                                                           
                                                                      
   
                                                                     
                                                                                                                
    
	public function originateCall(string $extension, string $targetNumber, string $callerId): CtiCallResult;

	   
                                       
   
                                                                                
                                                              
   
                                                        
                                                         
   
                                                  
                                                                                                                
    
	public function verifyWebhookSignature(string $payload, string $signature): bool;
}               
