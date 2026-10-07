<?php

   
                             
  
                                                                             
                                                                              
                                                                           
                                                                              
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                        
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use OCA\OpenRegister\Service\WebhookService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;

   
                                              
  
                                                                  
                                                                             
                                                                         
                                                                               
                                                            
  
                                                                            
                                                                                
                                                                         
                                                                               
                                                                                 
  
                                                                              
                                                                          
                                                                            
                                   
                                                                             
                                                                             
                                                                          
                                                                           
                                                                                  
                                                                              
                                                                          
                                                                        
                                                               
  
                                                        
                                                                   
   
class PosRefundService {
	   
                                                                       
   
               
    
	public const EVENT_REFUND_COMPLETED = 'pipelinq.TransactionRefund.completed';

	   
                                                                   
   
               
    
	public const EVENT_STOCK_MOVEMENT = 'shillinq.StockMovement';

	   
                                                             
   
               
    
	private const EVENT_SOURCE = '/apps/pipelinq/pos';

	   
                
   
                                                
                                              
                                                                  
                                                                                         
                                                                                 
    
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private readonly WebhookService $webhookService,
		private readonly ObjectServiceInterface $objectService,
		private readonly TransitionEngine $transitionEngine,
	) {
	}                   

	   
                                         
   
                                           
   
                                             
    
	private function money(float $value): float {
		return round($value, 2);
	}             

	   
                                                                       
   
                                                                             
                                                                             
                                                                           
                                                                        
                                                                             
   
                                                                              
                                                          
   
                                                                                         
   
                                                         
    
	public function recalculateLine(array $originalLine, float $returnedQty): array {
		$originalQty = (float)($originalLine['quantity'] ?? 0);
		$returned = max(0.0, $returnedQty);

		$ratio = 0.0;
		if ($originalQty > 0) {
			$ratio = min(1.0, ($returned / $originalQty));
		}

		$origTax = (float)($originalLine['taxAmount'] ?? 0);
		$origTotal = (float)($originalLine['lineTotal'] ?? 0);

		return [
			'ratio' => $ratio,
			'taxAmount' => $this->money(value: ($origTax * $ratio)),
			'lineTotal' => $this->money(value: ($origTotal * $ratio)),
		];
	}                       

	   
                                                           
   
                                                                            
                                                                          
                                                                               
                                                                                
                                                                         
                                               
   
                                            
   
                                                    
   
                                                              
   
                                                         
    
	public function recalculateTotals(string $refundId): array {
		$refund = $this->fetchRefund(id: $refundId);
		$lines = $this->fetchRefundLines(refundId: $refundId);

		$refundAmount = 0.0;
		$totalTax = 0.0;

		foreach ($lines as $line) {
			$originalLine = $this->fetchOriginalLine(id: (string)($line['originalLine'] ?? ''));
			$returnedQty = (float)($line['returnedQuantity'] ?? 0);
			$computed = $this->recalculateLine(originalLine: $originalLine, returnedQty: $returnedQty);

			$line['taxAmount'] = $computed['taxAmount'];
			$line['lineTotal'] = $computed['lineTotal'];
			$this->saveRefundLine(id: (string)($line['id'] ?? $line['uuid'] ?? ''), line: $line);

			$refundAmount += ($computed['lineTotal'] - $computed['taxAmount']);
			$totalTax += $computed['taxAmount'];
		}

		$refund['refundAmount'] = $this->money(value: $refundAmount);
		$refund['totalTax'] = $this->money(value: $totalTax);

		return $this->saveRefund(id: $refundId, refund: $refund);
	}                         

	   
                                            
   
                                                                              
                                                                   
                                                                        
                                                                               
                                                                 
                      
   
                                      
                                              
   
                                                      
   
                                                                                          
                                                                       
                                                                                                       
   
                                                         
    
	public function confirmRefund(string $id, string $userId): array {
		$refund = $this->fetchRefund(id: $id);

		$lines = $this->fetchRefundLines(refundId: $id);
		if (count($lines) === 0) {
			throw new OCSBadRequestException('Selecteer ten minste één artikel om terug te geven.');
		}

		                                                                      
		$this->fetchTransaction(id: (string)($refund['originalTransaction'] ?? ''));

		                                                                         
		                                                              
		                                                                         
		                                        
		$this->recalculateTotals(refundId: $id);

		                                                                     
		$refund = $this->fetchRefund(id: $id);
		$refund['confirmedAt'] = $this->now();
		$this->saveRefund(id: $id, refund: $refund);

		                                                                   
		                                                                     
		                                                                          
		$saved = $this->transitionObject(id: $id, action: 'complete');

		$eventId = $this->emitRefundEvent(refund: $saved);
		if ($eventId !== '') {
			$saved['cloudEventId'] = $eventId;
			$saved = $this->saveRefund(id: $id, refund: $saved);
		}

		$this->emitStockMovementEvents(refundId: $id);

		$this->logger->info('Pipelinq: POS refund completed', ['id' => $id, 'userId' => $userId]);

		return $saved;
	}                     

	   
                                           
   
                                                                    
                                                                              
                                                                              
                                                                             
                         
   
                                      
                                                          
                                              
   
                                                     
   
                                                              
                                                                       
                                                                                   
   
                                                         
                                                                    
    
	public function rejectRefund(string $id, string $reason, string $userId): array {
		if (trim($reason) === '') {
			throw new OCSBadRequestException('Vul een reden in voor de afwijzing.');
		}

		$refund = $this->fetchRefund(id: $id);
		$refund['rejectedAt'] = $this->now();
		$refund['rejectionReason'] = trim($reason);
		$this->saveRefund(id: $id, refund: $refund);

		$saved = $this->transitionObject(id: $id, action: 'reject');

		$this->logger->info('Pipelinq: POS refund rejected', ['id' => $id, 'userId' => $userId]);

		return $saved;
	}                    

	   
                                                                               
   
                                                                              
                                                                            
                                                                
                                                                            
                                              
   
                                      
                                                                      
   
                                                              
   
                                                                              
                                                                                 
                                                                    
   
                                                                    
    
	private function transitionObject(string $id, string $action): array {
		try {
			$saved = $this->getTransitionEngine()->transition(objectId: $id, action: $action);
		} catch (\Throwable $e) {
			throw $this->mapTransitionError(e: $e);
		}

		return $this->toArray(object: $saved);
	}                        

	   
                                                                  
   
                                                                           
                                                                    
                                     
   
                                              
   
                                                                              
   
                                                                               
                                                                               
                                                                        
   
                                                                    
    
	private function mapTransitionError(\Throwable $e): \Throwable {
		$short = (new ReflectionClass($e))->getShortName();
		$message = $e->getMessage();

		                                
		if ($short === 'NotAuthorizedException' || stripos($message, 'permission') !== false) {
			$forbidden = 'Geen toegang tot deze retour.';
			if ($message !== '') {
				$forbidden = $message;
			}

			return new OCSForbiddenException($forbidden);
		}

		                                                                 
		if (stripos($message, 'beheerder mag een retour') !== false) {
			return new OCSForbiddenException($message);
		}

		                                                               
		if (stripos($message, 'overschrijdt') !== false
			|| stripos($message, 'not allowed from') !== false
			|| stripos($message, 'not declared') !== false
			|| stripos($message, 'ontbreekt') !== false
			|| stripos($message, 'Originele kassabon niet gevonden') !== false
		) {
			return new OCSBadRequestException($message);
		}

		if (stripos($message, 'not found') !== false) {
			return new OCSNotFoundException($message);
		}

		return $e;
	}                          

	   
                                                                               
   
                                                                             
                                                                            
                                                                              
                                                                                
                                                    
   
                                                             
   
                                                                            
   
                                                         
    
	public function emitRefundEvent(array $refund): string {
		$eventId = $this->uuid();
		$payload = $this->buildRefundPayload(eventId: $eventId, refund: $refund);

		try {
			$event = new Event();
			$this->webhookService->dispatchEvent(_event: $event, eventName: self::EVENT_REFUND_COMPLETED, payload: $payload);
			return $eventId;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: POS refund CloudEvent not dispatched (no consumer or OR unavailable)',
				['exception' => $e->getMessage()]
			);
			return '';
		}         
	}                       

	   
                                                                         
   
                                                                         
                                                                          
                                                                            
                                      
   
                                            
   
                                                            
   
                                                         
    
	public function emitStockMovementEvents(string $refundId): int {
		$refund = $this->fetchRefund(id: $refundId);
		$lines = $this->fetchRefundLines(refundId: $refundId);
		$emitted = 0;
		$original = (string)($refund['originalTransaction'] ?? '');

		foreach ($lines as $line) {
			if ((bool)($line['restock'] ?? true) === false) {
				continue;
			}

			$originalLine = $this->fetchOriginalLineSafe(id: (string)($line['originalLine'] ?? ''));
			$productId = (string)($originalLine['product'] ?? $originalLine['sku'] ?? '');
			$quantity = max(0.0, (float)($line['returnedQuantity'] ?? 0));

			$payload = $this->buildStockMovementPayload(
				refundId: $refundId,
				productId: $productId,
				quantity: $quantity,
				originalTransaction: $original
			);

			try {
				$event = new Event();
				$this->webhookService->dispatchEvent(
					_event: $event,
					eventName: self::EVENT_STOCK_MOVEMENT,
					payload: $payload
				);
				$emitted++;
			} catch (\Throwable $e) {
				$this->logger->warning(
					'Pipelinq: POS stock movement CloudEvent not dispatched (no consumer or OR unavailable)',
					['exception' => $e->getMessage(), 'refundId' => $refundId]
				);
			}         
		}             

		return $emitted;
	}                               

	   
                                                              
   
                                                                           
                                        
   
                                              
                                                             
   
                                                        
   
                                                         
    
	private function buildRefundPayload(string $eventId, array $refund): array {
		$confirmedAt = (string)($refund['confirmedAt'] ?? '');
		$time = $this->now();
		if ($confirmedAt !== '') {
			$time = $confirmedAt;
		}

		$refundAmount = (float)($refund['refundAmount'] ?? 0);
		$totalTax = (float)($refund['totalTax'] ?? 0);

		return [
			'specversion' => '1.0',
			'type' => self::EVENT_REFUND_COMPLETED,
			'source' => self::EVENT_SOURCE,
			'id' => $eventId,
			'time' => $time,
			'datacontenttype' => 'application/json',
			'data' => [
				'refundId' => (string)($refund['id'] ?? $refund['uuid'] ?? ''),
				'reference' => (string)($refund['reference'] ?? ''),
				'originalTransactionId' => (string)($refund['originalTransaction'] ?? ''),
				'cashier' => (string)($refund['cashier'] ?? ''),
				'refundReason' => (string)($refund['refundReason'] ?? ''),
				'refundAmount' => $refundAmount,
				'totalTax' => $totalTax,
				                                                                
				'reversalAmount' => $this->money(value: (-1 * $refundAmount)),
				'reversalTax' => $this->money(value: (-1 * $totalTax)),
				'paymentMethod' => (string)($refund['paymentMethod'] ?? ''),
				'paymentReference' => (string)($refund['paymentReference'] ?? ''),
				'confirmedAt' => $confirmedAt,
			],
		];
	}                          

	   
                                                                         
   
                                            
                                                                         
                                                          
                                                                                    
   
                                                        
   
                                                         
    
	private function buildStockMovementPayload(
		string $refundId,
		string $productId,
		float $quantity,
		string $originalTransaction,
	): array {
		return [
			'specversion' => '1.0',
			'type' => self::EVENT_STOCK_MOVEMENT,
			'source' => self::EVENT_SOURCE,
			'id' => $this->uuid(),
			'time' => $this->now(),
			'datacontenttype' => 'application/json',
			'data' => [
				'transactionType' => 'refund_return',
				'refundId' => $refundId,
				'productId' => $productId,
				'quantity' => $quantity,
				'unit' => 'piece',
				'notes' => 'Return from transaction ' . $originalTransaction,
			],
		];
	}                                 

	   
                                                         
   
                                      
   
                                                 
   
                                                                                           
   
                                                         
    
	private function fetchRefund(string $id): array {
		[$register, $schema] = $this->config(schemaKey: 'posRefund_schema');

		try {
			$object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$object = null;
		}

		if ($object === null) {
			throw new OCSNotFoundException('Retour niet gevonden.');
		}

		return $this->toArray(object: $object);
	}                   

	   
                                                 
   
                                                   
   
                                                              
   
                                                         
    
	private function fetchRefundLines(string $refundId): array {
		[$register, $schema] = $this->config(schemaKey: 'posRefundLine_schema');

		try {
			$results = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'refund' => $refundId,
					],
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: failed to fetch refund lines', ['exception' => $e->getMessage()]);
			return [];
		}

		$lines = [];
		foreach (($results ?? []) as $result) {
			$lines[] = $this->toArray(object: $result);
		}

		return $lines;
	}                        

	   
                                                                      
   
                                             
   
                                                   
   
                                                                                        
   
                                                         
    
	private function fetchOriginalLine(string $id): array {
		[$register, $schema] = $this->config(schemaKey: 'posTransactionLine_schema');

		try {
			$object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$object = null;
		}

		if ($object === null) {
			throw new OCSNotFoundException('Originele regel niet gevonden.');
		}

		return $this->toArray(object: $object);
	}                         

	   
                                                                             
                                                                     
   
                                             
   
                                                                      
   
                                                         
    
	private function fetchOriginalLineSafe(string $id): array {
		try {
			return $this->fetchOriginalLine(id: $id);
		} catch (\Throwable $e) {
			return [];
		}
	}                             

	   
                                                                                 
   
                                                                          
                                                                           
                                                                             
                                                                           
                               
   
                                           
   
                
   
                                                                 
   
                                                         
    
	private function fetchTransaction(string $id): void {
		[$register, $schema] = $this->config(schemaKey: 'posTransaction_schema');

		try {
			$object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$object = null;
		}

		if ($object === null) {
			throw new OCSNotFoundException('Originele kassabon niet gevonden. Kan refund niet verwerken.');
		}
	}                        

	   
                                                     
   
                                      
                                                        
   
                                                              
   
                                                         
    
	private function saveRefund(string $id, array $refund): array {
		[$register, $schema] = $this->config(schemaKey: 'posRefund_schema');

		unset($refund['@self']);

		$saved = $this->getObjectService()->saveObject(
			object: $refund,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: $id
		);

		return $this->toArray(object: $saved);
	}                  

	   
                                                   
   
                                           
                                                           
   
                
   
                                                         
    
	private function saveRefundLine(string $id, array $line): void {
		if ($id === '') {
			return;
		}

		[$register, $schema] = $this->config(schemaKey: 'posRefundLine_schema');

		unset($line['@self']);

		try {
			$this->getObjectService()->saveObject(
				object: $line,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: $id
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: failed to persist refund line', ['exception' => $e->getMessage()]);
		}
	}                      

	   
                                                                     
   
                                                                               
   
                                                                   
   
                                                                             
   
                                                         
    
	private function config(string $schemaKey): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');

		if ($register === '' || $schema === '') {
			throw new OCSNotFoundException('POS register of schema is niet geconfigureerd.');
		}

		return [$register, $schema];
	}              

	   
                                       
   
                                      
   
                                                         
    
	private function getObjectService(): object {
		                                                                 
		                                                              
		return $this->objectService;
	}                        

	   
                                                    
   
                                         
   
                                                                    
    
	private function getTransitionEngine(): object {
		                                                                 
		                                                              
		return $this->transitionEngine;
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
