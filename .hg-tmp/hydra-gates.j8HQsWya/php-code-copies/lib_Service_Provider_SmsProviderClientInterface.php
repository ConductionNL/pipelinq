<?php

   
                                       
  
                                                                    
                                                                     
                                                                   
                    
  
                    
                                          
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Provider;

   
                                                            
  
                                                                       
                                                                   
                                                  
                                                                 
  
                                                                   
   
interface SmsProviderClientInterface {
	   
                      
   
                                                            
                                        
   
                                                                     
                                                                                                            
   
                                                              
    
	public function send(string $toNumber, string $body): array;

	   
                                                         
   
                                            
                                                                        
   
                                                                       
                                                              
    
	public function verifySignature(string $rawBody, string $signature): bool;

	   
                                                                      
   
                              
                                                              
    
	public function getVendor(): string;
}               
