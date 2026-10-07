<?php

   
                            
  
                                                                             
                                                                              
                                                                           
                                                
  
                                                                           
                                                                              
                                                                              
                  
  
                                                                              
                                                                     
  
                    
                                            
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                                                                                               
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Competitor;

use OCA\Pipelinq\Service\Egress\ConnectorEgress;
use OCA\Pipelinq\Service\Egress\EgressResult;
use SimpleXMLElement;
use Throwable;

   
                                      
  
                                                                                                                                                               
  
                                                                      
                                                                    
                                                                         
                                                                   
                                                                         
                         
   
class FeedWatchReader {

	   
                                            
   
            
    
	public const MAX_ENTRIES = 50;

	   
                
   
                                                            
   
                                                                                                                                                                
    
	public function __construct(
		private ConnectorEgress $egress,
	) {
	}                   

	   
                  
   
                                    
                                                                        
   
                                                                   
   
                                                                                                                                                                
    
	public function read(string $url, string $sourceId = ''): WatchOutcome {
		$result = $this->egress->readUrl(configKey: CompetitorWatchService::SOURCE_KEY, url: $url, sourceId: $sourceId);
		if ($result->succeeded === false) {
			return WatchOutcome::failed(outcome: (string)$result->failure, reason: $result->reason);
		}

		$entries = $this->parse(document: $result->body);
		if ($entries === null) {
			return WatchOutcome::failed(
				outcome: EgressResult::UNPARSABLE,
				reason: 'The answer from ' . $url . ' is not an RSS or Atom feed.'
			);
		}

		return WatchOutcome::seen(items: $entries);
	}            

	   
                          
   
                                                     
   
                                                                                                       
   
                                                                                                                                                                
    
	public function parse(string $document): ?array {
		$xml = $this->load(document: $document);
		if ($xml === null) {
			return null;
		}

		$items = $this->rssItems(xml: $xml);
		if ($items !== null) {
			return $items;
		}

		return $this->atomEntries(xml: $xml);
	}             

	   
                                                                          
   
                                              
   
                                                 
    
	private function rssItems(SimpleXMLElement $xml): ?array {
		$channel = ($xml->channel ?? null);
		if ($channel === null || isset($channel->item) === false) {
			return null;
		}

		$out = [];
		foreach ($channel->item as $item) {
			$link = trim((string)$item->link);
			if ($link === '') {
				continue;
			}

			$guid = trim((string)$item->guid);
			$stamp = $guid;
			if ($stamp === '') {
				$stamp = trim((string)$item->pubDate);
			}

			$out[] = [
				'url' => $link,
				'title' => trim((string)$item->title),
				'summary' => $this->shorten(value: trim((string)$item->description)),
				'stamp' => $stamp,
				'publishedAt' => $this->instant(value: trim((string)$item->pubDate)),
			];
			if (count($out) >= self::MAX_ENTRIES) {
				break;
			}
		}

		return $out;
	}                

	   
                                                                          
   
                                              
   
                                                 
    
	private function atomEntries(SimpleXMLElement $xml): ?array {
		if (isset($xml->entry) === false) {
			return null;
		}

		$out = [];
		foreach ($xml->entry as $entry) {
			$link = trim((string)($entry->link['href'] ?? ''));
			if ($link === '') {
				$link = trim((string)$entry->id);
			}

			if ($link === '') {
				continue;
			}

			$stamp = trim((string)$entry->id);
			if ($stamp === '') {
				$stamp = trim((string)$entry->updated);
			}

			$published = trim((string)$entry->published);
			if ($published === '') {
				$published = trim((string)$entry->updated);
			}

			$out[] = [
				'url' => $link,
				'title' => trim((string)$entry->title),
				'summary' => $this->shorten(value: trim((string)$entry->summary)),
				'stamp' => $stamp,
				'publishedAt' => $this->instant(value: $published),
			];
			if (count($out) >= self::MAX_ENTRIES) {
				break;
			}
		}

		return $out;
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

	   
                                                                           
   
                                                       
   
                                       
    
	private function instant(string $value): string {
		$parsed = false;
		if ($value !== '') {
			$parsed = strtotime($value);
		}

		if ($parsed === false) {
			return gmdate('Y-m-d\TH:i:s\Z');
		}

		return gmdate('Y-m-d\TH:i:s\Z', $parsed);
	}               

	   
                                                                           
   
                                                    
   
                  
    
	private function shorten(string $value): string {
		$text = trim(strip_tags($value));
		if (mb_strlen($text, 'UTF-8') <= 500) {
			return $text;
		}

		return (mb_substr($text, 0, 497, 'UTF-8') . '...');
	}               
}           
