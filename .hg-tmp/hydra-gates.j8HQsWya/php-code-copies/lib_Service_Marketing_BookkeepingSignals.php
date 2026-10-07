<?php

   
                               
  
                                                                            
                                                                         
                                                                            
                           
  
                                                                         
                                                                             
                                                                           
                                                                      
                                                                  
  
                                                                             
                                                                            
                                                          
                                                       
  
                    
                                           
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                                                                                              
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ShillinqInvoiceReader;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;

   
                                                                     
  
                                                                                                                                                                                              
   
class BookkeepingSignals {

	   
                                                                     
   
            
    
	private const DEFAULT_WINDOW_MONTHS = 12;

	   
                                                                 
   
              
    
	private const DEFAULT_TOP_THRESHOLD = 25000.0;

	   
                                                                 
   
              
    
	private const DEFAULT_MID_THRESHOLD = 5000.0;

	   
                                                       
   
              
    
	private const MONTH = 30.436875;

	   
                     
   
            
    
	private const DAY = 86400;

	   
                                    
   
                           
    
	private const WORST_FIRST = ['written-off', 'overdue', 'disputed'];

	   
                
   
                                                                             
                                                     
                                    
   
                                                                                                                                                                                               
    
	public function __construct(
		private ShillinqInvoiceReader $invoices,
		private IAppConfig $appConfig,
		private ITimeFactory $time,
	) {
	}                   

	   
                                    
   
                                           
   
                                                                                                                                                            
    
	public function isAvailable(): bool {
		return $this->invoices->isAvailable();
	}                   

	   
                                              
   
                                                                          
                                                       
                                                                                             
   
                                                                          
   
                                                                                                                                                                                               
    
	public function forCustomer(string $customerRef, array $catalogue): array {
		$blank = [
			SegmentSignalService::FIELD_REVENUE => null,
			SegmentSignalService::FIELD_VALUE_TIER => null,
			SegmentSignalService::FIELD_MONTHS_SINCE_INVOICE => null,
			SegmentSignalService::FIELD_PRODUCTS => null,
			SegmentSignalService::FIELD_SERVICES => null,
			SegmentSignalService::FIELD_DUNNING => null,
		];

		$customerRef = trim($customerRef);
		if ($customerRef === '' || $this->invoices->isAvailable() === false) {
			return $blank;
		}

		$invoices = $this->invoices->invoicesFor(customerRef: $customerRef);
		if ($invoices === []) {
			return $blank;
		}

		$revenue = $this->revenueOf(customerRef: $customerRef);

		return [
			SegmentSignalService::FIELD_REVENUE => $revenue,
			SegmentSignalService::FIELD_VALUE_TIER => $this->tierOf(revenue: $revenue),
			SegmentSignalService::FIELD_MONTHS_SINCE_INVOICE => $this->monthsSince(invoices: $invoices),
			SegmentSignalService::FIELD_PRODUCTS => $this->itemsOf(invoices: $invoices, catalogue: $catalogue, wanted: 'product'),
			SegmentSignalService::FIELD_SERVICES => $this->itemsOf(invoices: $invoices, catalogue: $catalogue, wanted: 'service'),
			SegmentSignalService::FIELD_DUNNING => $this->dunningOf(invoices: $invoices),
		];
	}                   

	   
                                        
   
                                                               
   
                                                
    
	private function revenueOf(string $customerRef): float {
		$now = $this->time->getTime();
		$months = max(1, $this->appConfig->getValueInt(Application::APP_ID, 'marketing.signal_window_months', self::DEFAULT_WINDOW_MONTHS));
		$from = date('Y-m-d', (int)strtotime('-' . $months . ' months', $now));
		$to = date('Y-m-d', $now);

		$total = 0.0;
		foreach ($this->invoices->paidInvoicesFor(customerRef: $customerRef, from: $from, to: $to) as $invoice) {
			$total += (float)($invoice['amount'] ?? 0);
		}

		return round($total, 2);
	}                 

	   
                                
   
                                                             
   
                                              
    
	private function tierOf(float $revenue): string {
		if ($revenue >= $this->threshold(key: 'marketing.value_tier_top', default: self::DEFAULT_TOP_THRESHOLD)) {
			return 'top';
		}

		if ($revenue >= $this->threshold(key: 'marketing.value_tier_mid', default: self::DEFAULT_MID_THRESHOLD)) {
			return 'mid';
		}

		if ($revenue > 0.0) {
			return 'low';
		}

		return 'none';
	}              

	   
                                               
   
                                                                              
   
                                                                             
    
	private function monthsSince(array $invoices): ?int {
		$latest = '';
		foreach ($invoices as $invoice) {
			$date = (string)($invoice['invoiceDate'] ?? '');
			if ($date > $latest) {
				$latest = $date;
			}
		}

		if ($latest === '') {
			return null;
		}

		$stamp = strtotime($latest);
		if ($stamp === false) {
			return null;
		}

		$days = (int)floor((($this->time->getTime() - $stamp) / self::DAY));
		if ($days < 0) {
			return 0;
		}

		return (int)floor(($days / self::MONTH));
	}                   

	   
                                                                        
   
                                                                    
                                                                         
                                                                           
                                                                          
                                                                         
                           
   
                                                                              
                                                                               
                                                 
   
                                                                              
    
	private function itemsOf(array $invoices, array $catalogue, string $wanted): array {
		$found = [];
		foreach ($invoices as $invoice) {
			foreach ((array)($invoice['lines'] ?? []) as $line) {
				$key = strtolower(trim((string)($line['itemName'] ?? '')));
				if ($key !== '' && ($catalogue[$key] ?? '') === $wanted) {
					$found[$key] = true;
				}
			}
		}

		$names = array_keys($found);
		sort($names);
		return $names;
	}               

	   
                                                      
   
                                                                              
   
                                                                           
    
	private function dunningOf(array $invoices): string {
		$states = [];
		foreach ($invoices as $invoice) {
			$states[(string)($invoice['lifecycleState'] ?? '')] = true;
		}

		foreach (self::WORST_FIRST as $worst) {
			if (array_key_exists($worst, $states) === true) {
				return $worst;
			}
		}

		if ($states === []) {
			return SegmentSignalService::DUNNING_UNKNOWN;
		}

		return 'current';
	}                 

	   
                                  
   
                                      
                                                                       
   
                                
    
	private function threshold(string $key, float $default): float {
		$raw = trim($this->appConfig->getValueString(Application::APP_ID, $key, ''));
		if ($raw === '' || is_numeric($raw) === false) {
			return $default;
		}

		return (float)$raw;
	}                 
}           
