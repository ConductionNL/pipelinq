<?php

   
                             
  
                                                                         
                                                                              
                                                                       
                                                                        
                                                                         
                                             
  
                                                                           
                                                                         
                                                                            
                                                                          
                                                                      
                  
  
                                                                          
                                                                     
                                                                         
                                                                         
                                              
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                               
                                                               
                                                               
                                                               
                                                               
                                                               
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Event\TenderPostedEvent;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

   
                                                       
  
                                                                                     
                                                                                     
                                                                                
                                                                           
                                                                               
                                                                            
                                                         
                                                                             
                                                                   
                                                                               
                                                                                     
                                                                               
                                                            
  
                                                               
   
class PosTenderService {
	   
                                                                          
                                                                          
                                                                     
   
              
    
	public const MONEY_EPSILON = 0.005;

	   
                                                     
   
               
    
	public const EVENT_TENDER_POSTED = 'nl.pipelinq.pos.tender.posted';

	   
                                                   
   
               
    
	public const EVENT_SOURCE = '/apps/pipelinq/pos/tender';

	   
                                                                            
   
            
    
	public const MAX_GL_POST_ATTEMPTS = 10;

	   
                
   
                                                                                 
                                                                                   
                                                                                    
                                              
    
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private IEventDispatcher $eventDispatcher,
		private LoggerInterface $logger,
	) {
	}                   

	                                                                        
	                                      
	                                                                        

	   
                                     
   
                                                               
   
                                                                                  
   
                                                                
   
                                                                                 
                                                                               
                                   
    
	public function listTenderTypes(bool $activeOnly = false): array {
		[$register, $schema] = $this->config(schemaKey: 'posTenderType_schema');

		try {
			$results = $this->getObjectService()->findAll(
				config: [
					'filters' => [],
					'register' => $register,
					'schema' => $schema,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq POS tender: failed to list tender types',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		$types = [];
		foreach ($this->resultRows(result: $results) as $row) {
			if ($activeOnly === true && ($row['isActive'] ?? true) !== true) {
				continue;
			}

			$types[] = $row;
		}

		usort(
			$types,
			static function (array $a, array $b): int {
				return ((int)($a['sortOrder'] ?? 0)) <=> ((int)($b['sortOrder'] ?? 0));
			}
		);

		return $types;
	}                       

	   
                                      
   
                                           
   
                                                 
   
                                                                     
   
                                                                
    
	public function getTenderTypeById(string $id): array {
		if ($id === '') {
			throw new TenderTypeNotFoundException('Tender type not found');
		}

		[$register, $schema] = $this->config(schemaKey: 'posTenderType_schema');

		try {
			$object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$object = null;
		}

		if ($object === null) {
			throw new TenderTypeNotFoundException('Tender type not found');
		}

		return $this->toArray(object: $object);
	}                         

	   
                                                                                     
   
                                             
   
                                                 
   
                                                                       
   
                                                                
    
	public function getTenderTypeByCode(string $code): array {
		if ($code === '') {
			throw new TenderTypeNotFoundException('Tender type code is required');
		}

		foreach ($this->listTenderTypes(activeOnly: false) as $type) {
			if ((string)($type['code'] ?? '') === $code) {
				return $type;
			}
		}

		throw new TenderTypeNotFoundException(sprintf('Tender type "%s" not found', $code));
	}                           

	   
                                          
   
                                                              
   
                                                           
   
                                                                                              
   
                                                                
    
	public function createTenderType(array $data): array {
		$payload = $this->validateTenderTypePayload(data: $data, allowMissingCode: false);

		                                                                      
		try {
			$existing = $this->getTenderTypeByCode(code: $payload['code']);
			if ($existing !== []) {
				throw new OCSBadRequestException(sprintf('Tender type code "%s" already exists', $payload['code']));
			}
		} catch (TenderTypeNotFoundException $e) {
			                           
		}

		return $this->saveTenderType(id: '', payload: $payload);
	}                        

	   
                                                                        
                               
   
                                           
                                                        
   
                                                         
   
                                                                     
                                                                    
   
                                                                
    
	public function updateTenderType(string $id, array $data): array {
		$current = $this->getTenderTypeById(id: $id);
		$merged = array_merge($current, $data);
		$merged['code'] = (string)($current['code'] ?? '');
		$payload = $this->validateTenderTypePayload(data: $merged, allowMissingCode: true);

		return $this->saveTenderType(id: $id, payload: $payload);
	}                        

	   
                                                                                
   
                                           
   
                
   
                                                                     
                                                                           
   
                                                                
    
	public function deleteTenderType(string $id): void {
		                                                           
		$this->getTenderTypeById(id: $id);

		$count = $this->countTendersForType(tenderTypeId: $id);
		if ($count > 0) {
			throw new OCSBadRequestException(
				sprintf('Cannot delete tender type with active references (%d active tenders)', $count)
			);
		}

		[$register, $schema] = $this->config(schemaKey: 'posTenderType_schema');

		try {
			                                                                       
			$this->getObjectService()->deleteObject(uuid: $id, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq POS tender: failed to delete tender type',
				['id' => $id, 'exception' => $e->getMessage()]
			);
			throw new OCSBadRequestException('Failed to delete tender type');
		}
	}                        

	                                                                        
	                                                            
	                                                                        

	   
                                                                    
   
                                                                   
                                                                           
                                                                          
                                                                          
                                                                           
                                                                            
   
                                                                            
                                                                             
                                                                             
                                                                           
                                                                         
                                                                
   
                                                         
   
                                                         
   
                                                                
    
	public function getTendersForTransaction(string $transactionId): array {
		if ($transactionId === '') {
			return [];
		}

		[$register, $schema] = $this->config(schemaKey: 'posTender_schema');

		try {
			$results = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'transaction' => $transactionId,
						'register' => $register,
						'schema' => $schema,
					],
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq POS tender: failed to list tenders',
				['transactionId' => $transactionId, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$tenders = [];
		foreach ($this->resultRows(result: $results) as $row) {
			                                                               
			if ((string)($row['transaction'] ?? '') !== $transactionId) {
				continue;
			}

			$tenders[] = $row;
		}

		usort(
			$tenders,
			static function (array $a, array $b): int {
				return ((int)($a['sortOrder'] ?? 0)) <=> ((int)($b['sortOrder'] ?? 0));
			}
		);

		return $tenders;
	}                                

	   
                                  
   
                                                                       
                                                                       
                                                                          
                                                                    
   
                                                         
                                                                                             
   
                                                    
   
                                                                   
                                                                          
                                                                                  
   
                                                                
                                                                
    
	public function addTender(string $transactionId, array $payload): array {
		$transaction = $this->fetchTransaction(id: $transactionId);
		$status = (string)($transaction['status'] ?? '');
		if ($status === 'settled') {
			throw new InvalidTenderException(
				'Cannot add tenders to a settled transaction',
				statusCode: 409
			);
		}

		$tenderTypeId = (string)($payload['tenderType'] ?? '');
		if ($tenderTypeId === '') {
			throw new InvalidTenderException('Tender type is required', statusCode: 400);
		}

		$type = $this->getTenderTypeById(id: $tenderTypeId);

		if (($type['isActive'] ?? true) !== true) {
			throw new InvalidTenderException(
				sprintf('Tender type "%s" is not active', (string)($type['code'] ?? '')),
				statusCode: 400
			);
		}

		$amount = (float)($payload['amount'] ?? 0);
		if ($amount < 0.01) {
			throw new InvalidTenderException(
				'Tender amount must be greater than €0.01',
				statusCode: 400
			);
		}

		$requiresReference = (bool)($type['requiresReference'] ?? false);
		$reference = trim((string)($payload['reference'] ?? ''));
		if ($requiresReference === true && $reference === '') {
			throw new InvalidTenderException(
				'Reference is required for this tender type',
				statusCode: 400
			);
		}

		                                                                       
		                                                                   
		                                                          
		$total = (float)($transaction['total'] ?? 0);
		$change = 0.0;
		if (($type['allowsChange'] ?? false) === true) {
			$change = $this->calculateChange(cashTenderedAmount: $amount, transactionTotal: $total);
		}

		$tender = [
			'transaction' => $transactionId,
			'tenderType' => $tenderTypeId,
			'amount' => round($amount, 2),
			'reference' => $reference,
			'glAccount' => (string)($type['glAccount'] ?? ''),
			'change' => round($change, 2),
			'notes' => (string)($payload['notes'] ?? ''),
			'sortOrder' => (int)($payload['sortOrder'] ?? (count($this->getTendersForTransaction(transactionId: $transactionId)) + 1)),
			'glPosted' => false,
			'glPostAttempts' => 0,
		];

		return $this->saveTender(id: '', payload: $tender);
	}                 

	   
                                       
   
                                                                               
                                               
   
                
   
                                                                             
                                                                           
   
                                                                
    
	public function removeTender(string $transactionId, string $tenderId): void {
		$transaction = $this->fetchTransaction(id: $transactionId);
		$status = (string)($transaction['status'] ?? '');
		if ($status === 'settled') {
			throw new InvalidTenderException(
				'Cannot remove tenders from a settled transaction',
				statusCode: 409
			);
		}

		$tender = $this->fetchTender(id: $tenderId);
		if ((string)($tender['transaction'] ?? '') !== $transactionId) {
			throw new OCSNotFoundException('Tender not found on this transaction');
		}

		[$register, $schema] = $this->config(schemaKey: 'posTender_schema');

		try {
			                                                                       
			$this->getObjectService()->deleteObject(uuid: $tenderId, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq POS tender: failed to delete tender',
				['tenderId' => $tenderId, 'exception' => $e->getMessage()]
			);
			throw new InvalidTenderException('Failed to remove tender', statusCode: 400);
		}
	}                    

	                                                                        
	                                                               
	                                                                        

	   
                                                                         
                                                                    
   
                                                         
   
                                                                                             
   
                                                                   
   
                                                                
    
	public function validateTenderSum(string $transactionId): array {
		$transaction = $this->fetchTransaction(id: $transactionId);
		$total = round((float)($transaction['total'] ?? 0), 2);

		$tenders = $this->getTendersForTransaction(transactionId: $transactionId);
		$tenderSum = 0.0;
		foreach ($tenders as $tender) {
			$tenderSum += (float)($tender['amount'] ?? 0);
		}

		$tenderSum = round($tenderSum, 2);
		$variance = round($total - $tenderSum, 2);

		return [
			'tenderSum' => $tenderSum,
			'transactionTotal' => $total,
			'variance' => $variance,
			'balanced' => (abs($variance) <= self::MONEY_EPSILON),
		];
	}                         

	   
                                                 
   
                                                                           
                                                                        
                                                                        
                                          
   
                                                                   
                                                                
   
                                                
   
                                                                
    
	public function calculateChange(float $cashTenderedAmount, float $transactionTotal): float {
		if ($cashTenderedAmount <= $transactionTotal) {
			return 0.0;
		}

		return round($cashTenderedAmount - $transactionTotal, 2);
	}                       

	   
                                                                          
                                         
   
                    
                                 
                                                              
                                                                         
                                                                      
                                                                  
   
                                                      
   
                
   
                                                                   
                                                                                
   
                                                                
    
	public function assertBalancedForSettle(string $transactionId): void {
		$validation = $this->validateTenderSum(transactionId: $transactionId);

		if ($validation['balanced'] === true) {
			return;
		}

		$tenderSum = (float)$validation['tenderSum'];
		$total = (float)$validation['transactionTotal'];
		$variance = (float)$validation['variance'];

		                                                              
		if ($variance > 0) {
			throw new InvalidTenderException(
				sprintf(
					'Tender sum (€%.2f) does not equal transaction total (€%.2f). Underpayment: €%.2f',
					$tenderSum,
					$total,
					abs($variance)
				),
				statusCode: 409
			);
		}

		                                                                  
		                                                                      
		                                                   
		$changeSum = 0.0;
		foreach ($this->getTendersForTransaction(transactionId: $transactionId) as $tender) {
			$changeSum += (float)($tender['change'] ?? 0);
		}

		$changeSum = round($changeSum, 2);
		$excess = round(abs($variance), 2);

		if ($changeSum + self::MONEY_EPSILON >= $excess) {
			return;
		}

		throw new InvalidTenderException(
			sprintf(
				'Tender sum (€%.2f) exceeds transaction total. Overpayment: €%.2f without change tender',
				$tenderSum,
				$excess
			),
			statusCode: 409
		);
	}                               

	                                                                        
	                                     
	                                                                        

	   
                                                                       
   
                                                                           
                                                                        
                                                                          
                                                                        
                                       
   
                                                              
   
                                                     
   
                                                                
    
	public function emitTendersPosted(string $transactionId): array {
		$transaction = $this->fetchTransactionOrNull(id: $transactionId);
		if ($transaction === null) {
			return [];
		}

		$reference = (string)($transaction['reference'] ?? '');
		$tenders = $this->getTendersForTransaction(transactionId: $transactionId);
		$emitted = [];

		foreach ($tenders as $tender) {
			$tenderId = $this->extractId(entity: $tender);
			if ($tenderId === '') {
				continue;
			}

			$eventId = $this->emitSingleTenderPosted(
				transactionUuid: $transactionId,
				transactionReference: $reference,
				tender: $tender
			);
			if ($eventId !== '') {
				$emitted[] = $eventId;
			}
		}

		return $emitted;
	}                         

	   
                                                                           
                                                                        
        
   
                                                        
                                                                                 
                                                          
   
                                                                                
   
                                                                
    
	public function emitSingleTenderPosted(string $transactionUuid, string $transactionReference, array $tender): string {
		$tenderId = $this->extractId(entity: $tender);
		if ($tenderId === '') {
			return '';
		}

		$attempts = (int)($tender['glPostAttempts'] ?? 0);
		if ($attempts >= self::MAX_GL_POST_ATTEMPTS) {
			$this->logger->warning(
				'Pipelinq POS tender: max GL-post attempts reached; soft-failing',
				['tenderId' => $tenderId, 'attempts' => $attempts]
			);
			return '';
		}

		                                                        
		$tenderTypeCode = '';
		try {
			$type = $this->getTenderTypeById(id: (string)($tender['tenderType'] ?? ''));
			$tenderTypeCode = (string)($type['code'] ?? '');
		} catch (Throwable $e) {
			                                                             
		}

		$eventId = $this->uuid();
		$event = new TenderPostedEvent(
			eventId: $eventId,
			tenderUuid: $tenderId,
			transactionUuid: $transactionUuid,
			transactionReference: $transactionReference,
			tenderTypeCode: $tenderTypeCode,
			amount: (float)($tender['amount'] ?? 0),
			glAccount: (string)($tender['glAccount'] ?? ''),
			emittedAt: $this->nowIso(),
		);

		try {
			$this->eventDispatcher->dispatchTyped(event: $event);
		} catch (Throwable $e) {
			$this->logger->info(
				'Pipelinq POS tender: typed dispatch failed; falling back to webhook',
				['tenderId' => $tenderId, 'exception' => $e->getMessage()]
			);
		}

		                                                                          
		                                                        
		$this->dispatchWebhook(event: $event);

		                                                                      
		$patched = $tender;
		unset($patched['@self']);
		$patched['cloudEventId'] = $eventId;
		$patched['glPostAttempts'] = ($attempts + 1);

		try {
			$this->saveTender(id: $tenderId, payload: $patched);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq POS tender: failed to persist event-id on tender',
				['tenderId' => $tenderId, 'exception' => $e->getMessage()]
			);
		}

		return $eventId;
	}                              

	  
                                          
   
                                                                
                                                                     
                                                                           
                                                                     
                                                                         
                                                                   
                                        
   
                                                                           
                                                                          
                                                                  
    

	   
                                                                       
                                                                      
                                    
   
                                                                  
   
                                                                
    
	public function listUnpostedTenders(): array {
		[$register, $schema] = $this->config(schemaKey: 'posTender_schema');

		try {
			$results = $this->getObjectService()->findAll(
				config: [
					'filters' => [],
					'register' => $register,
					'schema' => $schema,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq POS tender: failed to list unposted tenders',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		$unposted = [];
		foreach ($this->resultRows(result: $results) as $row) {
			$posted = (bool)($row['glPosted'] ?? false);
			$attempts = (int)($row['glPostAttempts'] ?? 0);
			if ($posted === false && $attempts < self::MAX_GL_POST_ATTEMPTS && $attempts > 0) {
				$unposted[] = $row;
			}
		}

		return $unposted;
	}                           

	                                                                        
	                                  
	                                                                        

	   
                                               
   
                                                        
                                                                              
   
                                                        
   
                                  
    
	private function validateTenderTypePayload(array $data, bool $allowMissingCode): array {
		$name = trim((string)($data['name'] ?? ''));
		$code = trim((string)($data['code'] ?? ''));
		$glAccount = trim((string)($data['glAccount'] ?? ''));

		if ($name === '') {
			throw new OCSBadRequestException('Name is required');
		}

		if ($allowMissingCode === false && $code === '') {
			throw new OCSBadRequestException('Code is required');
		}

		if ($glAccount === '') {
			throw new OCSBadRequestException('GL account is required');
		}

		$resolvedCode = $code;
		if ($code === '') {
			$resolvedCode = (string)($data['code'] ?? '');
		}

		return [
			'name' => $name,
			'code' => $resolvedCode,
			'description' => (string)($data['description'] ?? ''),
			'glAccount' => $glAccount,
			'requiresReference' => (bool)($data['requiresReference'] ?? false),
			'requiresPin' => (bool)($data['requiresPin'] ?? false),
			'allowsChange' => (bool)($data['allowsChange'] ?? false),
			'isActive' => (bool)($data['isActive'] ?? true),
			'sortOrder' => (int)($data['sortOrder'] ?? 0),
		];
	}                                 

	   
                                                      
   
                                                     
   
                          
    
	private function countTendersForType(string $tenderTypeId): int {
		if ($tenderTypeId === '') {
			return 0;
		}

		[$register, $schema] = $this->config(schemaKey: 'posTender_schema');

		try {
			$results = $this->getObjectService()->findAll(
				config: [
					'filters' => ['tenderType' => $tenderTypeId],
					'register' => $register,
					'schema' => $schema,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq POS tender: failed to count tenders for type',
				['tenderTypeId' => $tenderTypeId, 'exception' => $e->getMessage()]
			);
			return 0;
		}

		$count = 0;
		foreach ($this->resultRows(result: $results) as $row) {
			if ((string)($row['tenderType'] ?? '') === $tenderTypeId) {
				$count++;
			}
		}

		return $count;
	}                           

	                                                                        
	                                          
	                                                                        

	   
                                                               
   
                                                  
                                                                
   
                                                       
    
	private function saveTenderType(string $id, array $payload): array {
		[$register, $schema] = $this->config(schemaKey: 'posTenderType_schema');

		try {
			$saved = $this->getObjectService()->saveObject(
				object: $payload,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: $id
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq POS tender: failed to save tender type',
				['id' => $id, 'exception' => $e->getMessage()]
			);
			throw new OCSBadRequestException('Failed to save tender type');
		}

		return $this->toArray(object: $saved);
	}                      

	   
                                                          
   
                                                  
                                                            
   
                                                  
    
	private function saveTender(string $id, array $payload): array {
		[$register, $schema] = $this->config(schemaKey: 'posTender_schema');

		try {
			$saved = $this->getObjectService()->saveObject(
				object: $payload,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: $id
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq POS tender: failed to save tender',
				['id' => $id, 'exception' => $e->getMessage()]
			);
			throw new InvalidTenderException('Failed to save tender', statusCode: 400);
		}

		return $this->toArray(object: $saved);
	}                  

	   
                             
   
                                      
   
                                            
   
                                
    
	private function fetchTender(string $id): array {
		if ($id === '') {
			throw new OCSNotFoundException('Tender not found');
		}

		[$register, $schema] = $this->config(schemaKey: 'posTender_schema');

		try {
			$object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$object = null;
		}

		if ($object === null) {
			throw new OCSNotFoundException('Tender not found');
		}

		return $this->toArray(object: $object);
	}                   

	   
                                     
   
                                           
   
                                                 
   
                                
    
	private function fetchTransaction(string $id): array {
		$transaction = $this->fetchTransactionOrNull(id: $id);
		if ($transaction === null) {
			throw new OCSNotFoundException('Transaction not found');
		}

		return $transaction;
	}                        

	   
                                                                     
   
                                           
   
                                     
    
	private function fetchTransactionOrNull(string $id): ?array {
		if ($id === '') {
			return null;
		}

		[$register, $schema] = $this->config(schemaKey: 'posTransaction_schema');

		try {
			$object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
		} catch (Throwable $e) {
			return null;
		}

		if ($object === null) {
			return null;
		}

		return $this->toArray(object: $object);
	}                              

	   
                                                            
   
                                                                                 
   
                                                                    
   
                                                                             
    
	private function config(string $schemaKey): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');

		if ($register === '' || $schema === '') {
			throw new OCSNotFoundException('Tender register of schema is not configured');
		}

		return [$register, $schema];
	}              

	   
                                 
   
                                        
   
                                                      
    
	private function getObjectService(): object {
		                                                                         
		                                                                   
		                                                                        
		                                                                    
		if (class_exists('\OCA\OpenRegister\Service\ObjectService') === false) {
			throw new RuntimeException('The OpenRegister app is not installed');
		}

		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			throw new RuntimeException('OpenRegister is not available');
		}
	}                        

	   
                                                              
   
                                                          
   
                
    
	private function dispatchWebhook(TenderPostedEvent $event): void {
		try {
			$webhookService = $this->container->get('OCA\\OpenRegister\\Service\\WebhookService');
		} catch (Throwable $e) {
			$this->logger->debug(
				'Pipelinq POS tender: WebhookService unavailable; skipping external dispatch',
				['tenderId' => $event->getTenderUuid()]
			);
			return;
		}

		try {
			$webhookService->dispatchEvent(
				_event: new Event(),
				eventName: self::EVENT_TENDER_POSTED,
				payload: $event->toCloudEvent()
			);
		} catch (Throwable $e) {
			$this->logger->info(
				'Pipelinq POS tender: webhook dispatch failed (no consumer or OR offline)',
				['tenderId' => $event->getTenderUuid()]
			);
		}
	}                       

	   
                                                                  
   
                                       
   
                                                      
    
	private function resultRows(mixed $result): array {
		if (is_array($result) === false) {
			return [];
		}

		$rows = $result;
		if (isset($result['results']) === true && is_array($result['results']) === true) {
			$rows = $result['results'];
		}

		$out = [];
		foreach ($rows as $row) {
			$arr = $this->toArray(object: $row);
			if ($arr !== []) {
				$out[] = $arr;
			}
		}

		return $out;
	}                  

	   
                                                    
   
                                       
   
                                           
    
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialised = $object->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$data = $object->getObject();
			if (is_array($data) === true) {
				return $data;
			}
		}

		if (is_object($object) === true) {
			return (array)$object;
		}

		return [];
	}               

	   
                                                          
   
                                                      
   
                                                       
    
	private function extractId(array $entity): string {
		$self = ($entity['@self'] ?? []);
		if (is_array($self) === true && isset($self['id']) === true) {
			return (string)$self['id'];
		}

		if (isset($entity['id']) === true) {
			return (string)$entity['id'];
		}

		if (isset($entity['uuid']) === true) {
			return (string)$entity['uuid'];
		}

		return '';
	}                 

	   
                                  
   
                            
    
	private function uuid(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}            

	   
                        
   
                                 
    
	private function nowIso(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');
	}              
}           
