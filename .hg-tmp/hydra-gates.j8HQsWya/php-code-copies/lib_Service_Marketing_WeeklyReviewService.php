<?php

   
                                
  
                                                                            
                                                                     
                                     
  
                                                                             
                                                                           
                                                                    
  
                                                                           
                                                                             
                                                                           
                                                                               
                                                       
  
                    
                                           
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                                                                                              
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

use OCP\AppFramework\Utility\ITimeFactory;

   
                                                 
  
                                                                           
                                                         
  
                                                                                                                                                                                              
   
class WeeklyReviewService {

	   
                           
   
               
    
	public const SCHEMA = 'weeklyReview';

	   
                                                                 
   
                                                                           
                                                                            
                                                                      
                                                                          
                                                                       
                                                                           
                                                                            
                                  
   
               
    
	public const AGENT_IDENTITY = 'hermiq:marketing-weekly-review';

	   
                                                              
   
                           
    
	public const SOURCES = ['blast', 'touchpoint', 'socialPublication', 'searchQueryDaily', 'watchEvent'];

	   
                                          
   
            
    
	private const IDEAS = 3;

	   
                
   
                                                                  
                                                                             
                                    
   
                                                                                                                                                                                               
    
	public function __construct(
		private ListObjectStore $store,
		private WeeklyReviewNumbers $numbers,
		private ITimeFactory $time,
	) {
	}                   

	   
                                                           
   
                                                                                
   
                                                            
   
                                                                                                                                                                                               
    
	public function compose(string $weekStarting = ''): array {
		$monday = $this->mondayOf(day: $weekStarting);
		$sunday = date('Y-m-d', (strtotime($monday) + (6 * 86400)));

		$mail = $this->numbers->mail(from: $monday, to: $sunday);
		$social = $this->numbers->social(from: $monday, to: $sunday);
		$search = $this->numbers->search(from: $monday, to: $sunday);
		$watch = $this->numbers->watch(from: $monday, to: $sunday);

		$highlights = array_merge(
			$mail['highlights'],
			$social['highlights'],
			$search['highlights'],
			$watch['highlights']
		);

		return [
			'weekStarting' => $monday,
			'sources' => self::SOURCES,
			'degraded' => $this->degradedSources(),
			'summary' => $this->summaryOf(monday: $monday, sunday: $sunday, highlights: $highlights),
			'highlights' => $highlights,
			'suggestions' => $this->suggestionsFor(mail: $mail, social: $social, search: $search),
			                                                                  
			                                               
			'topicIdeas' => array_slice(array_merge($watch['ideas'], $search['ideas']), 0, self::IDEAS),
			'generatedAt' => date('c', $this->time->getTime()),
		];
	}               

	   
                                                         
   
                                                                                
   
                                                                                       
   
                                                                                                                                                                                               
    
	public function generate(string $weekStarting = ''): ?array {
		$review = $this->compose(weekStarting: $weekStarting);
		$existing = $this->findByWeek(weekStarting: (string)$review['weekStarting']);
		$id = null;
		if ($existing !== null) {
			$id = $this->store->idOf($existing);
		}

		return $this->store->save(schemaSlug: $this->schema(), payload: $review, id: $id);
	}                

	   
                                  
   
                                                                           
   
                                                                                                                                                                                               
    
	public function latest(): ?array {
		$latest = null;
		foreach ($this->store->findAll(schemaSlug: $this->schema()) as $review) {
			$week = (string)($review['weekStarting'] ?? '');
			if ($week === '') {
				continue;
			}

			if ($latest === null || $week > (string)($latest['weekStarting'] ?? '')) {
				$latest = $review;
			}
		}

		return $latest;
	}              

	   
                          
   
                                                   
                                                       
                                                       
   
                                               
    
	private function suggestionsFor(array $mail, array $social, array $search): array {
		$suggestions = [];
		if ($mail['sent'] === 0) {
			$suggestions[] = 'No mailing went out. Send the one that is waiting in draft.';
		}

		if ($mail['sent'] > 0 && $mail['clicks'] === 0) {
			$suggestions[] = 'Mailings went out and nobody clicked. Try a different subject line.';
		}

		if ($social['published'] === 0) {
			$suggestions[] = 'Nothing was published on social. Turn last week\'s article into a post.';
		}

		if ($search['impressions'] > 0 && $search['clicks'] === 0) {
			$suggestions[] = 'Search shows the site but nobody clicks. Rewrite the page titles.';
		}

		return $suggestions;
	}                      

	   
                                                                  
   
                                     
                                   
                                                         
   
                               
    
	private function summaryOf(string $monday, string $sunday, array $highlights): string {
		if ($highlights === []) {
			return sprintf('Nothing was recorded between %s and %s.', $monday, $sunday);
		}

		return sprintf('Between %s and %s: %s', $monday, $sunday, implode(' ', $highlights));
	}                 

	   
                                                       
   
                                                                           
                                                                         
                                             
   
                                                                        
    
	private function degradedSources(): array {
		$empty = [];
		foreach (self::SOURCES as $source) {
			$schema = $this->store->schemaSlug($source . '_schema', $source);
			if ($this->numbers->readable(schemaSlug: $schema) === false) {
				$empty[] = $source;
			}
		}

		return $empty;
	}                       

	   
                                  
   
                                           
   
                                                 
    
	private function findByWeek(string $weekStarting): ?array {
		foreach ($this->store->findAll(schemaSlug: $this->schema(), filters: ['weekStarting' => $weekStarting]) as $review) {
			if ((string)($review['weekStarting'] ?? '') === $weekStarting) {
				return $review;
			}
		}

		return null;
	}                  

	   
                                                                      
   
                                                    
   
                                            
    
	private function mondayOf(string $day): string {
		$day = trim($day);
		$stamp = strtotime($day);
		if ($day === '') {
			$stamp = strtotime('-7 days', $this->time->getTime());
		}
		if ($stamp === false) {
			$stamp = $this->time->getTime();
		}

		return date('Y-m-d', strtotime('monday this week', $stamp));
	}                

	   
                           
   
                            
    
	private function schema(): string {
		return $this->store->schemaSlug('weeklyReview_schema', self::SCHEMA);
	}              
}           
