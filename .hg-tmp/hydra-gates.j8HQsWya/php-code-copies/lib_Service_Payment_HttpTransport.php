<?php

   
                          
  
                                                                            
                                                                             
                                                                       
                                                                             
                                                                       
  
                    
                                         
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                                                                             
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Payment;

   
                                                                           
  
                                                                                                             
   
interface HttpTransport {
	   
                                                        
   
                                                           
                                    
                                                          
                                                       
   
                                                                       
   
                                                                                                              
    
	public function request(string $method, string $url, array $headers = [], ?string $body = null): array;
}               
