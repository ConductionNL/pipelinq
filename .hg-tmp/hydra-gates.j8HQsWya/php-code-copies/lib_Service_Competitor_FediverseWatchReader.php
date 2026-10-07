<?php

   
                                 
  
                                                                             
                                                                           
                                                                               
                                                                              
                                                                              
                                                         
  
                                                                            
                                                                               
                                                                   
  
                    
                                            
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                                                                                                       
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Competitor;

use OCA\Pipelinq\Service\Egress\ConnectorEgress;
use OCA\Pipelinq\Service\Egress\EgressResult;

   
                                         
  
                                                                                                                                                                       
  
                                                                      
                                                                    
                                                                         
                                                                   
                                                                         
                         
   
class FediverseWatchReader {

	   
                                                                          
   
               
    
	public const BLUESKY_APPVIEW = 'https://public.api.bsky.app';

	   
                                          
   
            
    
	public const MAX_POSTS = 25;

	   
                
   
                                                            
   
                                                                                                                                                                        
    
	public function __construct(
		private ConnectorEgress $egress,
	) {
	}                   

	   
                           
   
                                                                                                     
                                                                        
   
                                                                 
   
                                                                                                                                                                        
    
	public function read(string $handle, string $sourceId = ''): WatchOutcome {
		$trimmed = ltrim(trim($handle), '@');
		if ($trimmed === '') {
			return WatchOutcome::failed(outcome: EgressResult::NOT_CONFIGURED, reason: 'This watch has no handle.');
		}

		if (str_contains($trimmed, '@') === true) {
			return $this->mastodon(handle: $trimmed, sourceId: $sourceId);
		}

		return $this->bluesky(handle: $trimmed, sourceId: $sourceId);
	}            

	   
                                         
   
                                          
                                                       
   
                        
    
	private function mastodon(string $handle, string $sourceId): WatchOutcome {
		[$user, $instance] = explode('@', $handle, 2);
		$base = ('https://' . $instance);
		$lookup = $this->egress->readUrl(
			configKey: CompetitorWatchService::SOURCE_KEY,
			url: ($base . '/api/v1/accounts/lookup'),
			config: ['query' => ['acct' => ($user . '@' . $instance)]],
			sourceId: $sourceId
		);
		if ($lookup->succeeded === false) {
			return WatchOutcome::failed(outcome: (string)$lookup->failure, reason: $lookup->reason);
		}

		$account = $lookup->json();
		$accountId = trim((string)($account['id'] ?? ''));
		if ($accountId === '') {
			return WatchOutcome::failed(
				outcome: EgressResult::UNPARSABLE,
				reason: 'The account ' . $handle . ' was not found on ' . $instance . '.'
			);
		}

		$statuses = $this->egress->readUrl(
			configKey: CompetitorWatchService::SOURCE_KEY,
			url: ($base . '/api/v1/accounts/' . rawurlencode($accountId) . '/statuses'),
			config: ['query' => ['limit' => (string)self::MAX_POSTS, 'exclude_replies' => 'true']],
			sourceId: $sourceId
		);
		if ($statuses->succeeded === false) {
			return WatchOutcome::failed(outcome: (string)$statuses->failure, reason: $statuses->reason);
		}

		$rows = $statuses->json();
		if ($rows === null) {
			return WatchOutcome::failed(outcome: EgressResult::UNPARSABLE, reason: 'The timeline of ' . $handle . ' could not be read.');
		}

		return WatchOutcome::seen(items: $this->fromMastodon(rows: $rows, handle: $handle));
	}                

	   
                                           
   
                                            
                                                       
   
                        
    
	private function bluesky(string $handle, string $sourceId): WatchOutcome {
		$feed = $this->egress->readUrl(
			configKey: CompetitorWatchService::SOURCE_KEY,
			url: (self::BLUESKY_APPVIEW . '/xrpc/app.bsky.feed.getAuthorFeed'),
			config: ['query' => ['actor' => $handle, 'limit' => (string)self::MAX_POSTS]],
			sourceId: $sourceId
		);
		if ($feed->succeeded === false) {
			return WatchOutcome::failed(outcome: (string)$feed->failure, reason: $feed->reason);
		}

		$decoded = $feed->json();
		if ($decoded === null || is_array($decoded['feed'] ?? null) === false) {
			return WatchOutcome::failed(outcome: EgressResult::UNPARSABLE, reason: 'The feed of ' . $handle . ' could not be read.');
		}

		return WatchOutcome::seen(items: $this->fromBluesky(rows: (array)$decoded['feed'], handle: $handle));
	}               

	   
                                
   
                                                       
                                                    
   
                                            
    
	private function fromMastodon(array $rows, string $handle): array {
		$out = [];
		foreach ($rows as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$url = trim((string)($row['url'] ?? ($row['uri'] ?? '')));
			if ($url === '') {
				continue;
			}

			$out[] = [
				'url' => $url,
				'title' => ($handle . ' posted'),
				'summary' => $this->shorten(value: (string)($row['content'] ?? '')),
				'stamp' => trim((string)($row['id'] ?? $url)),
				'publishedAt' => trim((string)($row['created_at'] ?? '')),
			];
		}

		return $out;
	}                    

	   
                                    
   
                                                           
                                                    
   
                                            
    
	private function fromBluesky(array $rows, string $handle): array {
		$out = [];
		foreach ($rows as $row) {
			$post = null;
			if (is_array($row) === true) {
				$post = ($row['post'] ?? null);
			}

			if (is_array($post) === false) {
				continue;
			}

			$uri = trim((string)($post['uri'] ?? ''));
			if ($uri === '') {
				continue;
			}

			$record = ($post['record'] ?? []);
			$text = '';
			if (is_array($record) === true) {
				$text = (string)($record['text'] ?? '');
			}

			$out[] = [
				'url' => $this->blueskyPermalink(uri: $uri, handle: $handle),
				'title' => ($handle . ' posted'),
				'summary' => $this->shorten(value: $text),
				'stamp' => trim((string)($post['cid'] ?? $uri)),
				'publishedAt' => trim((string)($post['indexedAt'] ?? '')),
			];
		}

		return $out;
	}                   

	   
                                                     
   
                                           
                                     
   
                                                                      
    
	private function blueskyPermalink(string $uri, string $handle): string {
		$parts = explode('/', $uri);
		$rkey = end($parts);
		if (is_string($rkey) === false || $rkey === '') {
			return $uri;
		}

		return ('https://bsky.app/profile/' . rawurlencode($handle) . '/post/' . rawurlencode($rkey));
	}                        

	   
                                 
   
                                                      
   
                  
    
	private function shorten(string $value): string {
		$text = trim(strip_tags($value));
		if (mb_strlen($text, 'UTF-8') <= 500) {
			return $text;
		}

		return (mb_substr($text, 0, 497, 'UTF-8') . '...');
	}               
}           
