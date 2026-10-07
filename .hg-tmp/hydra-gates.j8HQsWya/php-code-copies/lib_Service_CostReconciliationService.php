<?php

   
                                      
  
                                                                  
                                                                      
                                                                    
           
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                             
  
                       
                                                                 
             
  
                                                               
                          
  
                                                                   
   
class CostReconciliationService {
	   
                          
    
	private const DEFAULT_REGISTER_SLUG = 'pipelinq';

	   
                                
    
	private const DEFAULT_SCHEMA_SLUG = 'channelMessage';

	   
                
   
                                                      
                                            
                                                                   
                                          
   
                                                                    
    
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private MessagingExchangeRateService $exchangeRate,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                             
   
                                                         
   
                                                                    
   
                                                                                  
                                                            
                                                                                         
                                                           
    
	public function reconcile(): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return ['scanned' => 0, 'reconciled' => 0];
		}

		try {
			$rows = $objectService->findAll(
				config: [
					'filters' => [
						'metadata.costCurrencyPending' => true,
						'register' => $this->getRegisterSlug(),
						'schema' => $this->getSchemaSlug(),
					],
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'CostReconciliationService.reconcile: findAll failed — falling back to full scan',
				['exception' => $e->getMessage()]
			);

			try {
				$rows = $objectService->findAll(
					config: [
						'filters' => [
							'register' => $this->getRegisterSlug(),
							'schema' => $this->getSchemaSlug(),
						],
					]
				);
			} catch (Throwable $e2) {
				return ['scanned' => 0, 'reconciled' => 0];
			}
		}         

		$scanned = 0;
		$reconciled = 0;
		foreach (($rows ?? []) as $raw) {
			$arr = $this->toArray(value: $raw);
			$meta = (array)($arr['metadata'] ?? []);
			$pending = (bool)($meta['costCurrencyPending'] ?? false);
			if ($pending === false) {
				continue;
			}

			$scanned++;
			$currency = (string)($meta['costCurrency'] ?? '');
			$amount = (float)($meta['costSourceAmount'] ?? 0.0);
			if ($currency === '' || $amount <= 0.0) {
				continue;
			}

			$eur = $this->exchangeRate->toEur(amount: $amount, currency: $currency);
			if ($eur === null) {
				continue;
			}

			$arr['costEur'] = round($eur, 6);
			$meta['costCurrencyPending'] = false;
			$meta['costReconciledAt'] = $this->nowIso();
			$arr['metadata'] = $meta;

			$id = $this->extractId(payload: $arr);
			try {
				$uuid = $id;
				if ($id === '') {
					$uuid = null;
				}

				$objectService->saveObject(
					object: $arr,
					register: $this->getRegisterSlug(),
					schema: $this->getSchemaSlug(),
					uuid: $uuid,
				);
				$reconciled++;
			} catch (Throwable $e) {
				$this->logger->warning(
					'CostReconciliationService.reconcile: save failed',
					['id' => $id, 'exception' => $e->getMessage()]
				);
			}
		}             

		return ['scanned' => $scanned, 'reconciled' => $reconciled];
	}                 

	   
                                       
   
                                        
    
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'CostReconciliationService.getObjectService: OpenRegister unavailable',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}                        

	   
                                       
   
                                        
   
                                               
    
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialised = $value->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($value) === true && method_exists($value, 'getObject') === true) {
			$payload = $value->getObject();
			if (is_array($payload) === true) {
				return $payload;
			}
		}

		return [];
	}               

	   
                                              
   
                                                 
   
                               
   
                                                                                    
                                                         
    
	private function extractId(array $payload): string {
		foreach (['uuid', 'id', 'slug'] as $key) {
			if (isset($payload[$key]) === true && is_scalar($payload[$key]) === true && (string)$payload[$key] !== '') {
				return (string)$payload[$key];
			}
		}

		if (isset($payload['@self']) === true && is_array($payload['@self']) === true) {
			foreach (['uuid', 'id', 'slug'] as $key) {
				$value = ($payload['@self'][$key] ?? null);
				if (is_scalar($value) === true && (string)$value !== '') {
					return (string)$value;
				}
			}
		}

		return '';
	}                 

	   
                  
   
                        
    
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_REGISTER_SLUG;
	}                       

	   
                
   
                        
    
	private function getSchemaSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'message_schema', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_SCHEMA_SLUG;
	}                     

	   
                                   
   
                             
    
	private function nowIso(): string {
		return gmdate('Y-m-d\TH:i:s\Z');
	}              
}           
