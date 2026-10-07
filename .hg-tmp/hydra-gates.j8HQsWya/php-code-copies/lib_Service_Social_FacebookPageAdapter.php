<?php

   
                                
  
                                                                           
                                                                              
                                                                            
                                                                           
                                          
  
                                                              
                                                                  
                                                                               
                                                             
                                                   
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                             
  
                                                                                                                                               
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Social;

   
                   
  
                                                                                                                                               
   
class FacebookPageAdapter extends AbstractSocialAdapter {
	   
                                                                          
                                                                       
                                                     
   
               
    
	public const GRAPH_VERSION = 'v21.0';

	   
                                               
   
                                    
   
                                                                                                                                                
    
	public function network(): string {
		return 'facebook';
	}               

	   
                                                                         
   
                                           
   
                                                                                                                                                  
    
	public function brokerProvider(): string {
		return 'meta-graph';
	}                      

	   
                                                                          
                                                   
   
                                    
   
                                                                                                                         
    
	public function bodyLimit(): int {
		return 5000;
	}                 

	   
                                                                     
                                                                             
   
                                                           
   
                                          
   
                                                                                                                                                
    
	public function publishCall(SocialPublishRequest $request): SocialAdapterCall {
		$payload = ['message' => trim($request->body)];
		if ($request->link !== '') {
			$payload['link'] = $request->link;
		}

		return new SocialAdapterCall(
			method: 'POST',
			path: '/' . self::GRAPH_VERSION . '/' . rawurlencode($request->externalAccountId) . '/feed',
			payload: $payload,
		);
	}                   

	   
                                                                     
   
                                                           
                                                               
   
                                                          
   
                                                                           
                      
   
                                                                                                                                                
    
	protected function readPublishedUrl(array $payload, SocialPublishRequest $request): string {
		$id = (string)($payload['id'] ?? '');
		if ($id === '') {
			return '';
		}

		return 'https://www.facebook.com/' . str_replace('_', '/posts/', $id);
	}                        

	   
                                                                         
   
                                                                        
                                                                            
                                    
   
                                          
                                                                      
   
                                                                      
   
                                                                                                                                                
   
                                                                         
                          
    
	public function metricsCall(string $externalId, array $account): ?SocialAdapterCall {
		if (trim($externalId) === '') {
			return null;
		}

		return new SocialAdapterCall(
			method: 'GET',
			path: '/' . self::GRAPH_VERSION . '/' . rawurlencode($externalId)
				. '/insights?metric=post_video_views,post_clicks,post_reactions_by_type_total',
		);
	}                   

	   
                                                                         
                                                                           
                                                                   
   
                                                              
                                                                        
   
                                                                                                          
   
                                                                                                                                                
   
                                                                 
    
	public function normaliseMetrics(array $payload, string $externalId = ''): array {
		$series = $this->seriesByName(payload: $payload);

		return [
			'views' => ($series['post_video_views'] ?? 0),
			'likes' => ($series['post_reactions_by_type_total'] ?? 0),
			'comments' => 0,
			'shares' => 0,
			'clicks' => ($series['post_clicks'] ?? 0),
		];
	}                        

	   
                                                       
   
                                                              
   
                                                         
   
                                                                                                                                                
    
	protected function seriesByName(array $payload): array {
		$out = [];
		$data = ($payload['data'] ?? []);
		if (is_array($data) === false) {
			return $out;
		}

		foreach ($data as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$name = (string)($entry['name'] ?? '');
			$values = ($entry['values'] ?? []);
			if ($name === '' || is_array($values) === false || $values === []) {
				continue;
			}

			$first = $values[0];
			$value = 0;
			if (is_array($first) === true) {
				$value = ($first['value'] ?? 0);
			}

			if (is_array($value) === true) {
				$value = array_sum(array_map('intval', $value));
			}

			$out[$name] = (int)$value;
		}

		return $out;
	}                    

	   
                                                                         
                                                        
   
                                                         
   
                                               
   
                                                                                                                                                
   
                                                                        
                                             
    
	public function followersCall(array $account): ?SocialAdapterCall {
		return null;
	}                     

	   
                          
   
                                                           
   
                                                    
   
                                                                                                                                                 
    
	public function composerUrl(SocialPublishRequest $request): string {
		if ($request->link !== '') {
			return 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode($request->link);
		}

		return 'https://www.facebook.com/';
	}                   
}           
