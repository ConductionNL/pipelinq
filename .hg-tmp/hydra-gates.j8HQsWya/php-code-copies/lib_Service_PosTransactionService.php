<?php

   
                                  
  
                                                                             
                                                            
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                           
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Event\PosStockMovedEvent;
use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

   
                                                   
  
                                                                             
                                                                            
                                                                               
  
                                                                            
                                                                                
                                                                                
                                                                
                                                                            
                                                          
  
                                                                                  
                                                                            
                                                                           
                           
                                                                             
                                                                               
                                                                               
                                                                               
                                                     
                                                                                  
                                                                                
                                                                          
                                                                      
                                                                           
                                                                                 
                                                                                
                                                                              
             
                                                                                   
                                                                               
                                                                                 
                                                             
  
                                                           
                                                                   
   
class PosTransactionService {
	   
                                            
   
               
    
	public const EVENT_CONFIRMED = 'pipelinq.PosTransaction.confirmed';

	   
                                                                             
   
               
   
                                                                                                                                                              
    
	public const EVENT_STOCK_MOVED = 'nl.pipelinq.pos.stock.moved';

	   
                                                             
   
               
    
	private const EVENT_SOURCE = '/apps/pipelinq/pos';

	   
                                                                         
                                                                           
                                                                         
   
               
    
	private const SHILLINQ_ADMINISTRATION_CONTEXT_SERVICE = 'OCA\\Shillinq\\Service\\AdministrationContextService';

	   
                
   
                                                          
                                                
                                                                
                                              
                                                                                     
    
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private PosAccessPolicy $policy,
		private LoggerInterface $logger,
		private IEventDispatcher $eventDispatcher,
	) {
	}                   

	   
                                                        
   
                           
    
	private const PRICE_MODES = ['excl', 'incl'];

	   
                                             
   
               
    
	private const DEFAULT_PRICE_MODE = 'excl';

	   
                                                                       
                                                                     
                                       
   
                                                                          
                                                                        
                                                                             
                                                                        
                                                                              
                                                                             
                                                                                
   
                              
    
	private const TRANSITION_STRING_DEFAULTS = ['consentSyncStatus' => 'pending'];

	   
                                                                           
                                                                        
                                                 
   
                           
    
	private const RATE_DESCRIPTIONS = [
		0 => 'Nultarief (0%)',
		9 => 'Verlaagd tarief (9%)',
		21 => 'Standaardtarief (21%)',
	];

	   
                                         
   
                                           
   
                                             
    
	private function money(float $value): float {
		return round($value, 2);
	}             

	   
                                                            
   
                                                                           
                                                              
   
                                          
   
                                           
   
                                                  
    
	public function normalizePriceMode(mixed $mode): string {
		$value = '';
		if (is_string($mode) === true) {
			$value = strtolower(trim($mode));
		}

		if (in_array($value, self::PRICE_MODES, true) === true) {
			return $value;
		}

		return self::DEFAULT_PRICE_MODE;
	}                          

	   
                                                       
   
                                               
   
                                   
   
                                                  
    
	private function rateDescription(float $rate): string {
		$intRate = (int)round($rate);
		if (isset(self::RATE_DESCRIPTIONS[$intRate]) === true) {
			return self::RATE_DESCRIPTIONS[$intRate];
		}

		return rtrim(rtrim((string)$rate, '0'), '.') . '% BTW';
	}                       

	   
                                                      
   
                                                                             
                                                                            
                                                                            
   
                                                                             
                                                                          
                                                               
                                       
                                                                               
                                                               
                                               
                                 
                  
                                                                          
                                                                              
                                                                          
   
                                                            
                                                                             
                                                                             
                                                        
   
                                                                                          
   
                                                            
                                                  
    
	public function recalculateLine(array $lineData, ?string $priceMode = null): array {
		$quantity = max(0.0, (float)($lineData['quantity'] ?? 0));
		$unitPrice = max(0.0, (float)($lineData['unitPrice'] ?? 0));
		$discount = min(100.0, max(0.0, (float)($lineData['discount'] ?? 0)));
		$taxRate = min(100.0, max(0.0, (float)($lineData['taxRate'] ?? 21)));

		$mode = $this->normalizePriceMode(mode: ($priceMode ?? ($lineData['priceMode'] ?? null)));

		$amount = ($quantity * $unitPrice * (1 - ($discount / 100)));

		                                                                      
		$net = $amount;
		$taxAmount = ($net * ($taxRate / 100));

		if ($mode === 'incl') {
			                                                                   
			$net = ($amount / (1 + ($taxRate / 100)));
			$taxAmount = ($amount - $net);
		}

		$lineData['quantity'] = $quantity;
		$lineData['unitPrice'] = $unitPrice;
		$lineData['discount'] = $discount;
		$lineData['taxRate'] = $taxRate;
		$lineData['net'] = $this->money(value: $net);
		$lineData['taxAmount'] = $this->money(value: $taxAmount);
		$lineData['lineTotal'] = $this->money(value: ($net + $taxAmount));

		return $lineData;
	}                       

	   
                                                                   
   
                                                                        
                                                                           
                                                                        
                                                                            
   
                                                                            
                                                                              
                                                                               
                                                   
   
                                                                                
                                                            
                                                                           
   
                                                                                     
                                                                                 
   
                                                            
                                                                             
    
	public function computeTotals(array $lines, ?string $priceMode = null): array {
		$mode = $this->normalizePriceMode(mode: $priceMode);
		$subtotal = 0.0;
		$discountTotal = 0.0;
		$totalTax = 0.0;
		$byRate = [];

		foreach ($lines as $rawLine) {
			$line = $this->recalculateLine(lineData: $rawLine, priceMode: $mode);
			$taxRate = (float)$line['taxRate'];

			                                                                  
			                                                                  
			                                                                  
			$net = (float)$line['net'];
			$taxAmount = (float)$line['taxAmount'];
			$grossNoDisc = $this->lineNetBeforeDiscount(line: $line, mode: $mode);

			$subtotal += $net;
			$discountTotal += ($grossNoDisc - $net);
			$totalTax += $taxAmount;

			$rateKey = (string)$taxRate;
			if (isset($byRate[$rateKey]) === false) {
				$byRate[$rateKey] = ['rate' => $taxRate, 'base' => 0.0, 'tax' => 0.0];
			}

			$byRate[$rateKey]['base'] += $net;
			$byRate[$rateKey]['tax'] += $taxAmount;
		}             

		$taxBreakdown = [];
		$invoiceBreakdown = [];
		ksort($byRate, SORT_NUMERIC);
		foreach ($byRate as $entry) {
			$base = $this->money(value: $entry['base']);
			$tax = $this->money(value: $entry['tax']);

			$taxBreakdown[] = [
				'rate' => $entry['rate'],
				'base' => $base,
				'tax' => $tax,
			];

			$invoiceBreakdown[] = [
				'rate' => $entry['rate'],
				'base' => $base,
				'tax' => $tax,
				'description' => $this->rateDescription(rate: (float)$entry['rate']),
			];
		}             

		$subtotal = $this->money(value: $subtotal);
		$totalTax = $this->money(value: $totalTax);

		return [
			'priceMode' => $mode,
			'subtotal' => $subtotal,
			'discountTotal' => $this->money(value: $discountTotal),
			'taxBreakdown' => $taxBreakdown,
			'invoiceBreakdown' => $invoiceBreakdown,
			'totalTax' => $totalTax,
			'total' => $this->money(value: ($subtotal + $totalTax)),
		];
	}                     

	   
                                                                           
   
                                                                          
                                                                            
                                                    
   
                                                          
                                                  
   
                                                 
   
                                                  
    
	private function lineNetBeforeDiscount(array $line, string $mode): float {
		$quantity = (float)$line['quantity'];
		$unitPrice = (float)$line['unitPrice'];
		$taxRate = (float)$line['taxRate'];
		$gross = ($quantity * $unitPrice);

		if ($mode === 'incl') {
			return ($gross / (1 + ($taxRate / 100)));
		}

		return $gross;
	}                             

	   
                                                                            
   
                                                                             
                                                                           
                                                                       
                                                                              
                                          
   
                                                                             
                                                                            
   
                                                                                        
   
                                                                                 
                                                                                
   
                                                  
    
	public function buildTaxReport(array $transactions): array {
		$byRate = [];
		$totalBase = 0.0;
		$totalTax = 0.0;
		$includedCount = 0;

		foreach ($transactions as $transaction) {
			$status = (string)($transaction['status'] ?? '');
			if (in_array($status, ['confirmed', 'settled', 'refunded'], true) === false) {
				continue;
			}

			$sign = 1.0;
			if ($status === 'refunded') {
				$sign = -1.0;
			}

			$rows = $transaction['invoiceBreakdown'] ?? $transaction['taxBreakdown'] ?? [];
			if (is_array($rows) === false) {
				continue;
			}

			$includedCount++;
			foreach ($rows as $row) {
				if (is_array($row) === false) {
					continue;
				}

				$rate = (float)($row['rate'] ?? 0);
				$base = ($sign * (float)($row['base'] ?? 0));
				$tax = ($sign * (float)($row['tax'] ?? 0));
				$rateKey = (string)$rate;

				if (isset($byRate[$rateKey]) === false) {
					$byRate[$rateKey] = ['rate' => $rate, 'base' => 0.0, 'tax' => 0.0];
				}

				$byRate[$rateKey]['base'] += $base;
				$byRate[$rateKey]['tax'] += $tax;
				$totalBase += $base;
				$totalTax += $tax;
			}             
		}             

		$rates = [];
		ksort($byRate, SORT_NUMERIC);
		foreach ($byRate as $entry) {
			$rates[] = [
				'rate' => $entry['rate'],
				'base' => $this->money(value: $entry['base']),
				'tax' => $this->money(value: $entry['tax']),
				'description' => $this->rateDescription(rate: (float)$entry['rate']),
			];
		}

		return [
			'rates' => $rates,
			'totalBase' => $this->money(value: $totalBase),
			'totalTax' => $this->money(value: $totalTax),
			'transactionCount' => $includedCount,
		];
	}                      

	   
                                                                          
                                                     
   
                                                                              
                                                                             
                                                                          
   
                                                                                   
                                                                              
   
                                                       
   
                                                                             
   
                                                  
                                                                    
    
	public function taxReport(?string $status = null, string $userId = ''): array {
		if ($this->policy->isManager(userId: $userId) === false) {
			throw new OCSForbiddenException('Alleen een beheerder mag het BTW-rapport opvragen.');
		}

		$transactions = $this->fetchAllTransactions(status: $status);

		return $this->buildTaxReport(transactions: $transactions);
	}                 

	   
                                                                            
   
                                                      
   
                                                                
   
                                                                                          
   
                                                            
    
	public function recalculateTotals(string $transactionId): array {
		$transaction = $this->fetchTransaction(id: $transactionId);
		$mode = $this->normalizePriceMode(mode: ($transaction['priceMode'] ?? null));
		$lines = $this->fetchLines(transactionId: $transactionId);
		$totals = $this->computeTotals(lines: $lines, priceMode: $mode);

		$transaction = array_merge($transaction, $totals);

		return $this->saveTransaction(id: $transactionId, transaction: $transaction);
	}                         

	   
                                          
   
                                                                            
                                                                 
                                                                   
   
                                           
                                              
   
                                                           
   
                                                                   
                                                                             
   
                                                            
    
	public function confirmTransaction(string $id, string $userId): array {
		                                                                      
		                                                                     
		                                                                     
		                                                                   
		$transaction = $this->fetchTransaction(id: $id);

		                                                                    
		                                                                
		                                                                   
		$this->assertOnAccountHasCustomer(transaction: $transaction);

		$mode = $this->normalizePriceMode(mode: ($transaction['priceMode'] ?? null));
		$lines = $this->fetchLines(transactionId: $id);
		$totals = $this->computeTotals(lines: $lines, priceMode: $mode);

		$transaction = array_merge(
			$transaction,
			$totals,
			[
				'confirmedAt' => $this->now(),
				'parkedAt' => null,
			]
		);
		$this->saveTransaction(id: $id, transaction: $transaction);

		                                                                       
		                                                                         
		                                                      
		$saved = $this->transitionObject(id: $id, action: 'confirm');

		                                                                        
		                          
		$eventId = $this->emitConfirmedEvent(transaction: $saved);
		if ($eventId !== '') {
			$saved['cloudEventId'] = $eventId;
			$saved = $this->saveTransaction(id: $id, transaction: $saved);
		}

		$this->logger->info('Pipelinq: POS transaction confirmed', ['id' => $id, 'userId' => $userId]);

		return $saved;
	}                          

	   
                                                      
   
                                           
                                              
   
                                                         
   
                                                                   
                                                                               
                                                                       
   
                                                            
                                                                    
    
	public function settleTransaction(string $id, string $userId): array {
		$transaction = $this->fetchTransaction(id: $id);

		                                                                      
		                                                                     
		                                                                    
		                                                                    
		                                        
		$tenderService = $this->resolveTenderService();
		if ($tenderService !== null) {
			$tenderService->assertBalancedForSettle(transactionId: $id);
		}

		$transaction['settledAt'] = $this->now();
		$this->saveTransaction(id: $id, transaction: $transaction);

		$saved = $this->transitionObject(id: $id, action: 'settle');

		                                                                    
		                                                                
		                                                                    
		                                                                     
		if ($tenderService !== null) {
			try {
				$tenderService->emitTendersPosted(transactionId: $id);
			} catch (\Throwable $e) {
				$this->logger->warning(
					'Pipelinq: tender CloudEvent emission failed; retry job will pick up',
					['id' => $id, 'exception' => $e->getMessage()]
				);
			}
		}

		                                                                   
		                                                                      
		                                                                    
		                                                                   
		try {
			$this->emitStockMovedEvent(transactionId: $id);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: stock-moved CloudEvent emission failed',
				['id' => $id, 'exception' => $e->getMessage()]
			);
		}

		$this->logger->info('Pipelinq: POS transaction settled', ['id' => $id, 'userId' => $userId]);

		return $saved;
	}                         

	   
                                                                       
                                                                      
                                                 
   
                                                                        
   
                                                                
    
	private function resolveTenderService(): ?PosTenderService {
		try {
			$service = $this->container->get(PosTenderService::class);
		} catch (\Throwable $e) {
			return null;
		}

		if ($service instanceof PosTenderService) {
			return $service;
		}

		return null;
	}                            

	   
                                                                           
   
                                                                        
                                                                          
                                                                    
                                                                          
                                                                     
                                                                      
                                                                          
                          
   
                                                              
   
                                                                           
                                                                      
   
                                                                                                                                                              
    
	public function emitStockMovedEvent(string $transactionId): string {
		try {
			$transaction = $this->fetchTransaction(id: $transactionId);
		} catch (\Throwable $e) {
			return '';
		}

		$lines = $this->fetchLines(transactionId: $transactionId);
		if ($lines === []) {
			return '';
		}

		$eventLines = [];
		foreach ($lines as $line) {
			$productId = (string)($line['product'] ?? '');
			[$sku, $unit] = $this->resolveProductSkuAndUnit(productId: $productId);
			$eventLines[] = [
				'productRef' => $sku,
				'qty' => (float)($line['quantity'] ?? 0),
				'unit' => $unit,
				                                                               
				                                                   
				'location' => '',
			];
		}

		$eventId = $this->uuid();
		$event = new PosStockMovedEvent(
			eventId: $eventId,
			transactionUuid: $transactionId,
			transactionReference: (string)($transaction['reference'] ?? ''),
			administrationId: $this->resolveAdministrationId(),
			lines: $eventLines,
			emittedAt: $this->now(),
		);

		try {
			$this->eventDispatcher->dispatchTyped(event: $event);
		} catch (\Throwable $e) {
			$this->logger->info(
				'Pipelinq POS stock: typed dispatch failed; falling back to webhook',
				['transactionId' => $transactionId, 'exception' => $e->getMessage()]
			);
		}

		                                                               
		                                                                   
		                                                        
		try {
			$webhookService = $this->container->get('OCA\\OpenRegister\\Service\\WebhookService');
			$webhookService->dispatchEvent(
				_event: new Event(),
				eventName: self::EVENT_STOCK_MOVED,
				payload: $event->toCloudEvent()
			);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'Pipelinq POS stock: WebhookService unavailable; skipping external dispatch',
				['transactionId' => $transactionId, 'exception' => $e->getMessage()]
			);
		}

		return $eventId;
	}                           

	   
                                                                           
                                                                         
                                                                          
                                                        
   
                                                                     
   
                                                               
   
                                                                                                                                                              
    
	private function resolveProductSkuAndUnit(string $productId): array {
		if ($productId === '') {
			return ['', ''];
		}

		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'product_schema', '');
		if ($register === '' || $schema === '') {
			return ['', ''];
		}

		try {
			$product = $this->getObjectService()->find(id: $productId, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			return ['', ''];
		}

		if ($product === null) {
			return ['', ''];
		}

		$productArr = $this->toArray(object: $product);

		return [
			(string)($productArr['sku'] ?? ''),
			(string)($productArr['unit'] ?? ''),
		];
	}                                

	   
                                                                     
   
                                                                        
                                                                           
                                                                           
                                
   
                                         
   
                                                                                                                                                              
    
	private function resolveAdministrationId(): string {
		try {
			$contextService = $this->container->get(self::SHILLINQ_ADMINISTRATION_CONTEXT_SERVICE);
			if (is_object($contextService) === true && method_exists($contextService, 'buildContext') === true) {
				$context = $contextService->buildContext();
				$candidate = (string)($context['activeAdministrationId'] ?? '');
				if ($candidate !== '') {
					return $candidate;
				}
			}
		} catch (\Throwable $e) {
			                                     
		}

		return 'default';
	}                               

	   
                                                                    
   
                                           
                                                       
                                              
   
                                                          
   
                                                                   
                                                                       
                                                                                   
   
                                                            
                                                                    
    
	public function refundTransaction(string $id, string $reason, string $userId): array {
		if (trim($reason) === '') {
			throw new OCSBadRequestException('Vul een reden in voor de terugboeking.');
		}

		                                                                        
		                                                                     
		              
		$transaction = $this->fetchTransaction(id: $id);
		$transaction['refundedAt'] = $this->now();
		$transaction['refundReason'] = $reason;
		$this->saveTransaction(id: $id, transaction: $transaction);

		$saved = $this->transitionObject(id: $id, action: 'refund');

		$this->logger->info('Pipelinq: POS transaction refunded', ['id' => $id, 'userId' => $userId]);

		return $saved;
	}                         

	   
                                                        
   
                                           
                                              
   
                                                        
   
                                                                   
                                                                               
                                                                     
   
                                                            
                                                                    
    
	public function parkTransaction(string $id, string $userId): array {
		$transaction = $this->fetchTransaction(id: $id);
		$transaction['parkedAt'] = $this->now();
		$this->saveTransaction(id: $id, transaction: $transaction);

		$saved = $this->transitionObject(id: $id, action: 'park');

		$this->logger->info('Pipelinq: POS transaction parked', ['id' => $id, 'userId' => $userId]);

		return $saved;
	}                       

	   
                                              
   
                                           
                                              
   
                                                         
   
                                                                   
                                                                               
                                                                    
   
                                                            
                                                                    
    
	public function resumeTransaction(string $id, string $userId): array {
		$transaction = $this->fetchTransaction(id: $id);
		$transaction['parkedAt'] = null;
		$this->saveTransaction(id: $id, transaction: $transaction);

		$saved = $this->transitionObject(id: $id, action: 'resume');

		$this->logger->info('Pipelinq: POS transaction resumed', ['id' => $id, 'userId' => $userId]);

		return $saved;
	}                         

	   
                                                                                
   
                                                                          
                                                                          
                                                                         
                                                                    
   
                                                                  
   
                
   
                                                                                     
   
                                                                 
    
	private function assertOnAccountHasCustomer(array $transaction): void {
		$tender = (string)($transaction['tenderType'] ?? '');
		$customer = (string)($transaction['customer'] ?? '');

		if ($tender === 'onAccount' && $customer === '') {
			throw new OCSBadRequestException(
				"Klant is verplicht voor 'op rekening' transacties."
			);
		}
	}                                  

	   
                                                                               
   
                                                                              
                                                                      
                                                                          
                                                                      
                                                                           
                                                                         
   
                                           
                                                
   
                                                                   
   
                                                                              
                                                                                         
                                                                    
   
                                                                    
    
	private function transitionObject(string $id, string $action): array {
		                                                               
		                                                            
		                                                                     
		                                                                        
		                                                                    
		                                                                     
		                                                                        
		                                                                     
		                                                                        
		                                                                        
		                     
		$this->sanitizeForTransition(id: $id);

		try {
			$saved = $this->getTransitionEngine()->transition(objectId: $id, action: $action);
		} catch (\Throwable $e) {
			throw $this->mapTransitionError(e: $e);
		}

		return $this->toArray(object: $saved);
	}                        

	   
                                                                            
                               
   
                                                                            
                                                                       
                                                                            
                                                                           
                                           
   
                                           
   
                
   
                                                                    
    
	private function sanitizeForTransition(string $id): void {
		try {
			$transaction = $this->fetchTransaction(id: $id);
		} catch (\Throwable $e) {
			                                                                    
			                    
			return;
		}

		$needsSave = false;
		foreach (self::TRANSITION_STRING_DEFAULTS as $field => $default) {
			                                                                    
			                                                                     
			                                                                  
			                                                                  
			                                                                   
			                                                               
			$value = ($transaction[$field] ?? '');
			if ($value === '') {
				$transaction[$field] = $default;
				$needsSave = true;
			}
		}

		if ($needsSave === true) {
			$this->saveTransaction(id: $id, transaction: $transaction);
		}
	}                             

	   
                                                                  
   
                                                                         
                                                                       
                                                                               
                                                                              
   
                                              
   
                                                                              
   
                                                                    
    
	private function mapTransitionError(\Throwable $e): \Throwable {
		$short = (new ReflectionClass($e))->getShortName();
		$message = $e->getMessage();

		                                                
		if ($short === 'NotAuthorizedException' || stripos($message, 'permission') !== false) {
			$forbidden = 'Geen toegang tot deze transactie.';
			if ($message !== '') {
				$forbidden = $message;
			}

			return new OCSForbiddenException($forbidden);
		}

		                                                                         
		                                                                          
		                                                                  
		if ($this->isAccessDenial(message: $message) === true) {
			return new OCSForbiddenException($message);
		}

		                                                              
		if (stripos($message, 'not allowed from') !== false
			|| stripos($message, 'not declared') !== false
			|| stripos($message, 'artikel') !== false
		) {
			return new OCSBadRequestException($message);
		}

		if (stripos($message, 'not found') !== false) {
			return new OCSNotFoundException($message);
		}

		return $e;
	}                          

	   
                                                                          
   
                                             
   
                                                                     
   
                                                                    
    
	private function isAccessDenial(string $message): bool {
		foreach (['gemachtigd', 'beheerder', 'mag deze transactie', 'mag een transactie'] as $marker) {
			if (stripos($message, $marker) !== false) {
				return true;
			}
		}

		return false;
	}                      

	   
                                                                            
   
                                                                           
                                                                    
                                                                           
                                                                             
                                       
   
                                                                       
   
                                                                            
   
                                                            
    
	public function emitConfirmedEvent(array $transaction): string {
		$eventId = $this->uuid();
		$payload = $this->buildConfirmedPayload(eventId: $eventId, transaction: $transaction);

		try {
			$webhookService = $this->container->get('OCA\OpenRegister\Service\WebhookService');
			$event = new Event();
			$webhookService->dispatchEvent(_event: $event, eventName: self::EVENT_CONFIRMED, payload: $payload);
			return $eventId;
		} catch (\Throwable $e) {
			                                                                   
			                                                                  
			$this->logger->warning(
				'Pipelinq: POS confirmed CloudEvent not dispatched (no consumer or OR unavailable)',
				['exception' => $e->getMessage()]
			);
			return '';
		}         
	}                          

	   
                                                                   
   
                                              
                                                                       
   
                                                        
   
                                                            
    
	private function buildConfirmedPayload(string $eventId, array $transaction): array {
		$confirmedAt = (string)($transaction['confirmedAt'] ?? '');

		$time = $this->now();
		if ($confirmedAt !== '') {
			$time = $confirmedAt;
		}

		return [
			'specversion' => '1.0',
			'type' => self::EVENT_CONFIRMED,
			'source' => self::EVENT_SOURCE,
			'id' => $eventId,
			'time' => $time,
			'datacontenttype' => 'application/json',
			'data' => [
				'transactionId' => (string)($transaction['id'] ?? $transaction['uuid'] ?? ''),
				'reference' => (string)($transaction['reference'] ?? ''),
				'cashier' => (string)($transaction['cashier'] ?? ''),
				'customer' => (string)($transaction['customer'] ?? ''),
				'tenderType' => (string)($transaction['tenderType'] ?? 'cash'),
				'marketingConsent' => (bool)($transaction['marketingConsent'] ?? false),
				                                                            
				                                                             
				                                                             
				                                                                
				                                                 
				'staffMemberId' => (string)($transaction['staffMemberId'] ?? ''),
				'total' => (float)($transaction['total'] ?? 0),
				'totalTax' => (float)($transaction['totalTax'] ?? 0),
				'priceMode' => $this->normalizePriceMode(mode: ($transaction['priceMode'] ?? null)),
				'taxBreakdown' => ($transaction['taxBreakdown'] ?? []),
				'invoiceBreakdown' => ($transaction['invoiceBreakdown'] ?? []),
				'confirmedAt' => $confirmedAt,
			],
		];
	}                             

	   
                                                              
   
                                           
   
                                                      
   
                                                                                                
   
                                                            
    
	private function fetchTransaction(string $id): array {
		[$register, $schema] = $this->config(schemaKey: 'posTransaction_schema');

		try {
			$object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$object = null;
		}

		if ($object === null) {
			throw new OCSNotFoundException('Transactie niet gevonden.');
		}

		return $this->toArray(object: $object);
	}                        

	   
                                                    
   
                                                             
   
                                                            
   
                                                            
    
	private function fetchLines(string $transactionId): array {
		[$register, $schema] = $this->config(schemaKey: 'posTransactionLine_schema');

		try {
			                                                                     
			                                                           
			                                                                     
			                                                                     
			                                                                       
			                                
			$results = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'@self' => [
							'register' => $register,
							'schema' => $schema,
						],
						'transaction' => $transactionId,
					],
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: failed to fetch POS lines', ['exception' => $e->getMessage()]);
			return [];
		}         

		$lines = [];
		foreach (($results ?? []) as $result) {
			$lines[] = $this->toArray(object: $result);
		}

		return $lines;
	}                  

	   
                                                                        
   
                                                      
   
                                                              
   
                                                  
    
	private function fetchAllTransactions(?string $status = null): array {
		[$register, $schema] = $this->config(schemaKey: 'posTransaction_schema');

		                                                                          
		                                                 
		$filters = [
			'@self' => [
				'register' => $register,
				'schema' => $schema,
			],
		];
		if ($status !== null && $status !== '') {
			$filters['status'] = $status;
		}

		try {
			$results = $this->getObjectService()->findAll(config: ['filters' => $filters]);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: failed to fetch POS transactions', ['exception' => $e->getMessage()]);
			return [];
		}

		$transactions = [];
		foreach (($results ?? []) as $result) {
			$transactions[] = $this->toArray(object: $result);
		}

		return $transactions;
	}                            

	   
                                                          
   
                                           
                                                                  
   
                                                                   
   
                                                            
    
	private function saveTransaction(string $id, array $transaction): array {
		[$register, $schema] = $this->config(schemaKey: 'posTransaction_schema');

		                                                                       
		unset($transaction['@self']);

		                                                                         
		                                                                        
		                                                                          
		                                                                
		                                                                     
		                                                                     
		                                                                     
		                                                                   
		$transaction = array_filter(
			$transaction,
			static fn ($value): bool => $value !== null
		);

		$saved = $this->getObjectService()->saveObject(
			object: $transaction,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: $id
		);

		return $this->toArray(object: $saved);
	}                       

	   
                                                                     
   
                                                                                    
   
                                                                   
   
                                                                             
   
                                                            
    
	private function config(string $schemaKey): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');

		                                                                     
		                                                                         
		                                                                          
		                                                                        
		                                                                      
		                                                                   
		                                                                        
		                                                                          
		                                                                    
		                                                                    
		                                                                             
		if ($schema === '') {
			$slug = preg_replace('/_schema$/', '', $schemaKey);
			$schema = $this->resolveSchemaIdBySlug(slug: (string)$slug);
		}

		if ($register === '' || $schema === '') {
			throw new OCSNotFoundException('POS register of schema is niet geconfigureerd.');
		}

		return [$register, $schema];
	}              

	   
                                                                
   
                                                                              
                                                                              
                                                                        
                                                                         
                                                                            
                                                                          
                     
   
                                                                          
   
                                                                           
   
                                                            
    
	private function resolveSchemaIdBySlug(string $slug): string {
		if ($slug === '') {
			return '';
		}

		try {
			$schemaMapper = $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
			                                                        
			                                                                    
			                                                                    
			                                                                
			                                                                  
			                                                                  
			                                                            
			                                                                    
			                                                         
			$schema = $schemaMapper->find($slug, [], null, false, false);
			$id = '';
			if (is_object($schema) === true) {
				$id = (string)$schema->getId();
			}

			return $id;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: could not resolve POS schema slug to id (config fallback)',
				['slug' => $slug, 'exception' => $e->getMessage()]
			);
			return '';
		}         
	}                             

	   
                                       
   
                                      
   
                                                              
   
                                                            
    
	private function getObjectService(): object {
		                                                                         
		                                                                   
		                                                                        
		                                                                    
		if (class_exists('\OCA\OpenRegister\Service\ObjectService') === false) {
			throw new RuntimeException('The OpenRegister app is not installed');
		}

		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			throw new RuntimeException('OpenRegister service is not available.');
		}
	}                        

	   
                                                    
   
                                         
   
                                                              
   
                                                                    
    
	private function getTransitionEngine(): object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\Lifecycle\TransitionEngine');
		} catch (\Throwable $e) {
			throw new RuntimeException('OpenRegister TransitionEngine is not available.');
		}
	}                           

	   
                                                                
   
                                       
   
                                                        
    
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialized = $object->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$data = $object->getObject();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return (array)$object;
	}               

	   
                                       
   
                                         
    
	private function now(): string {
		return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
	}           

	   
                       
   
                            
    
	private function uuid(): string {
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3F) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}            
}           
