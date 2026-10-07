<?php

   
                                 
  
                                                                            
                                                                           
                                                                             
                
  
                                                                              
                                                                              
                                                                        
                                                                              
                                                                     
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                                                                               
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Social;

   
                                                             
  
                                                                                                                                               
   
interface SocialNetworkAdapter {
	   
                                                                
   
                                    
   
                                                                                                                                                
    
	public function network(): string;

	   
                                                                       
                                                                  
   
                                                  
   
                                                                                                                                                  
    
	public function brokerProvider(): string;

	   
                                                         
   
                                    
   
                                                                                                                         
    
	public function bodyLimit(): int;

	   
                                                        
   
                                
   
                                                                                                                         
    
	public function maxMedia(): int;

	   
                                                                  
   
                                              
   
                                                                                                                                     
    
	public function costPerPost(): float;

	   
                                                                          
                                                                            
                                                           
   
                                            
   
                                                                                                                                     
    
	public function costPerRead(): float;

	   
                                                               
   
                                                                         
                                                                     
   
                                                           
   
                                                  
   
                                                                                                                                                
    
	public function publishCall(SocialPublishRequest $request): SocialAdapterCall;

	   
                                              
   
                                                           
   
                                                                                  
   
                                                                                                                                        
    
	public function publish(SocialPublishRequest $request): SocialPublishOutcome;

	   
                                                                          
                                                     
   
                                                                
                                                                                   
   
                                                                           
   
                                                                                                                                                
    
	public function metricsCall(string $externalId, array $account): ?SocialAdapterCall;

	   
                                                                           
                                                                       
                                   
   
                                                                     
                                                                                                   
   
                                                                                                          
   
                                                                                                                                                
    
	public function normaliseMetrics(array $payload, string $externalId = ''): array;

	   
                                                                          
                                                           
   
                                                         
   
                                                        
   
                                                                                                                                                
    
	public function followersCall(array $account): ?SocialAdapterCall;

	   
                                                     
   
                                                                     
   
                                                                             
   
                                                                                                                                                
    
	public function readFollowers(array $payload): int;

	   
                                                                            
                       
   
                                                           
   
                                                                
   
                                                                                                                                                 
    
	public function composerUrl(SocialPublishRequest $request): string;
}               
