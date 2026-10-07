<?php

   
                           
  
                                                                               
                                                                               
                                                                  
  
                                                                            
                                                                       
                                                                              
                                                                              
                                                                          
                                                                            
                                                                               
                                                               
                                                                        
                                                                               
                                 
  
                                                                                
                                                                             
                        
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                         
  
                                                                                                                                               
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Social;

   
                                 
  
                                                                                                                                               
   
class BlueskyAdapter extends AbstractSocialAdapter {
	   
                                                  
   
               
    
	public const POST_COLLECTION = 'app.bsky.feed.post';

	   
                                               
   
                                    
   
                                                                                                                                                
    
	public function network(): string {
		return 'bluesky';
	}               

	   
                                                                          
   
                                           
   
                                                                                                                                                  
    
	public function brokerProvider(): string {
		return 'bluesky';
	}                      

	   
                                    
   
                                    
   
                                                                                                                         
    
	public function bodyLimit(): int {
		return 300;
	}                 

	   
                                          
   
                                
   
                                                                                                                         
    
	public function maxMedia(): int {
		return 4;
	}                

	   
                                                                             
                                             
   
                                                           
   
                                          
   
                                                                                                                                                
    
	public function publishCall(SocialPublishRequest $request): SocialAdapterCall {
		return new SocialAdapterCall(
			method: 'POST',
			path: '/xrpc/com.atproto.repo.createRecord',
			payload: [
				'repo' => $request->externalAccountId,
				'collection' => self::POST_COLLECTION,
				'record' => [
					'$type' => self::POST_COLLECTION,
					'text' => $request->bodyWithLink(),
					'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
					'langs' => ['nl'],
				],
			],
		);
	}                   

	   
                                                                           
   
                                                           
   
                              
   
                                                                                                                                                
    
	protected function readPublishedId(array $payload): string {
		return (string)($payload['uri'] ?? '');
	}                       

	   
                                                                           
                                                    
   
                                                           
                                                                  
   
                                                          
   
                                                                                                                                                
    
	protected function readPublishedUrl(array $payload, SocialPublishRequest $request): string {
		$uri = (string)($payload['uri'] ?? '');
		$handle = ltrim($request->handle, '@');
		if ($uri === '' || $handle === '') {
			return '';
		}

		$parts = explode('/', $uri);
		$recordKey = (string)end($parts);
		if ($recordKey === '') {
			return '';
		}

		return 'https://bsky.app/profile/' . rawurlencode($handle) . '/post/' . rawurlencode($recordKey);
	}                        

	   
                                                                           
                                                                          
                         
   
                                                
                                                                        
   
                                                                         
   
                                                                                                                                                
    
	public function metricsCall(string $externalId, array $account): ?SocialAdapterCall {
		$actor = trim((string)($account['externalAccountId'] ?? ''));
		if ($actor === '' || trim($externalId) === '') {
			return null;
		}

		return new SocialAdapterCall(
			method: 'GET',
			path: '/xrpc/app.bsky.feed.getAuthorFeed?actor=' . rawurlencode($actor) . '&limit=100',
		);
	}                   

	   
                                                                           
                                                                             
                                            
   
                                                          
                                                
   
                                                                                                          
   
                                                                                                                                                
    
	public function normaliseMetrics(array $payload, string $externalId = ''): array {
		$feed = ($payload['feed'] ?? []);
		if (is_array($feed) === false || $externalId === '') {
			return self::noMetrics();
		}

		foreach ($feed as $entry) {
			$post = ($entry['post'] ?? null);
			if (is_array($post) === false || (string)($post['uri'] ?? '') !== $externalId) {
				continue;
			}

			return [
				'views' => 0,
				'likes' => $this->readInt(payload: $post, path: ['likeCount']),
				'comments' => $this->readInt(payload: $post, path: ['replyCount']),
				'shares' => $this->readInt(payload: $post, path: ['repostCount']),
				'clicks' => 0,
			];
		}

		return self::noMetrics();
	}                        

	   
                                                         
   
                                                         
   
                                                                         
   
                                                                                                                                                
    
	public function followersCall(array $account): ?SocialAdapterCall {
		$actor = trim((string)($account['externalAccountId'] ?? ''));
		if ($actor === '') {
			return null;
		}

		return new SocialAdapterCall(
			method: 'GET',
			path: '/xrpc/app.bsky.actor.getProfile?actor=' . rawurlencode($actor),
		);
	}                     

	   
                                      
   
                                                             
   
                                   
   
                                                                                                                                                
    
	public function readFollowers(array $payload): int {
		return $this->readInt(payload: $payload, path: ['followersCount']);
	}                     

	   
                                  
   
                                                           
   
                                                      
   
                                                                                                                                                 
    
	public function composerUrl(SocialPublishRequest $request): string {
		return 'https://bsky.app/intent/compose?text=' . rawurlencode($request->bodyWithLink());
	}                   
}           
