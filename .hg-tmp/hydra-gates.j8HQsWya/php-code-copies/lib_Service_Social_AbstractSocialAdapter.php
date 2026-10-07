<?php

   
                                  
  
                                                                               
                                                                         
                                               
  
                                                                               
                                                                         
                                                                             
                                                                          
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                                                                               
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Social;

   
                                              
  
                                                                                                                                               
  
                                                                  
                                                                    
                                                                        
                                                                           
                                                                          
   
abstract class AbstractSocialAdapter implements SocialNetworkAdapter {
	   
                
   
                                                                 
   
                
    
	public function __construct(protected readonly SocialBrokerGateway $gateway) {
	}                   

	   
                                           
   
                                
   
                                                                                                                         
    
	public function maxMedia(): int {
		return 1;
	}                

	   
                                           
   
                                                        
   
                                                                                                                                     
    
	public function costPerPost(): float {
		return 0.0;
	}                   

	   
                                                     
   
                                                      
   
                                                                                                                                     
    
	public function costPerRead(): float {
		return 0.0;
	}                   

	   
                                                                     
   
                                                           
   
                                             
   
                                                                                                                                        
    
	public function publish(SocialPublishRequest $request): SocialPublishOutcome {
		$refusal = $this->refuseWhenNotConfigured();
		if ($refusal !== null) {
			return $refusal;
		}

		$result = $this->send(call: $this->publishCall(request: $request), request: $request);
		if ($result->accepted === false) {
			return SocialPublishOutcome::fromGatewayFailure(result: $result);
		}

		return SocialPublishOutcome::published(
			externalId: $this->readPublishedId(payload: $result->body),
			url: $this->readPublishedUrl(payload: $result->body, request: $request),
			cost: $this->costPerPost(),
		);
	}               

	   
                                                                               
   
                                                                
                                                         
   
                                                        
   
                                                                                                                                                
   
                                                                         
                                                                      
    
	public function metricsCall(string $externalId, array $account): ?SocialAdapterCall {
		return null;
	}                   

	   
                          
   
                                                                     
                                                         
   
                                                                                                          
   
                                                                                                                                                
   
                                                                 
    
	public function normaliseMetrics(array $payload, string $externalId = ''): array {
		return self::noMetrics();
	}                        

	   
                                    
   
                                                         
   
                                                        
   
                                                                                                                                                
   
                                                                 
    
	public function followersCall(array $account): ?SocialAdapterCall {
		return null;
	}                     

	   
                                 
   
                                                                     
   
                                   
   
                                                                                                                                                
   
                                                                 
    
	public function readFollowers(array $payload): int {
		return 0;
	}                     

	   
                                                                           
                                                                 
   
                                                                                               
   
                                                                                                                                                
    
	public static function noMetrics(): array {
		return ['views' => 0, 'likes' => 0, 'comments' => 0, 'shares' => 0, 'clicks' => 0];
	}                 

	   
                                                                        
   
                                                                                        
   
                                                                                                                                                  
    
	protected function refuseWhenNotConfigured(): ?SocialPublishOutcome {
		$readiness = $this->gateway->readiness(brokerProvider: $this->brokerProvider());
		if ($readiness['state'] !== SocialBrokerGateway::NOT_CONFIGURED) {
			return null;
		}

		return SocialPublishOutcome::refused(
			code: SocialGatewayResult::NOT_CONFIGURED,
			reason: $readiness['reason'],
		);
	}                               

	   
                                
   
                                                                     
                                                                                      
   
                                               
   
                                                                                                                                        
    
	protected function send(SocialAdapterCall $call, SocialPublishRequest $request): SocialGatewayResult {
		return $this->gateway->request(
			credentialRef: $request->credentialRef,
			method: $call->method,
			path: $call->path,
			headers: $call->requestHeaders(),
			body: $call->body(),
			actingUserId: $request->actingUserId,
		);
	}            

	   
                                                        
   
                                                           
   
                                                                         
   
                                                                                                                                                
    
	protected function readPublishedId(array $payload): string {
		return (string)($payload['id'] ?? '');
	}                       

	   
                                                     
   
                                                           
                                                                                                      
   
                                                   
   
                                                                          
                                                           
   
                                                                                                                                                
    
	protected function readPublishedUrl(array $payload, SocialPublishRequest $request): string {
		foreach (['url', 'permalink', 'uri'] as $field) {
			$value = ($payload[$field] ?? null);
			if (is_string($value) === true && $value !== '') {
				return $value;
			}
		}

		return '';
	}                        

	   
                                                                                 
   
                                                     
                                                               
   
                                                                          
   
                                                                                                                                                
    
	protected function readInt(array $payload, array $path): int {
		$node = $payload;
		foreach ($path as $key) {
			if (is_array($node) === false || array_key_exists($key, $node) === false) {
				return 0;
			}

			$node = $node[$key];
		}

		if (is_numeric($node) === false) {
			return 0;
		}

		return (int)$node;
	}               
}           
