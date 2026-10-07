<?php

   
                               
  
                                                                   
                                           
                                                                   
                                                                    
                                                              
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

   
                                                                    
  
                       
                                                                  
                                                                     
                                                   
  
                                                                   
   
class CostCaptureService {
	   
                
   
                                                                          
                                                               
   
                                                                    
    
	public function __construct(
		private MessagingExchangeRateService $exchangeRate,
		private CostEstimationService $estimator,
	) {
	}                   

	   
                                                      
   
          
                                                                
                                                         
                                              
   
                                                               
                                              
                                                    
                                              
                                              
   
                                                                      
                                                                                                                  
   
                                                                    
    
	public function capture(
		array $payload,
		string $vendor,
		string $channel,
		string $category = 'utility',
		string $country = 'NL',
	): array {
		                                                      
		if (isset($payload['Price']) === true && isset($payload['PriceUnit']) === true) {
			$rawPrice = abs((float)$payload['Price']);
			$currency = strtoupper((string)$payload['PriceUnit']);
			$eur = $this->exchangeRate->toEur(amount: $rawPrice, currency: $currency);
			if ($eur === null) {
				return [
					'costEur' => 0.0,
					'metadata' => [
						'costSource' => 'webhook',
						'costCurrency' => $currency,
						'costSourceAmount' => $rawPrice,
						'costCurrencyPending' => true,
						'costEstimated' => false,
					],
				];
			}

			return [
				'costEur' => round($eur, 6),
				'metadata' => [
					'costSource' => 'webhook',
					'costCurrency' => $currency,
					'costSourceAmount' => $rawPrice,
					'costCurrencyPending' => false,
					'costEstimated' => false,
				],
			];
		}        

		                                            
		if (isset($payload['costEur']) === true && is_numeric($payload['costEur']) === true) {
			return [
				'costEur' => (float)$payload['costEur'],
				'metadata' => [
					'costSource' => 'webhook',
					'costCurrency' => 'EUR',
					'costEstimated' => false,
				],
			];
		}

		                                                                       
		$estimate = $this->estimator->estimate(
			vendor: $vendor,
			channel: $channel,
			category: $category,
			country: $country,
		);

		return [
			'costEur' => $estimate,
			'metadata' => [
				'costSource' => 'estimate',
				'costCurrency' => 'EUR',
				'costEstimated' => true,
			],
		];
	}               
}           
