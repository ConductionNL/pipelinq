<?php

   
                                     
  
                                                                           
                                                                       
                                                                           
                                                                           
                                                                               
  
                    
                                         
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                                                                             
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Payment;

   
                                     
  
                                                                                                             
   
interface PaymentProviderInterface {
	   
                                                                             
   
                                                                                      
                                                                                   
                                                                                       
                                                                             
   
                                                                                        
   
                
   
                                                                                      
    
	public function setHttpTransport(?HttpTransport $transport): void;

	   
                                                 
   
                                                                                                 
                                                                                        
                                                                                           
   
                                                                                                           
   
                                                                                                              
                                                                                                              
    
	public function initiate(array $transactionData, float $amount, string $paymentMethod): array;

	   
                                                    
   
                                                                     
   
                                                                                             
   
                                                                                                              
    
	public function capture(string $sessionId): array;

	   
                                     
   
                                                     
                                                       
   
                                                                                                              
   
                                                                                                              
    
	public function refund(string $sessionId, string $reason): array;

	   
                                                                         
   
                                                                        
                                                                           
                                                                            
                                 
   
                                                                  
                                                                          
   
                                                   
   
                                                                                                              
    
	public function validateWebhook(string $rawPayload, string $signature): bool;

	   
                                                                          
   
                                                                  
   
                                                                                                                     
   
                                                                                                              
    
	public function parseWebhook(array $payload): array;

	   
                                                   
   
                                                                   
   
                                                                                                              
    
	public function testConnection(): array;

	   
                                                             
   
                  
   
                                                                                                              
    
	public function getName(): string;
}               
