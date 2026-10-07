<?php

   
                            
  
                                                                               
                                                                           
                                                                               
                                                   
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                              
                                                              
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                       
  
                                                                                                                            
  
                                                              
   
class GiftCardService {
	   
                
   
                                                       
                                              
                                                                                         
    
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                                                           
   
                                                        
                                                      
                                                           
                                            
                                             
   
                                                                                                
   
                                                                  
    
	public function issueGiftCard(
		?string $programmeId,
		float $initialBalance,
		int $expiryDays = 365,
		string $channel = 'purchased',
		?string $issuedIn = null,
	): array {
		if ($initialBalance <= 0) {
			throw new RuntimeException('Initial balance must be positive.');
		}

		$serial = $this->generateUniqueSerial();
		$pin = $this->generatePin();
		$hash = password_hash($pin, PASSWORD_BCRYPT, ['cost' => 10]);

		$tz = new DateTimeZone('UTC');
		$now = (new DateTimeImmutable('now', $tz))->format('c');
		$exp = (new DateTimeImmutable("+{$expiryDays} days", $tz))->format('c');

		$card = [
			'programmeId' => $programmeId,
			'serial' => $serial,
			'pin' => $hash,
			'initialBalance' => $initialBalance,
			'currentBalance' => $initialBalance,
			'currency' => 'EUR',
			'status' => 'issued',
			'issuedOn' => $now,
			'issuedIn' => $issuedIn,
			'expiresOn' => $exp,
			'channel' => $channel,
		];

		$saved = $this->persistCard(payload: $card, uuid: null);

		$this->logTransaction(
			giftCardId: (string)$this->extractUuid(object: $saved),
			type: 'issue',
			amount: $initialBalance,
			balanceAfter: $initialBalance,
			posTransactionId: null,
			processedBy: 'gift-card-service'
		);

		return ['card' => $saved, 'pin' => $pin];
	}                     

	   
                                                                                  
   
                                            
                                                            
   
                                 
   
                                                                  
    
	public function activateGiftCard(string $giftCardId, ?string $posTransactionId = null): ?array {
		$card = $this->getCard(giftCardId: $giftCardId);
		if ($card === null) {
			return null;
		}

		                                     
		  
		                                                                   
		                                                                      
		                                                                     
		                                                            
		                                                                     
		           
		  
		                                                                      
		                                                                      
		                                                   
		$status = (string)($card['status'] ?? '');
		if ($status === 'active') {
			return $card;
		}

		if ($status !== 'issued') {
			throw new RuntimeException('Gift card cannot be activated from status "' . $status . '".');
		}

		$card['status'] = 'active';
		$saved = $this->persistCard(payload: $card, uuid: $giftCardId);

		                                                                      
		                                                  
		$this->logger->info(
			'Pipelinq: gift card activated',
			['giftCardId' => $giftCardId, 'posTransactionId' => $posTransactionId]
		);

		return $saved;
	}                        

	   
                                                                           
   
                                            
                                                                 
                                                     
                                                                 
   
                                                                                                 
   
                                                                                  
   
                                                                                                           
                                                                                                           
   
                                                                  
                                                                  
    
