<?php

   
                               
  
                                                                       
  
                                                                               
                                                                              
                                                                            
                                            
  
                                                                             
                                                                              
                      
  
                                                                         
                                                                           
                                                                            
                                                                               
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                                                                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Search;

use OCA\Pipelinq\Service\Egress\ConnectorEgress;
use OCA\Pipelinq\Service\Egress\EgressResult;

   
                                                 
  
                                                                                                                                                                   
   
class SiteContentCrawler {

	   
                                                                         
   
               
    
	public const SOURCE_KEY = 'search.crawl_source';

	   
                                      
   
            
    
	public const DEFAULT_LIMIT = 50;

	   
                
   
                                                            
                                                                             
   
                                                                                                                                                                    
    
	public function __construct(
		private ConnectorEgress $egress,
		private HtmlTextExtractor $extractor,
	) {
	}                   

	   
                                                           
   
                                                                      
   
                                                                          
                                             
   
                                                             
   
                                                                                                                                                                    
    
	public function pagesToCrawl(array $rows, int $limit = self::DEFAULT_LIMIT): array {
		$impressions = [];
		foreach ($rows as $row) {
			$page = trim((string)($row['page'] ?? ''));
			if ($page === '') {
				continue;
			}

			$impressions[$page] = (($impressions[$page] ?? 0) + max(0, (int)($row['impressions'] ?? 0)));
		}

		arsort($impressions);

		return array_slice(array_keys($impressions), 0, max(0, $limit));
	}                    

	   
                                
   
                                                                          
                                             
   
                                                                                                                           
   
                                                                                                                                                                    
    
	public function crawl(array $rows, int $limit = self::DEFAULT_LIMIT): array {
		if ($this->egress->isConfigured(configKey: self::SOURCE_KEY) === false) {
			return [
				'crawled' => false,
				'failure' => EgressResult::NOT_CONFIGURED,
				'reason' => 'No crawl source is configured, so the content gap check did not run.',
				'pages' => [],
			];
		}

		$urls = $this->pagesToCrawl(rows: $rows, limit: $limit);
		if ($urls === []) {
			return [
				'crawled' => false,
				'failure' => EgressResult::NOT_CONFIGURED,
				'reason' => 'No page has been shown in search yet, so there is nothing to crawl.',
				'pages' => [],
			];
		}

		$pages = [];
		$lastFailure = null;
		$lastReason = '';
		foreach ($urls as $url) {
			$result = $this->egress->readUrl(configKey: self::SOURCE_KEY, url: $url);
			if ($result->succeeded === false) {
				$lastFailure = $result->failure;
				$lastReason = $result->reason;
				continue;
			}

			$pages[] = ['url' => $url, 'text' => $this->extractor->headline(html: $result->body)];
		}

		if ($pages === []) {
			$reason = $lastReason;
			if ($reason === '') {
				$reason = 'No page could be read, so the content gap check did not run.';
			}

			return [
				'crawled' => false,
				'failure' => ($lastFailure ?? EgressResult::UNAVAILABLE),
				'reason' => $reason,
				'pages' => [],
			];
		}

		return ['crawled' => true, 'failure' => null, 'reason' => '', 'pages' => $pages];
	}             
}           
