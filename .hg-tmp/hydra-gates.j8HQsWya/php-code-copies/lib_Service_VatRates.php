<?php

   
                                                                 
  
                                                                           
                                                                           
                                                                     
  
                    
                                 
  
                                                              
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                            
  
                                                                             
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;

   
                                                                            
  
                                               
   
class VatRates {
	   
                                            
   
               
    
	public const CONFIG_KEY = 'vat_rates';

	   
                                                               
   
                            
    
	public const DEFAULTS = [
		'high' => 21.0,
		'low' => 9.0,
		'zero' => 0.0,
		'exempt' => 0.0,
	];

	   
                                                       
   
                                                                          
                                                                   
   
                                                 
   
                                                                    
   
                                                                              
    
	public static function parse(string $stored): array {
		$rates = self::DEFAULTS;
		$decoded = json_decode($stored, true);
		if (is_array($decoded) === false) {
			return $rates;
		}

		foreach (array_keys(self::DEFAULTS) as $class) {
			$value = ($decoded[$class] ?? null);
			if (is_numeric($value) === true && (float)$value >= 0 && (float)$value <= 100) {
				$rates[$class] = (float)$value;
			}
		}

		return $rates;
	}             

	   
                
   
                                                                    
    
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}                   

	   
                         
   
                                               
   
                                                                              
    
	public function rates(): array {
		return self::parse(stored: $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, ''));
	}             
}           
