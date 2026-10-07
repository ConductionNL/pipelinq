<?php

   
                               
  
                                                                             
                                                                           
                                                                             
                                                           
  
                                                                          
                                                                              
                                                                            
                             
  
                                                                            
                                                                              
                                                                               
                        
  
                    
                                            
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                                                                                                
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Competitor;

use OCA\Pipelinq\Service\Egress\ConnectorEgress;
use OCA\Pipelinq\Service\Egress\EgressResult;
use SimpleXMLElement;
use Throwable;

   
                                              
  
                                                                                                                                                                
  
                                                                      
                                                                    
                                                                         
                                                                   
                                                                         
                         
   
class SitemapWatchReader {

	   
                                                                        
                                                                      
   
            
    
	public const MAX_DEPTH = 1;

	   
                                                  
   
            
    
	public const MAX_LOCATIONS = 5000;

	   
                
   
                                                            
   
                                                                                                                                                                 
    
	public function __construct(
		private ConnectorEgress $egress,
	) {
	}                   

	   
                                                                    
                               
   
                                       
                                                                           
                                                                        
   
                                                                                     
   
                                                                                                                                                                 
    
	public function read(string $url, array $previous, string $sourceId = ''): WatchOutcome {
		$current = $this->collect(url: $url, sourceId: $sourceId, depth: 0);
		if (is_array($current) === false) {
			return WatchOutcome::failed(outcome: $current->outcome, reason: $current->reason);
		}

		$diff = $this->diff(previous: $previous, current: $current);
		$items = [];
		foreach ($diff['new'] as $loc => $stamp) {
			$items[] = ['url' => (string)$loc, 'title' => (string)$loc, 'summary' => 'New in the sitemap.', 'stamp' => (string)$stamp];
		}

		foreach ($diff['changed'] as $loc => $stamp) {
			$items[] = ['url' => (string)$loc, 'title' => (string)$loc, 'summary' => 'Changed in the sitemap.', 'stamp' => (string)$stamp];
		}

		return WatchOutcome::seen(items: $items, state: ['locations' => $current]);
	}            

	   
                                                            
   
                                                                           
                                                                         
   
                                                                             
   
                                                                                                                                                                 
    
	public function diff(array $previous, array $current): array {
		$new = [];
		$changed = [];
		foreach ($current as $loc => $stamp) {
			if (array_key_exists($loc, $previous) === false) {
				$new[$loc] = $stamp;
				continue;
			}

			$before = trim((string)$previous[$loc]);
			$after = trim((string)$stamp);
			if ($before === '' || $after === '' || $before === $after) {
				continue;
			}

			$changed[$loc] = $stamp;
		}

		return ['new' => $new, 'changed' => $changed];
	}            

	   
                               
   
                                                         
   
                                                                                                                  
   
                                                                                                                                                                 
    
	public function parse(string $document): ?array {
		$xml = $this->load(document: $document);
		if ($xml === null) {
			return null;
		}

		$isIndex = (isset($xml->sitemap) === true);
		$children = ($xml->url ?? null);
		if ($isIndex === true) {
			$children = $xml->sitemap;
		}

		if ($children === null) {
			return null;
		}

		return ['index' => $isIndex, 'locations' => $this->locationsOf(children: $children)];
	}             

	   
                                                                        
                                                                         
             
   
                                         
   
                                                    
    
	private function load(string $document): ?SimpleXMLElement {
		if (trim($document) === '') {
			return null;
		}

		$previous = libxml_use_internal_errors(true);
		try {
			$xml = simplexml_load_string($document, SimpleXMLElement::class, (LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING));
		} catch (Throwable) {
			return null;
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}

		if ($xml === false) {
			return null;
		}

		return $xml;
	}            

	   
                                                                          
                                   
   
                                                                          
   
                                                    
    
	private function locationsOf(SimpleXMLElement $children): array {
		$locations = [];
		foreach ($children as $child) {
			$loc = trim((string)$child->loc);
			if ($loc === '') {
				continue;
			}

			$locations[$loc] = trim((string)$child->lastmod);
			if (count($locations) >= self::MAX_LOCATIONS) {
				break;
			}
		}

		return $locations;
	}                   

	   
                                                 
   
                                       
                                                       
                                              
   
                                                                             
    
	private function collect(string $url, string $sourceId, int $depth): array|WatchOutcome {
		$result = $this->egress->readUrl(configKey: CompetitorWatchService::SOURCE_KEY, url: $url, sourceId: $sourceId);
		if ($result->succeeded === false) {
			return WatchOutcome::failed(outcome: (string)$result->failure, reason: $result->reason);
		}

		$parsed = $this->parse(document: $result->body);
		if ($parsed === null) {
			return WatchOutcome::failed(
				outcome: EgressResult::UNPARSABLE,
				reason: 'The answer from ' . $url . ' is not a sitemap.'
			);
		}

		if ($parsed['index'] === false) {
			return $parsed['locations'];
		}

		if ($depth >= self::MAX_DEPTH) {
			return WatchOutcome::failed(
				outcome: EgressResult::UNPARSABLE,
				reason: 'The sitemap index at ' . $url . ' nests deeper than one level.'
			);
		}

		$locations = [];
		foreach (array_keys($parsed['locations']) as $child) {
			$nested = $this->collect(url: (string)$child, sourceId: $sourceId, depth: ($depth + 1));
			if (is_array($nested) === false) {
				continue;
			}

			$locations = ($locations + $nested);
			if (count($locations) >= self::MAX_LOCATIONS) {
				break;
			}
		}

		return $locations;
	}               
}           
