<?php

   
                                   
  
                                                                              
                                                                            
        
  
                                                                           
                                                                            
                                                                             
                                                                               
                                                                              
                  
  
                                                                              
                                                                           
                                                                               
                                                                               
                   
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                                                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Search;

   
                                                                         
  
                                                                                                                                                          
  
                                                                     
                                                                       
                                                                      
                                                                         
                                                                          
                                                                          
                     
                                                                          
                                                                          
                                                                        
                                                           
   
class KeywordAnalysisService {

	   
                                                                    
                                                                  
   
                             
    
	public const BUCKETS = [
		'1-3' => 3.0,
		'4-10' => 10.0,
		'11-20' => 20.0,
	];

	   
                                                          
   
               
    
	public const TAIL_BUCKET = '21+';

	   
                                                                         
                                                                    
   
            
    
	public const DEFAULT_MIN_IMPRESSIONS = 100;

	   
                                                                        
                                                 
   
              
    
	public const STRIKING_FROM = 8.0;

	   
                                                                           
               
   
              
    
	public const STRIKING_TO = 20.0;

	   
                                                                         
                                              
   
              
    
	public const DEFAULT_MIN_PAGE_SHARE = 0.20;

	   
                                                                           
                                
   
              
    
	public const DEFAULT_MATERIALITY = 0.10;

	   
                                                                         
   
            
    
	public const MIN_TOKEN_LENGTH = 3;

	   
                                                                           
                                                               
   
                           
    
	public const STOP_WORDS = [
		'aan', 'als', 'bij', 'dat', 'deze', 'die', 'een', 'het', 'hoe', 'ik',
		'kan', 'met', 'niet', 'om', 'ook', 'van', 'voor', 'wat', 'zijn',
		'and', 'are', 'can', 'for', 'how', 'the', 'was', 'what', 'which',
		'with', 'you', 'your',
	];

	   
                                                        
   
                                                                          
   
                                                                                                                          
   
                                                                                                                                                           
    
	public function positionBuckets(array $rows): array {
		$labels = array_merge(array_keys(self::BUCKETS), [self::TAIL_BUCKET]);
		$out = [];
		foreach ($labels as $label) {
			$out[$label] = ['bucket' => $label, 'queries' => 0, 'clicks' => 0, 'impressions' => 0];
		}

		foreach ($this->byQuery(rows: $rows) as $entry) {
			if ($entry['impressions'] <= 0) {
				continue;
			}

			$label = $this->bucketOf(position: $entry['position']);
			$out[$label]['queries']++;
			$out[$label]['clicks'] += $entry['clicks'];
			$out[$label]['impressions'] += $entry['impressions'];
		}

		return array_values($out);
	}                       

	   
                                                                           
   
                                                                  
   
                                                               
   
                                                                                                                                                           
    
	public function bucketOf(float $position): string {
		foreach (self::BUCKETS as $label => $bound) {
			if ($position <= $bound) {
				return $label;
			}
		}

		return self::TAIL_BUCKET;
	}                

	   
                                                 
   
                                                                          
                                                                    
   
                                                                              
   
                                                                                                                                                                              
    
	public function strikingDistance(array $rows, int $minImpressions = self::DEFAULT_MIN_IMPRESSIONS): array {
		$out = [];
		foreach ($this->byQuery(rows: $rows) as $entry) {
			if ($entry['impressions'] < $minImpressions || $entry['impressions'] <= 0) {
				continue;
			}

			$position = $entry['position'];
			if ($position < self::STRIKING_FROM || $position > self::STRIKING_TO) {
				continue;
			}

			$ctr = ($entry['clicks'] / $entry['impressions']);
			$expected = ExpectedCtrCurve::rateAt(position: $position);
			if ($ctr >= $expected) {
				continue;
			}

			$out[] = [
				'query' => $entry['query'],
				'clicks' => $entry['clicks'],
				'impressions' => $entry['impressions'],
				'ctr' => round($ctr, 4),
				'position' => round($position, 1),
				'expectedCtr' => round($expected, 4),
				'shortfall' => round(($expected - $ctr), 4),
				'topPage' => $entry['topPage'],
			];
		}

		usort(
			$out,
			static function (array $a, array $b): int {
				return [$b['impressions'], $b['shortfall'], $a['query']] <=> [$a['impressions'], $a['shortfall'], $b['query']];
			}
		);

		return $out;
	}                        

	   
                                                                       
           
   
                                                                      
                                                                         
                                                                        
                                                                        
                                                                        
                                                    
   
                                                                          
                                                                      
                                                                  
                                                                                        
   
                                                                              
   
                                                                                                                                                                         
    
