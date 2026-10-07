<?php

   
                              
  
                                                                      
                                                                           
                                                                               
                                
  
                                                                           
                                                                           
                                                                               
                                                                           
                                                                        
  
                                                                            
                                                                               
                                                                             
                                                                           
                     
  
                    
                                            
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                                                                                                       
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Competitor;

use OCA\Pipelinq\Service\Egress\EgressResult;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                         
  
                                                                                                                                                                       
  
                                                                      
                                                                    
                                                                         
                                                                   
                                                                         
                         
   
class SearchWatchReader {

	   
                                                                       
                                      
   
               
    
	public const CLIENT_CLASS = 'OCA\\Hermiq\\Service\\WebResearch\\WebSearchClient';

	   
                                          
   
            
    
	public const MAX_RESULTS = 10;

	   
                
   
                                                                                  
                                          
   
                                                                                                                                                                        
    
	public function __construct(
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}                   

	   
                         
   
                                         
                                                                                          
   
                                                                   
   
                                                                                                                                                                        
    
	public function read(string $query, ?string $actingUserId = null): WatchOutcome {
		$trimmed = trim($query);
		if ($trimmed === '') {
			return WatchOutcome::failed(outcome: EgressResult::NOT_CONFIGURED, reason: 'This watch has no search query.');
		}

		$client = $this->client();
		if ($client === null) {
			return WatchOutcome::failed(
				outcome: EgressResult::NOT_CONFIGURED,
				reason: 'Hermiq is not available, so search watches do not run on this instance.'
			);
		}

		try {
			$answer = $client->search(query: $trimmed, actingUserId: $actingUserId);
		} catch (Throwable $e) {
			$this->logger->info('SearchWatchReader: the search failed', ['exception' => $e->getMessage()]);
			return WatchOutcome::failed(outcome: EgressResult::UNAVAILABLE, reason: 'The web search failed.');
		}

		if (is_array($answer) === false) {
			return WatchOutcome::failed(outcome: EgressResult::UNPARSABLE, reason: 'The web search answered nothing usable.');
		}

		if (is_array($answer['error'] ?? null) === true) {
			return WatchOutcome::failed(
				outcome: EgressResult::NOT_CONFIGURED,
				reason: (string)($answer['error']['message'] ?? 'The web search is not configured.')
			);
		}

		return WatchOutcome::seen(items: $this->normalise(results: (array)($answer['results'] ?? [])));
	}            

	   
                                                
   
                                                         
   
                                            
    
	private function normalise(array $results): array {
		$out = [];
		foreach ($results as $result) {
			if (is_array($result) === false) {
				continue;
			}

			$url = trim((string)($result['url'] ?? ''));
			if ($url === '') {
				continue;
			}

			$out[] = [
				'url' => $url,
				'title' => trim((string)($result['title'] ?? $url)),
				'summary' => trim((string)($result['snippet'] ?? '')),
				'stamp' => $url,
			];
			if (count($out) >= self::MAX_RESULTS) {
				break;
			}
		}

		return $out;
	}                 

	   
                                                          
   
                       
    
	private function client(): ?object {
		try {
			$client = $this->container->get(self::CLIENT_CLASS);
		} catch (Throwable) {
			return null;
		}

		if (method_exists($client, 'search') === false) {
			return null;
		}

		return $client;
	}              
}           
