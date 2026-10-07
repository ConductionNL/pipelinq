<?php

   
                           
  
                                                                               
                      
  
                                                                              
                                                                              
                                                                          
                                                                              
                                                                              
                                                                        
                                                                             
                    
  
                                                                               
                                                                              
                                                                           
                                                        
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                           
  
                                                                                                                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Social;

   
                                                               
  
                                                                                                                                                 
  
                                                                  
                                                                    
                                                                        
                                                                           
                                                                          
   
class ThreadsAdapter extends AbstractSocialAdapter {
	   
                                               
   
                                    
   
                                                                                                                                                
    
	public function network(): string {
		return 'threads';
	}               

	   
                                                                         
                                                          
   
                                   
   
                                                                                                                                                  
    
	public function brokerProvider(): string {
		return '';
	}                      

	   
                                     
   
                                    
   
                                                                                                                         
    
	public function bodyLimit(): int {
		return 500;
	}                 

	   
                                                     
   
                                                           
   
                                                    
   
                                                                                                                                                
    
	public function publishCall(SocialPublishRequest $request): SocialAdapterCall {
		$media = $request->firstPublicMedia();
		$payload = [
			'media_type' => 'TEXT',
			'text' => $request->bodyWithLink(),
		];

		if ($media !== null) {
			$payload['media_type'] = 'IMAGE';
			$payload['image_url'] = (string)($media['url'] ?? '');
		}

		return new SocialAdapterCall(
			method: 'POST',
			path: '/v1.0/' . rawurlencode($request->externalAccountId) . '/threads',
			payload: $payload,
		);
	}                   

	   
                                                                
   
                                                           
                                                                       
   
                                                  
   
                                                                                                                                                
    
	public function publishContainerCall(SocialPublishRequest $request, string $containerId): SocialAdapterCall {
		return new SocialAdapterCall(
			method: 'POST',
			path: '/v1.0/' . rawurlencode($request->externalAccountId) . '/threads_publish',
			payload: ['creation_id' => $containerId],
		);
	}                            

	   
                                                                          
                                                
   
                                                           
   
                                             
   
                                                                                                                                                  
    
	public function publish(SocialPublishRequest $request): SocialPublishOutcome {
		$refusal = $this->refuseWhenNotConfigured();
		if ($refusal !== null) {
			return $refusal;
		}

		$container = $this->send(call: $this->publishCall(request: $request), request: $request);
		if ($container->accepted === false) {
			return SocialPublishOutcome::fromGatewayFailure(result: $container);
		}

		$containerId = (string)($container->body['id'] ?? '');
		if ($containerId === '') {
			return SocialPublishOutcome::refused(
				code: SocialGatewayResult::REJECTED_BY_NETWORK,
				reason: 'Threads accepted the text but named no container, so there is nothing to publish.',
			);
		}

		$published = $this->send(
			call: $this->publishContainerCall(request: $request, containerId: $containerId),
			request: $request,
		);
		if ($published->accepted === false) {
			return SocialPublishOutcome::fromGatewayFailure(result: $published);
		}

		return SocialPublishOutcome::published(externalId: $this->readPublishedId(payload: $published->body));
	}               

	   
                                                      
   
                                                           
   
                                                      
   
                                                                                                                                                 
    
	public function composerUrl(SocialPublishRequest $request): string {
		return 'https://www.threads.net/intent/post?text=' . rawurlencode($request->bodyWithLink());
	}                   
}           