	public function redeemGiftCard(
		string $giftCardId,
		string $pin,
		float $amount,
		?string $posTransactionId = null,
	): array {
		if ($amount <= 0) {
			throw new RuntimeException('Redemption amount must be positive.');
		}

		$card = $this->getCard(giftCardId: $giftCardId);
		if ($card === null) {
			throw new RuntimeException('Gift card not found.');
		}

		$status = (string)($card['status'] ?? '');
		if ($status === 'blocked') {
			throw new RuntimeException('Gift card is blocked.');
		}

		if (in_array($status, ['active'], true) === false) {
			throw new RuntimeException('Gift card is not active.');
		}

		$expiresOn = (string)($card['expiresOn'] ?? '');
		$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
		if ($expiresOn !== '' && $expiresOn < $now) {
			throw new RuntimeException('Gift card has expired.');
		}

		if ($this->verifyPin(plaintext: $pin, hash: (string)($card['pin'] ?? '')) === false) {
			throw new RuntimeException('Invalid PIN.');
		}

		                                               
		  
		                                                                         
		                                                                         
		                                                                  
		                                                                       
		        
		  
		                                                                       
		                                                                    
		                                                                     
		$replay = $this->findRedemptionByPosTransaction(
			giftCardId: $giftCardId,
			posTransactionId: $posTransactionId
		);
		if ($replay !== null) {
			return $replay;
		}

		$balance = (float)($card['currentBalance'] ?? 0);
		$applied = min($amount, $balance);
		$change = max(0.0, $amount - $applied);
		$after = round($balance - $applied, 2);

		$card['currentBalance'] = $after;
		$type = 'partial_redeem';
		if ($after <= 0.0) {
			$after = 0.0;
			$card['currentBalance'] = 0.0;
			$card['status'] = 'depleted';
			                                                                                        
			if (abs($applied - $balance) < 0.005 && $change === 0.0) {
				$type = 'redeem';
			}
		}

		$this->persistCard(payload: $card, uuid: $giftCardId);

		$this->logTransaction(
			giftCardId: $giftCardId,
			type: $type,
			amount: $applied,
			balanceAfter: $after,
			posTransactionId: $posTransactionId,
			processedBy: 'gift-card-service'
		);

		return [
			'amountApplied' => $applied,
			'balanceAfter' => $after,
			'changeAmount' => $change,
			'status' => (string)($card['status'] ?? 'active'),
		];
	}                      

	   
                                                                    
   
                                                                            
                                                                         
   
                                                                   
                                                                       
                                                                           
                                                                          
                                                                      
                                                                   
   
                                            
                                                                               
   
                                                                              
    
