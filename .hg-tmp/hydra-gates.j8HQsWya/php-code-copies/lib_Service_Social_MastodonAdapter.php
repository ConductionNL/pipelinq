<?php

   
                            
  
                                                                           
                                                                       
                                                                             
                                              
  
                                                                     
                                                                              
                                                                         
                                                                        
                              
  
                                                                            
                                                                        
                                                                           
              
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                        
  
                                                                                                                                               
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Social;

   
                          
  
                                                                                                                                               
   
class MastodonAdapter extends AbstractSocialAdapter {
	   
                                               
   
                                    
   
                                                                                                                                                
    
	public function network(): string {
		return 'mastodon';
	}               

	   
                                                
   
                                           
   
                                                                                                                                                  
    
	public function brokerProvider(): string {
		return 'mastodon';
	}                      

	   
                                                                            
                                                          
   
                                    
   
                                                                                                                         
    
	public function bodyLimit(): int {
		return 500;
	}                 

	   
                                             
   
                                
   
                                                                                                                         
    
	public function maxMedia(): int {
		return 4;
	}                

	   
                                                                       
                                                                  
   
                                                           
   
                                          
   
                                                                                                                                                
    
	public function publishCall(SocialPublishRequest $request): SocialAdapterCall {
		return new SocialAdapterCall(
			method: 'POST',
			path: '/api/v1/statuses',
			payload: [
				'status' => $request->bodyWithLink(),
				'visibility' => 'public',
			],
		);
	}                   

	   
                                                                             
   
                                            
                                                                      
   
                                                                      
   
                                                                                                                                                
   
                                                                       
                                                                  
    
	public function metricsCall(string $externalId, array $account): ?SocialAdapterCall {
		if (trim($externalId) === '') {
			return null;
		}

		return new SocialAdapterCall(method: 'GET', path: '/api/v1/statuses/' . rawurlencode($externalId));
	}                   

	   
                                                                        
                                                                     
                   
   
                                                            
                                                                       
   
                                                                                                          
   
                                                                                                                                                
   
                                                                         
                                       
    
	public function normaliseMetrics(array $payload, string $externalId = ''): array {
		return [
			'views' => 0,
			'likes' => $this->readInt(payload: $payload, path: ['favourites_count']),
			'comments' => $this->readInt(payload: $payload, path: ['replies_count']),
			'shares' => $this->readInt(payload: $payload, path: ['reblogs_count']),
			'clicks' => 0,
		];
	}                        

	   
                                                                  
   
                                                                      
   
                                               
   
                                                                                                                                                
   
                                                                         
                                                      
    
	public function followersCall(array $account): ?SocialAdapterCall {
		return new SocialAdapterCall(method: 'GET', path: '/api/v1/accounts/verify_credentials');
	}                     

	   
                                               
   
                                                             
   
                                   
   
                                                                                                                                                
    
	public function readFollowers(array $payload): int {
		return $this->readInt(payload: $payload, path: ['followers_count']);
	}                     

	   
                                                             
   
                                                           
   
                                                      
   
                                                                                                                                                 
    
	public function composerUrl(SocialPublishRequest $request): string {
		return 'https://mastodon.social/share?text=' . rawurlencode($request->bodyWithLink());
	}                   
}           
