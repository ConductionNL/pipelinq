<?php

   
                            
  
                                                                              
                                                                               
                                                                               
                                                          
  
                                                                             
                                                                             
                                                                            
                                                                               
                                                                             
                                                                 
  
                                                                           
                                                                              
                                                                   
  
                    
                                            
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                                                                                                              
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Competitor;

use OCA\Pipelinq\Service\Egress\ConnectorEgress;
use OCA\Pipelinq\Service\Egress\EgressResult;
use OCA\Pipelinq\Service\Search\HtmlTextExtractor;

   
                                                 
  
                                                                                                                                                                              
  
                                                                      
                                                                    
                                                                         
                                                                   
                                                                         
                         
   
class PageWatchReader {

	   
                                                                          
                                                                         
                                               
   
            
    
	public const MAX_LINES = 200;

	   
                                             
   
            
    
	public const MAX_QUOTED = 5;

	   
                
   
                                                            
                                                                   
   
                                                                                                                                                                               
    
	public function __construct(
		private ConnectorEgress $egress,
		private HtmlTextExtractor $extractor,
	) {
	}                   

	   
                                                                
   
                                    
                                                             
                                                                       
                                                                                   
                                                                        
   
                                                                                  
   
                                                                                                                                                                               
    
	public function read(
		string $url,
		string $selector,
		string $fingerprint,
		array $lineFingerprints,
		string $sourceId = '',
	): WatchOutcome {
		$result = $this->egress->readUrl(configKey: CompetitorWatchService::SOURCE_KEY, url: $url, sourceId: $sourceId);
		if ($result->succeeded === false) {
			return WatchOutcome::failed(outcome: (string)$result->failure, reason: $result->reason);
		}

		$fragment = $this->extractor->fragment(html: $result->body, selector: $selector);
		if ($fragment === null) {
			return WatchOutcome::failed(
				outcome: EgressResult::UNPARSABLE,
				reason: 'The selector ' . $selector . ' matched nothing on ' . $url . '.'
			);
		}

		$diff = $this->diff(fingerprint: $fingerprint, lineFingerprints: $lineFingerprints, fragment: $fragment);
		$state = ['fingerprint' => $diff['fingerprint'], 'lineFingerprints' => $diff['lineFingerprints']];
		if ($diff['changed'] === false) {
			return WatchOutcome::seen(items: [], state: $state);
		}

		return WatchOutcome::seen(
			items: [
				[
					'url' => $url,
					'title' => $url,
					'summary' => $diff['summary'],
					'stamp' => $diff['fingerprint'],
				],
			],
			state: $state
		);
	}            

	   
                                                                          
   
                                                                       
                                                                                   
                                                     
   
                                                                                                                                                     
   
                                                                                                                                                                               
    
	public function diff(string $fingerprint, array $lineFingerprints, string $fragment): array {
		$lines = $this->lines(fragment: $fragment);
		$current = [];
		foreach ($lines as $line) {
			$current[$this->hash(value: $line)] = $line;
		}

		$currentHashes = array_slice(array_keys($current), 0, self::MAX_LINES);
		$freshFingerprint = $this->hash(value: $fragment);
		if ($fingerprint !== '' && $fingerprint === $freshFingerprint) {
			return [
				'changed' => false,
				'fingerprint' => $freshFingerprint,
				'lineFingerprints' => $currentHashes,
				'summary' => '',
				'added' => [],
				'removed' => 0,
			];
		}

		$added = [];
		foreach ($current as $hash => $line) {
			if (in_array((string)$hash, $lineFingerprints, true) === false) {
				$added[] = $line;
			}
		}

		$removed = 0;
		foreach ($lineFingerprints as $hash) {
			if (array_key_exists($hash, $current) === false) {
				$removed++;
			}
		}

		return [
			'changed' => true,
			'fingerprint' => $freshFingerprint,
			'lineFingerprints' => $currentHashes,
			'summary' => $this->summary(added: $added, removed: $removed, first: ($fingerprint === '')),
			'added' => $added,
			'removed' => $removed,
		];
	}            

	   
                                      
   
                                                                
                                                
                                                                            
   
                  
    
	private function summary(array $added, int $removed, bool $first): string {
		if ($first === true) {
			return 'First reading of this fragment.';
		}

		$parts = [];
		if ($added !== []) {
			$parts[] = ('Added: ' . implode(' / ', array_slice($added, 0, self::MAX_QUOTED)));
		}

		if ($removed > 0) {
			$parts[] = ($removed . ' line(s) removed. The previous text is not kept, so it cannot be quoted.');
		}

		if ($parts === []) {
			return 'The watched fragment changed.';
		}

		return implode(' ', $parts);
	}               

	   
                                             
   
                                              
   
                                                   
    
	private function lines(string $fragment): array {
		$parts = preg_split('/(?<=[.!?])\s+|\R+/u', $fragment);
		if (is_array($parts) === false) {
			return [];
		}

		$out = [];
		foreach ($parts as $part) {
			$line = trim($part);
			if ($line !== '') {
				$out[] = $line;
			}
		}

		return $out;
	}             

	   
                                            
   
                                   
   
                                                  
    
	private function hash(string $value): string {
		return substr(hash('sha256', $value), 0, 16);
	}            
}           
