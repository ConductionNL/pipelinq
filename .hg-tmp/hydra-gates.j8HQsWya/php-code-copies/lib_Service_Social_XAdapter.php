<?php

   
                     
  
                                                                               
                                                                              
                                                                              
                                                                              
                                                         
  
                                                                       
                                                                             
                                                                              
                                                                             
                                                                              
                                                                 
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                          
  
                                                                                                                                               
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Social;

   
                            
  
                                                                                                                                               
  
                                                                             
                                                                               
                                                                               
                                        
   
class XAdapter extends AbstractSocialAdapter {
	   
                                                                      
                                                                          
                                                  
   
               
    
	public const BUDGET_PROVIDER = 'x';

	   
                                                                            
                                                                         
                                                          
   
              
    
	public const COST_PER_POST_EUR = 0.05;

	   
                                                                           
                                                                          
                                          
   
              
    
	public const COST_PER_READ_EUR = 0.01;

	   
                                               
   
                                    
   
                                                                                                                                                
    
	public function network(): string {
		return 'x';
	}               

	   
                        
   
                                           
   
                                                                                                                                                  
    
	public function brokerProvider(): string {
		return 'x';
	}                      

	   
                                                                          
                                    
   
                                    
   
                                                                                                                         
    
	public function bodyLimit(): int {
		return 280;
	}                 

	   
                                    
   
                                
   
                                                                                                                         
    
	public function maxMedia(): int {
		return 4;
	}                

	   
                                               
   
                                    
   
                                                                                                                                     
    
	public function costPerPost(): float {
		return self::COST_PER_POST_EUR;
	}                   

	   
                            
   
                                    
   
                                                                                                                                     
    
	public function costPerRead(): float {
		return self::COST_PER_READ_EUR;
	}                   

	   
                   
   
                                                           
   
                                          
   
                                                                                                                                                
    
	public function publishCall(SocialPublishRequest $request): SocialAdapterCall {
		return new SocialAdapterCall(
			method: 'POST',
			path: '/2/tweets',
			payload: ['text' => $request->bodyWithLink()],
		);
	}                   

	   
                                    
   
                                                           
   
                               
   
                                                                                                                                                
    
	protected function readPublishedId(array $payload): string {
		$data = ($payload['data'] ?? []);
		if (is_array($data) === false) {
			return '';
		}

		return (string)($data['id'] ?? '');
	}                       

	   
                                                           
   
                                                           
                                                                  
   
                                                          
   
                                                                                                                                                
    
	protected function readPublishedUrl(array $payload, SocialPublishRequest $request): string {
		$id = $this->readPublishedId(payload: $payload);
		$handle = ltrim($request->handle, '@');
		if ($id === '' || $handle === '') {
			return '';
		}

		return 'https://x.com/' . rawurlencode($handle) . '/status/' . rawurlencode($id);
	}                        

	   
                                                                 
   
                                          
                                                                      
   
                                                                      
   
                                                                                                                                                
   
                                                                         
                
    
	public function metricsCall(string $externalId, array $account): ?SocialAdapterCall {
		if (trim($externalId) === '') {
			return null;
		}

		return new SocialAdapterCall(
			method: 'GET',
			path: '/2/tweets/' . rawurlencode($externalId) . '?tweet.fields=public_metrics',
		);
	}                   

	   
                                                                            
                                           
   
                                                           
                                                                        
   
                                                                                                          
   
                                                                                                                                                
   
                                                                 
    
	public function normaliseMetrics(array $payload, string $externalId = ''): array {
		$base = ['data', 'public_metrics'];

		return [
			'views' => $this->readInt(payload: $payload, path: array_merge($base, ['impression_count'])),
			'likes' => $this->readInt(payload: $payload, path: array_merge($base, ['like_count'])),
			'comments' => $this->readInt(payload: $payload, path: array_merge($base, ['reply_count'])),
			'shares' => $this->readInt(payload: $payload, path: array_merge($base, ['retweet_count'])),
			'clicks' => $this->readInt(payload: $payload, path: array_merge($base, ['url_link_clicks'])),
		];
	}                        

	   
                                                                  
   
                                                                      
   
                                               
   
                                                                                                                                                
   
                                                                           
                                            
    
	public function followersCall(array $account): ?SocialAdapterCall {
		return new SocialAdapterCall(method: 'GET', path: '/2/users/me?user.fields=public_metrics');
	}                     

	   
                                                       
   
                                                           
   
                                   
   
                                                                                                                                                
    
	public function readFollowers(array $payload): int {
		return $this->readInt(payload: $payload, path: ['data', 'public_metrics', 'followers_count']);
	}                     

	   
                            
   
                                                           
   
                                                      
   
                                                                                                                                                 
    
	public function composerUrl(SocialPublishRequest $request): string {
		return 'https://x.com/intent/post?text=' . rawurlencode($request->bodyWithLink());
	}                   
}           