	private function findRedemptionByPosTransaction(string $giftCardId, ?string $posTransactionId): ?array {
		if ($posTransactionId === null || $posTransactionId === '') {
			return null;
		}

		[$register, $schema] = $this->config(schemaKey: 'giftCardTransaction_schema');
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'giftCardId' => $giftCardId,
						'posTransactionId' => $posTransactionId,
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 1,
				]
			);
		} catch (\Throwable $e) {
			return null;
		}

		foreach ((array)$rows as $row) {
			$replay = $this->replayFromTransactionRow(row: $row);
			if ($replay !== null) {
				return $replay;
			}
		}

		return null;
	}                                      

	   
                                                              
   
                                                                          
                                                                            
                                 
   
                                                       
   
                                                                            
                                                              
    
	private function replayFromTransactionRow(mixed $row): ?array {
		$array = $row;
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$array = $row->jsonSerialize();
		}

		if (is_array($array) === false) {
			return null;
		}

		$type = (string)($array['type'] ?? '');
		if ($type !== 'redeem' && $type !== 'partial_redeem') {
			return null;
		}

		$balanceAfter = (float)($array['balanceAfter'] ?? 0);
		$status = 'active';
		if ($balanceAfter <= 0.0) {
			$status = 'depleted';
		}

		return [
			'amountApplied' => (float)($array['amount'] ?? 0),
			'balanceAfter' => $balanceAfter,
			'changeAmount' => 0.0,
			'status' => $status,
		];
	}                                

	   
                                 
   
                                            
                                                     
                                                                 
   
                                                  
                                                               
    
	public function refundGiftCard(
		string $giftCardId,
		float $amount,
		?string $posTransactionId = null,
	): array {
		if ($amount <= 0) {
			throw new RuntimeException('Refund amount must be positive.');
		}

		$card = $this->getCard(giftCardId: $giftCardId);
		if ($card === null) {
			throw new RuntimeException('Gift card not found.');
		}

		$balance = (float)($card['currentBalance'] ?? 0);
		$after = round($balance + $amount, 2);
		$card['currentBalance'] = $after;

		if ((string)($card['status'] ?? '') === 'depleted' && $after > 0) {
			$card['status'] = 'active';
		}

		$saved = $this->persistCard(payload: $card, uuid: $giftCardId);

		$this->logTransaction(
			giftCardId: $giftCardId,
			type: 'refund',
			amount: $amount,
			balanceAfter: $after,
			posTransactionId: $posTransactionId,
			processedBy: 'gift-card-service'
		);

		return $saved;
	}                      

	   
                                        
   
                                            
                                           
   
                                 
                                                               
    
	public function blockGiftCard(string $giftCardId, string $reason): ?array {
		$card = $this->getCard(giftCardId: $giftCardId);
		if ($card === null) {
			return null;
		}

		$card['status'] = 'blocked';
		$saved = $this->persistCard(payload: $card, uuid: $giftCardId);

		$this->logTransaction(
			giftCardId: $giftCardId,
			type: 'block',
			amount: 0.0,
			balanceAfter: (float)($card['currentBalance'] ?? 0),
			posTransactionId: null,
			processedBy: 'gift-card-service'
		);

		$this->logger->info(
			'Pipelinq: gift card blocked',
			['giftCardId' => $giftCardId, 'reason' => $reason]
		);

		return $saved;
	}                     

	   
                                                                     
   
                                 
   
                                            
                                         
   
                                                                      
   
                                            
                                                               
    
	public function getCardDetails(string $giftCardId, string $pin): array {
		$card = $this->getCard(giftCardId: $giftCardId);
		if ($card === null) {
			throw new RuntimeException('Gift card not found.');
		}

		if ($this->verifyPin(plaintext: $pin, hash: (string)($card['pin'] ?? '')) === false) {
			throw new RuntimeException('Invalid PIN.');
		}

		return [
			'balance' => (float)($card['currentBalance'] ?? 0),
			'status' => (string)($card['status'] ?? 'issued'),
			'expiryDate' => $card['expiresOn'] ?? null,
		];
	}                      

	   
                                                  
   
                                     
                                         
   
                                                                                                         
   
                                                                
    
	public function validateBySerial(string $serial, string $pin): array {
		$card = $this->findBySerial(serial: $serial);
		if ($card === null) {
			return ['valid' => false, 'balance' => 0.0, 'expiryDate' => null, 'giftCardId' => null, 'reason' => 'Not found'];
		}

		$status = (string)($card['status'] ?? '');
		if (in_array($status, ['blocked'], true) === true) {
			return [
				'valid' => false,
				'balance' => (float)($card['currentBalance'] ?? 0),
				'expiryDate' => ($card['expiresOn'] ?? null),
				'giftCardId' => $this->extractUuid(object: $card),
				'reason' => 'Blocked',
			];
		}

		$expiresOn = (string)($card['expiresOn'] ?? '');
		$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
		if ($expiresOn !== '' && $expiresOn < $now) {
			return [
				'valid' => false,
				'balance' => (float)($card['currentBalance'] ?? 0),
				'expiryDate' => $expiresOn,
				'giftCardId' => $this->extractUuid(object: $card),
				'reason' => 'Expired',
			];
		}

		if ($this->verifyPin(plaintext: $pin, hash: (string)($card['pin'] ?? '')) === false) {
			return ['valid' => false, 'balance' => 0.0, 'expiryDate' => null, 'giftCardId' => null, 'reason' => 'Invalid PIN'];
		}

		$expiryDate = null;
		if ($expiresOn !== '') {
			$expiryDate = $expiresOn;
		}

		return [
			'valid' => true,
			'balance' => (float)($card['currentBalance'] ?? 0),
			'expiryDate' => $expiryDate,
			'giftCardId' => $this->extractUuid(object: $card),
			'reason' => null,
		];
	}                        

	   
                       
   
                                            
   
                                     
                                                                                       
                              
    
	public function getCard(string $giftCardId): ?array {
		[$register, $schema] = $this->config(schemaKey: 'giftCard_schema');
		if ($register === '' || $schema === '' || $giftCardId === '') {
			return null;
		}

		try {
			$object = $this->getObjectService()->find(id: $giftCardId, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			return null;
		}

		if ($object === null) {
			return null;
		}

		return $this->toArray(object: $object);
	}               

	   
                          
   
                                     
   
                                     
   
                                                                
    
	public function findBySerial(string $serial): ?array {
		[$register, $schema] = $this->config(schemaKey: 'giftCard_schema');
		if ($register === '' || $schema === '' || $serial === '') {
			return null;
		}

		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'serial' => $serial,
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 1,
				]
			);
		} catch (\Throwable $e) {
			return null;
		}

		if (is_array($rows) === false) {
			$rows = [];
		}

		$rows = array_values($rows);
		if ($rows === []) {
			return null;
		}

		return $this->toArray(object: reset($rows));
	}                    

	   
                                                             
   
                                          
   
                                            
   
                                                                
    
	public function listForKlant(string $customerId): array {
		[$register, $schema] = $this->config(schemaKey: 'giftCard_schema');
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'customerId' => $customerId,
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 1000,
				]
			);
		} catch (\Throwable $e) {
			return [];
		}

		$rowList = [];
		if (is_array($rows) === true) {
			$rowList = array_values($rows);
		}

		return array_map([$this, 'toArray'], $rowList);
	}                    

	   
                                                 
   
                                               
                                        
   
                
    
	private function verifyPin(string $plaintext, string $hash): bool {
		if ($hash === '') {
			return false;
		}

		return password_verify($plaintext, $hash);
	}                 

	   
                                                          
   
                                            
                                             
                                             
                                                              
                                                            
                                                    
   
                
    
	private function logTransaction(
		string $giftCardId,
		string $type,
		float $amount,
		float $balanceAfter,
		?string $posTransactionId,
		string $processedBy,
	): void {
		[$register, $schema] = $this->config(schemaKey: 'giftCardTransaction_schema');
		if ($register === '' || $schema === '') {
			return;
		}

		$payload = [
			'giftCardId' => $giftCardId,
			'type' => $type,
			'amount' => $amount,
			'balanceAfter' => $balanceAfter,
			'timestamp' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c'),
			'posTransactionId' => $posTransactionId,
			'processedBy' => $processedBy,
		];

		try {
			$this->getObjectService()->saveObject(
				object: $payload,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: null
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: gift card transaction log failed',
				['giftCardId' => $giftCardId, 'type' => $type, 'exception' => $e->getMessage()]
			);
		}
	}                      

	   
                                                                          
   
                  
    
	private function generateUniqueSerial(): string {
		for ($i = 0; $i < 10; $i++) {
			$candidate = 'GC-' . str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
			if ($this->findBySerial(serial: $candidate) === null) {
				return $candidate;
			}
		}

		throw new RuntimeException('Failed to generate unique gift card serial after 10 retries.');
	}                            

	   
                                  
   
                  
    
	private function generatePin(): string {
		return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
	}                   

	   
                   
   
                                                       
                                       
   
                                
    
	private function persistCard(array $payload, ?string $uuid): array {
		[$register, $schema] = $this->config(schemaKey: 'giftCard_schema');
		if ($register === '' || $schema === '') {
			throw new RuntimeException('GiftCard schema is not configured.');
		}

		$saved = $this->getObjectService()->saveObject(
			object: $payload,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: $uuid
		);

		return $this->toArray(object: $saved);
	}                   

	   
                                 
   
                                                                        
                                                                            
                                                                             
                                                                             
                                                                           
                                                                      
   
                                                   
   
                                                                           
                                                          
    
	private function config(string $schemaKey): array {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schemaId = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
		if ($registerId === '' || $schemaId === '') {
			$this->logger->warning(
				'Pipelinq: register/schema not configured; OpenRegister calls are refused, not run unscoped',
				['schemaKey' => $schemaKey]
			);
		}

		return [$registerId, $schemaId];
	}              

	   
                                         
   
                                                      
   
                   
    
	private function extractUuid(array $object): ?string {
		$self = $object['@self'] ?? [];
		if (is_array($self) === true && isset($self['id']) === true) {
			return (string)$self['id'];
		}

		return $object['giftCardId'] ?? $object['uuid'] ?? $object['id'] ?? null;
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
			$decoded = $object->getObject();
			if (is_array($decoded) === true) {
				return $decoded;
			}
		}

		return [];
	}               

	   
                          
   
                  
    
	private function getObjectService(): object {
		                                                                 
		                                                              
		return $this->objectService;
	}                        
}           
