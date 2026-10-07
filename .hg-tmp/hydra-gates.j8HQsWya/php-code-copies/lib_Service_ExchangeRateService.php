<?php

   
                                
  
                                                                               
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use InvalidArgumentException;
use OCP\IAppConfig;

   
                                                           
  
                                                                              
                                                                               
                                                                             
                                               
  
                                                                                     
                                                
   
class ExchangeRateService {
	   
                                                  
   
                                                                       
                                                                            
                                                                     
   
               
    
	public const REPORTING_CURRENCY_KEY = 'currency';

	   
                                                                          
                                                                     
   
               
    
	public const LEGACY_REPORTING_CURRENCY_KEY = 'forecast_reporting_currency';

	   
                                                       
   
               
    
	public const REPORTING_CURRENCY_DEFAULT = 'EUR';

	   
                                                                     
   
               
    
	public const RATES_KEY = 'forecast_exchange_rates';

	   
                
   
                                                       
    
	public function __construct(
		private IAppConfig $appConfig,
	) {
	}                   

	   
                                                  
   
                                               
   
                                                                                  
    
	public function getReportingCurrency(): string {
		foreach ([self::REPORTING_CURRENCY_KEY, self::LEGACY_REPORTING_CURRENCY_KEY] as $key) {
			$currency = strtoupper(trim($this->appConfig->getValueString(Application::APP_ID, $key, '')));
			if ($currency !== '') {
				return $currency;
			}
		}

		return self::REPORTING_CURRENCY_DEFAULT;
	}                            

	   
                                                                         
   
                                                           
                                                                                                
   
                                                                              
   
                                                                                  
    
	public function toReportingCurrency(float $amount, ?string $currency): float {
		$source = strtoupper(trim((string)$currency));
		$reporting = $this->getReportingCurrency();
		if ($source === '' || $source === $reporting) {
			return round($amount, 2);
		}

		$rate = $this->rateFor(currency: $source);
		return round($amount * $rate, 2);
	}                           

	   
                                                                            
   
                                                                       
   
                                                                                  
    
	public function getRates(): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::RATES_KEY, '');
		$table = json_decode($raw, true);
		if (is_array($table) === false) {
			return [];
		}

		$rates = [];
		foreach ($table as $code => $rate) {
			if (is_numeric($rate) === true && (float)$rate > 0) {
				$rates[strtoupper(trim((string)$code))] = (float)$rate;
			}
		}

		ksort($rates);
		return $rates;
	}                

	   
                                                   
   
                                                            
   
                                                                         
   
                                                                                                  
   
                                                                                  
    
	public function normaliseRates(array $input): array {
		$rates = [];
		foreach ($input as $code => $rate) {
			$currency = strtoupper(trim((string)$code));
			if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
				throw new InvalidArgumentException("'{$code}' is not a three-letter currency code.");
			}

			if (is_numeric($rate) === false || (float)$rate <= 0) {
				throw new InvalidArgumentException("The rate for {$currency} must be a number above zero.");
			}

			$rates[$currency] = (float)$rate;
		}

		ksort($rates);
		return $rates;
	}                      

	   
                                                      
   
                                                                            
   
                                                                                      
    
	private function rateFor(string $currency): float {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::RATES_KEY, '');
		if ($raw === '') {
			return 1.0;
		}

		$table = json_decode($raw, true);
		if (is_array($table) === false) {
			return 1.0;
		}

		$rate = $table[$currency] ?? null;
		if (is_numeric($rate) === false || (float)$rate <= 0) {
			return 1.0;
		}

		return (float)$rate;
	}               
}           