	public function cannibalisation(
		array $rows,
		int $minImpressions = self::DEFAULT_MIN_IMPRESSIONS,
		float $minPageShare = self::DEFAULT_MIN_PAGE_SHARE,
		float $materiality = self::DEFAULT_MATERIALITY,
	): array {
		$out = [];
		foreach ($this->byQueryAndPage(rows: $rows) as $query => $pages) {
			$totals = $this->pageTotals(pages: $pages);
			if ($totals['impressions'] < $minImpressions || $totals['impressions'] <= 0) {
				continue;
			}

			$contenders = $this->contenders(pages: $pages, queryImpressions: $totals['impressions'], minPageShare: $minPageShare);
			if (count($contenders) < 2) {
				continue;
			}

			$combined = ($totals['clicks'] / $totals['impressions']);
			$best = $this->bestPage(contenders: $contenders);
			if ($combined > ($best['ctr'] * (1.0 - $materiality))) {
				continue;
			}

			$out[] = [
				'query' => $query,
				'clicks' => $totals['clicks'],
				'impressions' => $totals['impressions'],
				'combinedCtr' => round($combined, 4),
				'bestPage' => $best['page'],
				'bestPageCtr' => round($best['ctr'], 4),
				'pages' => array_values($contenders),
			];
		}

		usort(
			$out,
			static function (array $a, array $b): int {
				return [$b['impressions'], $a['query']] <=> [$a['impressions'], $b['query']];
			}
		);

		return $out;
	}                       

	   
                                                     
   
                                                                    
                                                                         
                                                                         
                                                    
   
                                                                          
                                                                                             
                                                                      
   
                                                                          
   
                                                                                                                                                                  
    
	public function contentGaps(array $rows, array $pages, int $minImpressions = self::DEFAULT_MIN_IMPRESSIONS): array {
		if ($pages === []) {
			return [];
		}

		$haystacks = [];
		foreach ($pages as $page) {
			$haystacks[(string)($page['url'] ?? '')] = $this->normalise(value: (string)($page['text'] ?? ''));
		}

		$out = [];
		foreach ($this->byQuery(rows: $rows) as $entry) {
			if ($entry['impressions'] < $minImpressions) {
				continue;
			}

			$tokens = $this->significantTokens(value: $entry['query']);
			if ($tokens === [] || $this->anyPageCarries(tokens: $tokens, haystacks: $haystacks) === true) {
				continue;
			}

			$out[] = [
				'query' => $entry['query'],
				'clicks' => $entry['clicks'],
				'impressions' => $entry['impressions'],
				'position' => round($entry['position'], 1),
				'terms' => $tokens,
			];
		}

		usort(
			$out,
			static function (array $a, array $b): int {
				return [$b['impressions'], $a['query']] <=> [$a['impressions'], $b['query']];
			}
		);

		return $out;
	}                   

	   
                                                                       
                  
   
                                    
   
                                                                  
   
                                                                                                                                                                  
    
	public function significantTokens(string $value): array {
		$parts = preg_split('/[^a-z0-9]+/', $this->normalise(value: $value));
		if (is_array($parts) === false) {
			return [];
		}

		$out = [];
		foreach ($parts as $part) {
			if (strlen($part) < self::MIN_TOKEN_LENGTH || in_array($part, self::STOP_WORDS, true) === true) {
				continue;
			}

			$out[$part] = true;
		}

		return array_keys($out);
	}                         

	   
                                                     
   
                                  
   
                                       
    
	private function normalise(string $value): string {
		$lower = mb_strtolower($value, 'UTF-8');
		$folded = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lower);
		if (is_string($folded) === false) {
			$folded = $lower;
		}

		$collapsed = preg_replace('/\s+/', ' ', $folded);
		if (is_string($collapsed) === false) {
			$collapsed = $folded;
		}

