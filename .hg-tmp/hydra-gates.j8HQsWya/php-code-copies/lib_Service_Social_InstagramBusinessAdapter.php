<?php

   
                                     
  
                                                                               
                                                                              
                                                                        
                        
  
                                                                           
                                                                       
                                                                              
                                                                       
                                                                            
                                                                             
                              
  
                                                                           
                                                       
  
                                                                             
                                         
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                                                   
  
                                                                                                                                               
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Social;

   
                                                         
  
                                                                                                                                               
  
                                                                  
                                                                    
                                                                        
                                                                           
                                                                          
   
class InstagramBusinessAdapter extends FacebookPageAdapter {
	   
                                               
   
                                    
   
                                                                                                                                                
    
	public function network(): string {
		return 'instagram';
	}               

	   
                                             
   
                                    
   
                                                                                                                         
    
	public function bodyLimit(): int {
		return 2200;
	}                 

	   
                                                           
   
                                                                       
                                                                           
               
   
                                                           
   
                                                    
   
                                                                                                                                                
    
	public function publishCall(SocialPublishRequest $request): SocialAdapterCall {
		$media = $request->firstPublicMedia();

		return new SocialAdapterCall(
			method: 'POST',
			path: '/' . self::GRAPH_VERSION . '/' . rawurlencode($request->externalAccountId) . '/media',
			payload: [
				'image_url' => (string)($media['url'] ?? ''),
				'caption' => $request->bodyWithLink(),
			],
		);
	}                   

	   
                                                                
   
                                                           
                                                                       
   
                                                  
   
                                                                                                                                                
    
	public function publishContainerCall(SocialPublishRequest $request, string $containerId): SocialAdapterCall {
		return new SocialAdapterCall(
			method: 'POST',
			path: '/' . self::GRAPH_VERSION . '/' . rawurlencode($request->externalAccountId) . '/media_publish',
			payload: ['creation_id' => $containerId],
		);
	}                            

	   
                                                                      
   
                                                           
   
                                             
   
                                                                                                                                                
    
	public function publish(SocialPublishRequest $request): SocialPublishOutcome {
		$refusal = $this->refuseWhenNotConfigured();
		if ($refusal !== null) {
			return $refusal;
		}

		if ($request->firstPublicMedia() === null) {
			return SocialPublishOutcome::refused(
				code: SocialGatewayResult::REJECTED_BY_NETWORK,
				reason: 'Instagram needs an image or a video with a public address. Add media to this post and try again.',
			);
		}

		$container = $this->send(call: $this->publishCall(request: $request), request: $request);
		if ($container->accepted === false) {
			                                                               
			                                                                  
			                                                 
			return SocialPublishOutcome::fromGatewayFailure(result: $container);
		}

		$containerId = (string)($container->body['id'] ?? '');
		if ($containerId === '') {
			return SocialPublishOutcome::refused(
				code: SocialGatewayResult::REJECTED_BY_NETWORK,
				reason: 'Instagram accepted the media but named no container, so there is nothing to publish.',
			);
		}

		$published = $this->send(
			call: $this->publishContainerCall(request: $request, containerId: $containerId),
			request: $request,
		);
		if ($published->accepted === false) {
			return SocialPublishOutcome::fromGatewayFailure(result: $published);
		}

		return SocialPublishOutcome::published(
			externalId: $this->readPublishedId(payload: $published->body),
			url: $this->readPublishedUrl(payload: $published->body, request: $request),
		);
	}               

	   
                                                                       
   
                                                           
                                                               
   
                                          
   
                                                                         
                                                                           
                
   
                                                                                                                                                
    
	protected function readPublishedUrl(array $payload, SocialPublishRequest $request): string {
		return '';
	}                        

	   
                                                                       
                                           
   
                                           
                                                                      
   
                                                                      
   
                                                                                                                                                
   
                                                                            
                   
    
	public function metricsCall(string $externalId, array $account): ?SocialAdapterCall {
		if (trim($externalId) === '') {
			return null;
		}

		return new SocialAdapterCall(
			method: 'GET',
			path: '/' . self::GRAPH_VERSION . '/' . rawurlencode($externalId)
				. '/insights?metric=views,likes,comments,shares',
		);
	}                   

	   
                                                                            
                                            
   
                                                              
                                                                         
   
                                                                                                          
   
                                                                                                                                                
   
                                                                 
    
	public function normaliseMetrics(array $payload, string $externalId = ''): array {
		$series = $this->seriesByName(payload: $payload);

		return [
			'views' => ($series['views'] ?? 0),
			'likes' => ($series['likes'] ?? 0),
			'comments' => ($series['comments'] ?? 0),
			'shares' => ($series['shares'] ?? 0),
			'clicks' => 0,
		];
	}                        

	   
                                                                       
                                                                         
   
                                                                        
   
                                                   
   
                                                                                                                                                 
   
                                                                       
                                                
    
	public function composerUrl(SocialPublishRequest $request): string {
		return 'https://www.instagram.com/';
	}                   
}           
