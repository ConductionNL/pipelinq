<?php

   
                                  
  
                                                                            
                                                                            
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                           
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

   
                                         
  
                                         
                                                                                  
                                                                       
  
                                                                                        
                                                     
   
class ForecastRollupService {
	   
                                 
   
                           
    
	public const AMOUNT_KEYS = ['commit_amount', 'best_case_amount', 'pipeline_amount', 'closed_won_amount'];

	   
                
   
                                                                                
    
	public function __construct(
		private ExchangeRateService $exchangeRate,
	) {
	}                   

	   
                                                        
   
                                                                               
                                                                         
                                                                           
                                                                         
   
                                                                          
   
                                                                                               
   
                                                                                                  
    
	public function bucketDeals(array $deals): array {
		$totals = $this->emptyTotals();
		$dealIds = [];
		$currency = $this->exchangeRate->getReportingCurrency();

		foreach ($deals as $deal) {
			$category = (string)($deal['forecast_category'] ?? '');
			$value = $this->exchangeRate->toReportingCurrency(
				amount: (float)($deal['value'] ?? 0),
				currency: ($deal['currency'] ?? null)
			);

			$key = $this->categoryToAmountKey(category: $category);
			if ($key === null) {
				continue;
			}

			$totals[$key] += $value;
			$dealIds[] = $this->dealId(deal: $deal);
		}

		foreach (self::AMOUNT_KEYS as $amountKey) {
			$totals[$amountKey] = round($totals[$amountKey], 2);
		}

		return [
			'totals' => $totals,
			'deal_ids' => array_values(array_filter($dealIds, static fn (string $id): bool => $id !== '')),
			'currency' => $currency,
		];
	}                   

	   
                                                  
   
                                                                           
                                         
   
                                                                                 
   
                                                          
   
                                                                                                                  
    
	public function sumChildTotals(array $childTotals): array {
		$parent = $this->emptyTotals();
		foreach ($childTotals as $child) {
			foreach (self::AMOUNT_KEYS as $amountKey) {
				$parent[$amountKey] += (float)($child[$amountKey] ?? 0);
			}
		}

		foreach (self::AMOUNT_KEYS as $amountKey) {
			$parent[$amountKey] = round($parent[$amountKey], 2);
		}

		return $parent;
	}                      

	   
                                                            
   
                                                            
   
                                                  
                                                                                         
                                                      
    
	public function totalsFromSnapshot(array $snapshot): array {
		$totals = $this->emptyTotals();
		foreach (self::AMOUNT_KEYS as $amountKey) {
			$totals[$amountKey] = (float)($snapshot[$amountKey] ?? 0);
		}

		return $totals;
	}                          

	   
                             
   
                                                  
                                                                                         
                                                      
    
	public function emptyTotals(): array {
		return [
			'commit_amount' => 0.0,
			'best_case_amount' => 0.0,
			'pipeline_amount' => 0.0,
			'closed_won_amount' => 0.0,
		];
	}                   

	   
                                                                             
   
                                                  
   
                                               
    
	private function categoryToAmountKey(string $category): ?string {
		return match ($category) {
			'commit' => 'commit_amount',
			'best_case' => 'best_case_amount',
			'pipeline' => 'pipeline_amount',
			'closed_won' => 'closed_won_amount',
			default => null,
		};
	}                           

	   
                                                    
   
                                                    
   
                                                          
    
	private function dealId(array $deal): string {
		$self = $deal['@self'] ?? [];
		if (is_array($self) === true) {
			$candidate = $self['uuid'] ?? $self['id'] ?? $self['slug'] ?? null;
			if (is_string($candidate) === true && $candidate !== '') {
				return $candidate;
			}
		}

		$candidate = $deal['uuid'] ?? $deal['id'] ?? null;
		if (is_string($candidate) === true) {
			return $candidate;
		}

		return '';
	}              
}           
