<?php

   
                                  
  
                                                                
                                                                     
                                                                   
                                                                 
  
                                                                      
                                                                      
                           
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                 
  
                       
                                                                
                                          
  
                                                                   
   
class CostEstimationService {
	   
                                                                  
                                            
   
                                                                          
    
	private const DEFAULT_PRICE_TABLE = [
		'meta' => [
			'whatsapp' => [
				'utility' => [
					'NL' => 0.012,
					'BE' => 0.012,
					'DE' => 0.015,
					'default' => 0.020,
				],
				'marketing' => [
					'NL' => 0.060,
					'BE' => 0.060,
					'DE' => 0.070,
					'default' => 0.080,
				],
				'authentication' => [
					'NL' => 0.015,
					'BE' => 0.015,
					'DE' => 0.018,
					'default' => 0.022,
				],
			],
		],
		'360dialog' => [
			'whatsapp' => [
				'utility' => ['default' => 0.025],
				'marketing' => ['default' => 0.090],
				'authentication' => ['default' => 0.025],
			],
		],
	];

	   
                
   
                                            
                                          
   
                                                                    
    
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                                  
   
                                                                 
                                                           
   
                                                     
                                                   
                                                                                   
                                                                         
   
                                                                
   
                                                                    
    
	public function estimate(
		string $vendor,
		string $channel,
		string $category = 'utility',
		string $country = 'NL',
	): float {
		$table = $this->loadTable();

		$vendorRow = ($table[$vendor] ?? null);
		if (is_array($vendorRow) === false) {
			return 0.0;
		}

		$channelRow = ($vendorRow[$channel] ?? null);
		if (is_array($channelRow) === false) {
			return 0.0;
		}

		$catRow = ($channelRow[$category] ?? ($channelRow['default'] ?? null));
		if (is_array($catRow) === false) {
			if (is_numeric($catRow) === true) {
				return (float)$catRow;
			}

			return 0.0;
		}

		if (isset($catRow[$country]) === true && is_numeric($catRow[$country]) === true) {
			return (float)$catRow[$country];
		}

		if (isset($catRow['default']) === true && is_numeric($catRow['default']) === true) {
			return (float)$catRow['default'];
		}

		return 0.0;
	}                

	   
                                                                              
   
                                             
    
	private function loadTable(): array {
		$override = $this->appConfig->getValueString(
			Application::APP_ID,
			'messaging.price_table_json',
			''
		);

		if ($override === '') {
			return self::DEFAULT_PRICE_TABLE;
		}

		try {
			$decoded = json_decode($override, true, 512, JSON_THROW_ON_ERROR);
		} catch (Throwable $e) {
			$this->logger->warning(
				'CostEstimationService.loadTable: invalid price_table_json — using defaults',
				['exception' => $e->getMessage()]
			);
			return self::DEFAULT_PRICE_TABLE;
		}

		if (is_array($decoded) === false) {
			return self::DEFAULT_PRICE_TABLE;
		}

		                                                         
		                                                     
		$merged = self::DEFAULT_PRICE_TABLE;
		foreach ($decoded as $vendor => $value) {
			if (is_string($vendor) === true) {
				$merged[$vendor] = $value;
			}
		}

		return $merged;
	}                 
}           
