<?php

   
                                    
  
                                                                            
                                                                        
                                                                               
                                                           
  
                                                 
                      
                          
                           
                         
                                                                
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                    
                                    
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                   
  
                                                                                               
   
class RecurringRevenueService {
	   
                                                       
   
                 
    
	private const REVENUE_STATUSES = ['active', 'expiring'];

	   
                
   
                                                
                                                                                 
                                              
    
	public function __construct(
		private IAppConfig $appConfig,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                                     
   
                                                        
                                                          
   
                                                                           
   
                                                                                                
    
	public function normalizeToMonthly(string $billingInterval, float $valuePerInterval): float {
		switch ($billingInterval) {
			case 'monthly':
				return $valuePerInterval;
			case 'quarterly':
				return ($valuePerInterval / 3.0);
			case 'annual':
				return ($valuePerInterval / 12.0);
			case 'one-off':
			default:
				return 0.0;
		}
	}                          

	   
                                                                               
   
                                                                           
   
                                                
   
                                                                                                
    
	public function computeMrr(array $contracts): float {
		$mrr = 0.0;
		foreach ($contracts as $contract) {
			$status = (string)($contract['status'] ?? '');
			if (in_array($status, self::REVENUE_STATUSES, true) === false) {
				continue;
			}

			$mrr += $this->normalizeToMonthly(
				billingInterval: (string)($contract['billingInterval'] ?? ''),
				valuePerInterval: (float)($contract['valuePerInterval'] ?? 0)
			);
		}

		return round($mrr, 2);
	}                  

	   
                           
   
                                                                           
   
                                               
   
                                                                                                
    
	public function computeArr(array $contracts): float {
		return round(($this->computeMrr(contracts: $contracts) * 12.0), 2);
	}                  

	   
                                                                    
   
                                                                    
                                             
   
                                                       
   
                                                                                                
    
	public function computeClientMrr(array $contracts, string $clientRef): float {
		$clientContracts = array_filter(
			$contracts,
			static fn (array $c): bool => ((string)($c['clientRef'] ?? '')) === $clientRef
		);

		return $this->computeMrr(contracts: array_values($clientContracts));
	}                        

	   
                                                                        
                                          
   
                                                                          
                                                      
   
                                                                    
                                                                          
                                                                        
   
                                                                                
   
                                                                                                
    
	public function computeRenewalMetrics(array $contracts, string $periodFrom, string $periodTo): array {
		$renewed = 0;
		$churned = 0;
		$churnedMrr = 0.0;

		foreach ($contracts as $contract) {
			$status = (string)($contract['status'] ?? '');
			$endDate = (string)($contract['endDate'] ?? '');
			if ($endDate === '' || $endDate < $periodFrom || $endDate > $periodTo) {
				continue;
			}

			if ($status === 'renewed') {
				$renewed++;
			} elseif ($status === 'churned') {
				$churned++;
				$churnedMrr += $this->normalizeToMonthly(
					billingInterval: (string)($contract['billingInterval'] ?? ''),
					valuePerInterval: (float)($contract['valuePerInterval'] ?? 0)
				);
			}
		}             

		$closed = ($renewed + $churned);
		$renewalRate = 0.0;
		if ($closed > 0) {
			$renewalRate = round((($renewed / $closed) * 100.0), 1);
		}

		return [
			'renewed' => $renewed,
			'churned' => $churned,
			'renewalRate' => $renewalRate,
			'churnedMrr' => round($churnedMrr, 2),
		];
	}                             

	   
                                                                     
                                                                             
   
                                                                 
   
                                                                                                
    
	public function loadContracts(): array {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schemaId = $this->appConfig->getValueString(Application::APP_ID, 'contract_schema', '');
		if ($registerId === '' || $schemaId === '') {
			return [];
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$results = $objectService->findAll(
				[
					'filters' => [
						'register' => $registerId,
						'schema' => $schemaId,
					],
					'limit' => 10000,
				]
			);

			$contracts = [];
			foreach ($results as $row) {
				if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
					$contracts[] = $row->jsonSerialize();
				} elseif (is_array($row) === true) {
					$contracts[] = $row;
				}
			}

			return $contracts;
		} catch (Throwable $e) {
			$this->logger->warning(
				'RecurringRevenueService: failed to load contracts',
				['error' => $e->getMessage()]
			);
			return [];
		}         
	}                     

	   
                                                               
   
                                                                           
   
                                                                                                
    
	public function getSummary(): array {
		$contracts = $this->loadContracts();

		$activeCount = 0;
		$expiringCount = 0;
		foreach ($contracts as $contract) {
			$status = (string)($contract['status'] ?? '');
			if ($status === 'active') {
				$activeCount++;
			} elseif ($status === 'expiring') {
				$expiringCount++;
			}
		}

		return [
			'mrr' => $this->computeMrr(contracts: $contracts),
			'arr' => $this->computeArr(contracts: $contracts),
			'activeCount' => $activeCount,
			'expiringCount' => $expiringCount,
		];
	}                  
}           