		return trim($collapsed);
	}                 

	   
                                                 
   
                                                                     
                                                                        
   
                
    
	private function anyPageCarries(array $tokens, array $haystacks): bool {
		foreach ($haystacks as $text) {
			$carries = true;
			foreach ($tokens as $token) {
				if (str_contains($text, $token) === false) {
					$carries = false;
					break;
				}
			}

			if ($carries === true) {
				return true;
			}
		}

		return false;
	}                      

	   
                                                                        
                                                                          
   
                                                           
   
                                                                                                             
    
	private function byQuery(array $rows): array {
		$acc = [];
		foreach ($rows as $row) {
			$query = trim((string)($row['query'] ?? ''));
			if ($query === '') {
				continue;
			}

			$impressions = max(0, (int)($row['impressions'] ?? 0));
			$entry = ($acc[$query] ?? ['query' => $query, 'clicks' => 0, 'impressions' => 0, 'weighted' => 0.0, 'pages' => []]);
			$entry['clicks'] += max(0, (int)($row['clicks'] ?? 0));
			$entry['impressions'] += $impressions;
			$entry['weighted'] += ((float)($row['position'] ?? 0.0) * $impressions);
			$page = (string)($row['page'] ?? '');
			if ($page !== '') {
				$entry['pages'][$page] = (($entry['pages'][$page] ?? 0) + $impressions);
			}

			$acc[$query] = $entry;
		}

		$out = [];
		foreach ($acc as $entry) {
			$position = 0.0;
			if ($entry['impressions'] > 0) {
				$position = ($entry['weighted'] / $entry['impressions']);
			}

			arsort($entry['pages']);
			$out[] = [
				'query' => $entry['query'],
				'clicks' => (int)$entry['clicks'],
				'impressions' => (int)$entry['impressions'],
				'position' => $position,
				'topPage' => (string)(array_key_first($entry['pages']) ?? ''),
			];
		}

		return $out;
	}               

	   
                                      
   
                                                           
   
                                                                                                             
    
	private function byQueryAndPage(array $rows): array {
		$acc = [];
		foreach ($rows as $row) {
			$query = trim((string)($row['query'] ?? ''));
			$page = trim((string)($row['page'] ?? ''));
			if ($query === '' || $page === '') {
				continue;
			}

			$impressions = max(0, (int)($row['impressions'] ?? 0));
			$entry = ($acc[$query][$page] ?? ['page' => $page, 'clicks' => 0, 'impressions' => 0, 'weighted' => 0.0]);
			$entry['clicks'] += max(0, (int)($row['clicks'] ?? 0));
			$entry['impressions'] += $impressions;
			$entry['weighted'] += ((float)($row['position'] ?? 0.0) * $impressions);
			$acc[$query][$page] = $entry;
		}

		return $acc;
	}                      

	   
                                                       
   
                                                                           
   
                                                
    
	private function pageTotals(array $pages): array {
		$clicks = 0;
		$impressions = 0;
		foreach ($pages as $entry) {
			$clicks += (int)$entry['clicks'];
			$impressions += (int)$entry['impressions'];
		}

		return ['clicks' => $clicks, 'impressions' => $impressions];
	}                  

	   
                                                               
   
                                                                           
                                                               
                                                 
   
                                                                                 
    
	private function contenders(array $pages, int $queryImpressions, float $minPageShare): array {
		$out = [];
		foreach ($pages as $page => $entry) {
			$impressions = (int)$entry['impressions'];
			if ($impressions <= 0 || ($impressions / $queryImpressions) < $minPageShare) {
				continue;
			}

			$out[$page] = [
				'page' => (string)$entry['page'],
				'clicks' => (int)$entry['clicks'],
				'impressions' => $impressions,
				'ctr' => round(((int)$entry['clicks'] / $impressions), 4),
				'position' => round(((float)$entry['weighted'] / $impressions), 1),
			];
		}

		return $out;
	}                  

	   
                                                   
   
                                                                          
   
                                           
    
	private function bestPage(array $contenders): array {
		$best = ['page' => '', 'ctr' => 0.0];
		foreach ($contenders as $entry) {
			$ctr = (float)$entry['ctr'];
			if ($ctr > $best['ctr']) {
				$best = ['page' => (string)$entry['page'], 'ctr' => $ctr];
			}
		}

		return $best;
	}                
}           
