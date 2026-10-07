<?php

   
                                         
  
                                                             
                                                                 
                                                                 
                                                    
                                     
  
                                                                      
                                                          
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                             
  
                       
                                                                 
                            
                                              
  
                                                                   
   
class MessagingExchangeRateService {
	   
                                                                
                                  
                                                                 
                                
   
                             
    
	private const FALLBACK_RATES = [
		'EUR' => 1.0,
		'USD' => 0.92,
		'GBP' => 1.17,
		'CHF' => 1.04,
	];

	   
                
   
                                            
                                          
   
                                                                    
    
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                    
   
                                                               
                                                                  
   
                                       
                                                   
   
                                          
   
                                                                    
    
	public function toEur(float $amount, string $currency): ?float {
		$currency = strtoupper(trim($currency));
		if ($currency === '' || $currency === 'EUR') {
			return $amount;
		}

		$rate = $this->getRate(currency: $currency);
		if ($rate === null) {
			return null;
		}

		return ($amount * $rate);
	}             

	   
                                                    
   
                                                   
   
                                    
                                                              
    
	public function getRate(string $currency): ?float {
		$currency = strtoupper(trim($currency));
		if ($currency === '') {
			return null;
		}

		$override = $this->appConfig->getValueString(
			Application::APP_ID,
			'messaging.exchange_rates_json',
			''
		);

		if ($override !== '') {
			try {
				$decoded = json_decode($override, true, 512, JSON_THROW_ON_ERROR);
				if (is_array($decoded) === true && isset($decoded[$currency]) === true && is_numeric($decoded[$currency]) === true) {
					return (float)$decoded[$currency];
				}
			} catch (Throwable $e) {
				$this->logger->warning(
					'MessagingExchangeRateService.getRate: invalid exchange_rates_json',
					['exception' => $e->getMessage()]
				);
			}
		}

		if (isset(self::FALLBACK_RATES[$currency]) === true) {
			return self::FALLBACK_RATES[$currency];
		}

		return null;
	}               
}           
