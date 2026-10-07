<?php

   
                                  
  
                                                                         
                                                                       
                       
  
                                                                          
                                                                         
                                                                         
                                                                         
                      
  
                                                               
                                                                        
                                                                     
                                                                        
                                           
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                                                                           
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Service\Marketing\ShillinqInvoiceProjection;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                          
  
                                                                                                                                                                           
   
class ShillinqInvoiceReader {

	   
                             
   
               
    
	public const SHILLINQ_REGISTER = 'shillinq';

	   
                                                 
   
               
    
	public const AR_INVOICE_SCHEMA = 'ARInvoice';

	   
                                                           
   
               
    
	public const SHILLINQ_PROBE_CLASS = 'OCA\\Shillinq\\AppInfo\\Application';

	   
                                                     
   
               
    
	public const PAID_STATE = 'paid';

	   
                                                            
   
            
    
	private const PAGE = 100;

	   
                                                             
   
            
    
	private const MAX_ROWS = 500;

	   
                
   
                                                                                              
                                          
                                                                                                 
   
                                                                                                                                                                            
    
	public function __construct(
		private ContainerInterface $container,
		private LoggerInterface $logger,
		private ShillinqInvoiceProjection $projection = new ShillinqInvoiceProjection(),
	) {
	}                   

	   
                                                                         
   
                                               
   
                                                                                                                                                                            
    
	public function isAvailable(): bool {
		return $this->probe();
	}                   

	   
                                                                  
   
                                                                  
                                                                           
                                                             
                                                         
   
                                                                                                                      
                                                                     
   
                                                                                                                                                                            
    
	public function paidInvoicesFor(string $customerRef, string $from, string $to): array {
		$customer = trim($customerRef);
		if ($customer === '' || $this->probe() === false) {
			return [];
		}

		$objectService = $this->objectService();
		if ($objectService === null) {
			return [];
		}

		$rows = $this->pages(
			objectService: $objectService,
			filters: [
				'register' => self::SHILLINQ_REGISTER,
				'schema' => self::AR_INVOICE_SCHEMA,
				'customerId' => $customer,
				'lifecycleState' => self::PAID_STATE,
			],
			maxRows: self::MAX_ROWS
		);

		$invoices = [];
		foreach ($rows as $row) {
			$invoice = $this->normalise(row: $row);
			if ($invoice === null || $this->inWindow(date: $invoice['invoiceDate'], from: $from, to: $to) === false) {
				continue;
			}

			$invoices[] = $invoice;
		}

		return $invoices;
	}                       

	   
                                                                        
   
                                                                       
                                                                         
                                                                         
                                                                          
                                                                         
                                                                  
   
                                                                          
                                                                         
                               
   
                                                                  
                                                                           
   
                                                                         
                                                                            
                                                                          
   
                                                                                                                                                                                               
    
	public function invoicesFor(string $customerRef): array {
		$customer = trim($customerRef);
		if ($customer === '' || $this->probe() === false) {
			return [];
		}

		$objectService = $this->objectService();
		if ($objectService === null) {
			return [];
		}

		$rows = $this->pages(
			objectService: $objectService,
			filters: [
				'register' => self::SHILLINQ_REGISTER,
				'schema' => self::AR_INVOICE_SCHEMA,
				'customerId' => $customer,
			],
			maxRows: self::MAX_ROWS
		);

		$invoices = [];
		foreach ($rows as $row) {
			$invoice = $this->projection->whole(row: $row);
			if ($invoice !== null) {
				$invoices[] = $invoice;
			}
		}

		return $invoices;
	}                   

	   
                                                                    
                                                            
   
                                                 
   
                                                                                                                                                                            
    
	protected function probe(): bool {
		return class_exists(self::SHILLINQ_PROBE_CLASS);
	}             

	   
                                                                     
   
                                    
    
	private function objectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'ShillinqInvoiceReader: OpenRegister unavailable',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}                     

	   
                                                                  
   
                                                                 
                                                                                   
                                                            
   
                                                        
    
	private function pages(object $objectService, array $filters, int $maxRows): array {
		$rows = [];
		$offset = 0;
		while ($offset < $maxRows) {
			try {
				$page = $objectService->findAll(
					config: ['filters' => $filters, 'limit' => self::PAGE, 'offset' => $offset],
					_rbac: false,
					_multitenancy: false
				);
			} catch (Throwable $e) {
				$this->logger->warning(
					'ShillinqInvoiceReader: reading AR invoices failed',
					['customer' => (string)($filters['customerId'] ?? ''), 'exception' => $e->getMessage()]
				);
				break;
			}

			if (is_iterable($page) === false) {
				break;
			}

			$count = 0;
			foreach ($page as $row) {
				$rows[] = $this->toArray(value: $row);
				$count++;
			}

			if ($count < self::PAGE) {
				break;
			}

			$offset += self::PAGE;
		}

		return $rows;
	}             

	   
                                                                     
   
                                                                      
                                                                        
                                                                      
                                                    
   
                                                             
   
                                                                                                               
                                                                
    
	private function normalise(array $row): ?array {
		if ((string)($row['lifecycleState'] ?? '') !== self::PAID_STATE) {
			return null;
		}

		$id = $this->projection->identify(row: $row);
		if ($id === '') {
			return null;
		}

		return [
			'id' => $id,
			'amount' => (float)($row['grossAmount'] ?? 0),
			'currency' => (string)($row['currency'] ?? 'EUR'),
			'invoiceDate' => substr((string)($row['invoiceDate'] ?? ''), 0, 10),
			'invoiceNumber' => (string)($row['invoiceNumber'] ?? ''),
		];
	}                 

	   
                                                    
   
                                                                        
                                                                    
                                                   
   
                                                                    
                                     
                                 
   
                                     
    
	private function inWindow(string $date, string $from, string $to): bool {
		if ($date === '') {
			return true;
		}

		if ($from !== '' && $date < $from) {
			return false;
		}

		return ($to === '' || $date <= $to);
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
}           
