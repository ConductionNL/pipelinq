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

   
                                
  
                                                              
  
                                                                          
                                                                            
             
   
class RedemptionService {
	   
                
   
                                                       
                                                                     
                                                                 
                                              
                                                                                 
    
	public function __construct(
		private IAppConfig $appConfig,
		private LoyaltyAccountService $accountService,
		private PointsLedgerService $ledgerService,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                                                                        
   
                                              
                                                      
   
                                                       
   
                                                                                                 
   
                                                                  
    
	public function initiateRedemption(string $accountId, string $optionId): array {
		$account = $this->accountService->getAccount(accountId: $accountId);
		if ($account === null) {
			throw new RuntimeException('Account not found.');
		}

		$option = $this->getOption(optionId: $optionId);
		if ($option === null) {
			throw new RuntimeException('Redemption option not found.');
		}

		$cost = $this->assertRedemptionEligible(account: $account, option: $option, accountId: $accountId, optionId: $optionId);

		$code = $this->generateBeloningCode();
		$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
		$redemption = [
			'accountId' => $accountId,
			'customerId' => $account['customerId'] ?? null,
			'optionId' => $optionId,
			'programmeId' => $option['programmeId'] ?? null,
			'costInPoints' => $cost,
			'rewardCode' => $code,
			'status' => 'reserved',
			'initiatedOn' => $now,
			'validTo' => $this->codeExpiryDefault(),
		];

		$saved = $this->persist(payload: $redemption, uuid: null);

		                                                                                         
		$redemptionUuid = $this->extractUuid(object: $saved);
		try {
			$this->ledgerService->debitPoints(
				accountId: $accountId,
				amount: $cost,
				redemptionId: (string)$redemptionUuid,
				sourceDocument: ['optionId' => $optionId, 'rewardCode' => $code],
				processedBy: 'redemption-service'
			);
		} catch (\Throwable $e) {
			                                                    
			$this->logger->warning(
				'Pipelinq: redemption debit failed; rolling back Redemption',
				['accountId' => $accountId, 'optionId' => $optionId, 'exception' => $e->getMessage()]
			);
			if ($redemptionUuid !== null) {
				$this->deleteRedemption(redemptionId: (string)$redemptionUuid);
			}

			throw new RuntimeException('Debit failed: ' . $e->getMessage(), 0, $e);
		}

		return $saved;
	}                          

	   
                                                                          
   
                                                     
                                                              
                                              
                                            
   
                                   
   
                                                                                                         
    
	private function assertRedemptionEligible(array $account, array $option, string $accountId, string $optionId): int {
		if ((string)($account['status'] ?? '') !== 'active') {
			throw new RuntimeException('Account is not active.');
		}

		if ($this->isOptionValid(option: $option) === false) {
			throw new RuntimeException('Redemption option is not currently valid.');
		}

		$cost = (int)($option['costInPoints'] ?? 0);
		if ((int)($account['currentBalance'] ?? 0) < $cost) {
			throw new RuntimeException('Insufficient balance.');
		}

		$perCustomerLimit = $option['perCustomerLimit'] ?? null;
		if ($perCustomerLimit !== null && (int)$perCustomerLimit > 0) {
			$usedCount = $this->countUsedRedemptions(accountId: $accountId, optionId: $optionId);
			if ($usedCount >= (int)$perCustomerLimit) {
				throw new RuntimeException('Redemption limit reached for this option.');
			}
		}

		return $cost;
	}                                

	   
                                                      
   
                                         
   
                                                                                  
                                                               
    
	public function validateCode(string $code): array {
		$redemption = $this->findByCode(code: $code);
		if ($redemption === null) {
			return ['valid' => false, 'redemption' => null, 'reason' => 'Code not found'];
		}

		$status = (string)($redemption['status'] ?? '');
		if ($status !== 'reserved') {
			return ['valid' => false, 'redemption' => $redemption, 'reason' => 'Status is ' . $status];
		}

		$validTo = (string)($redemption['validTo'] ?? '');
		$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
		if ($validTo !== '' && $validTo < $now) {
			                            
			$this->markExpired(redemptionId: $this->extractUuid(object: $redemption) ?? '');
			return ['valid' => false, 'redemption' => $redemption, 'reason' => 'Redemption code expired'];
		}

		return ['valid' => true, 'redemption' => $redemption, 'reason' => null];
	}                    

	   
                              
   
                                                    
                                                                 
   
                                                        
                                                               
    
	public function markRedemptionUsed(string $redemptionId, ?string $posTransactionId = null): array {
		$redemption = $this->getRedemption(redemptionId: $redemptionId);
		if ($redemption === null) {
			throw new RuntimeException('Redemption not found.');
		}

		                                             
		if ((string)($redemption['status'] ?? '') === 'used') {
			return $redemption;
		}

		$redemption['status'] = 'used';
		$redemption['usedOn'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
		$redemption['posTransactionId'] = $posTransactionId;

		return $this->persist(payload: $redemption, uuid: $redemptionId);
	}                          

	  
                               
   
                                                                     
                                                                        
                                                                            
                                                                   
                                                                     
                                                                      
                                                  
    

	   
                                                                      
   
                                                    
   
                                 
                                                                                       
                              
    
	public function expireRedemption(string $redemptionId): ?array {
		return $this->markExpired(redemptionId: $redemptionId);
	}                        

	   
                                                                     
   
                                              
                                                  
   
                                                                        
   
                                           
    
	public function getValidRedemptionOptions(string $accountId, string $programmeId): array {
		$account = $this->accountService->getAccount(accountId: $accountId);
		$balance = 0;
		if ($account !== null) {
			$balance = (int)($account['currentBalance'] ?? 0);
		}

		[$register, $schema] = $this->config(schemaKey: 'redemptionOption_schema');
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'programmeId' => $programmeId,
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 200,
				]
			);
		} catch (\Throwable $e) {
			return [];
		}

		$rowList = [];
		if (is_array($rows) === true) {
			$rowList = array_values($rows);
		}

		$options = array_map([$this, 'toArray'], $rowList);

		return array_values(
			array_filter(
				$options,
				fn (array $option): bool => $this->isOptionValid(option: $option)
					&& (int)($option['costInPoints'] ?? 0) <= $balance
			)
		);
	}                                 

	   
                             
   
                                                    
   
                                     
                                                                                       
                              
    
	public function getRedemption(string $redemptionId): ?array {
		[$register, $schema] = $this->config(schemaKey: 'redemption_schema');
		if ($register === '' || $schema === '' || $redemptionId === '') {
			return null;
		}

		try {
			$object = $this->getObjectService()->find(id: $redemptionId, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			return null;
		}

		if ($object === null) {
			return null;
		}

		return $this->toArray(object: $object);
	}                     

	   
                                          
   
                                 
   
                                     
   
                                           
    
	public function findByCode(string $code): ?array {
		[$register, $schema] = $this->config(schemaKey: 'redemption_schema');
		if ($register === '' || $schema === '' || $code === '') {
			return null;
		}

		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'rewardCode' => $code,
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 1,
				]
			);
		} catch (\Throwable $e) {
			return null;
		}

		$rowList = [];
		if (is_array($rows) === true) {
			$rowList = array_values($rows);
		}

		if ($rowList === []) {
			return null;
		}

		return $this->toArray(object: reset($rowList));
	}                  

	   
                                   
   
                                                    
   
                                 
    
	private function markExpired(string $redemptionId): ?array {
		$redemption = $this->getRedemption(redemptionId: $redemptionId);
		if ($redemption === null) {
			return null;
		}

		if ((string)($redemption['status'] ?? '') === 'lapsed') {
			return $redemption;
		}

		$redemption['status'] = 'lapsed';
		return $this->persist(payload: $redemption, uuid: $redemptionId);
	}                   

	   
                                                           
   
                                              
                                            
   
               
    
	private function countUsedRedemptions(string $accountId, string $optionId): int {
		[$register, $schema] = $this->config(schemaKey: 'redemption_schema');
		if ($register === '' || $schema === '') {
			return 0;
		}

		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'accountId' => $accountId,
						'optionId' => $optionId,
						'status' => 'used',
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 1000,
				]
			);
		} catch (\Throwable $e) {
			return 0;
		}

		if (is_array($rows) === true) {
			return count($rows);
		}

		return 0;
	}                            

	   
                                                                        
   
                                                   
   
                
    
	private function isOptionValid(array $option): bool {
		$today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
		$from = (string)($option['validFrom'] ?? '');
		$to = (string)($option['validTo'] ?? '');
		if ($from !== '' && $today < $from) {
			return false;
		}

		if ($to !== '' && $today > $to) {
			return false;
		}

		return true;
	}                     

	   
                           
   
                                            
   
                                     
    
	private function getOption(string $optionId): ?array {
		[$register, $schema] = $this->config(schemaKey: 'redemptionOption_schema');
		if ($register === '' || $schema === '' || $optionId === '') {
			return null;
		}

		try {
			$object = $this->getObjectService()->find(id: $optionId, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			return null;
		}

		if ($object === null) {
			return null;
		}

		return $this->toArray(object: $object);
	}                 

	   
                                                  
   
                  
    
	private function generateBeloningCode(): string {
		return 'RDM-' . strtoupper(bin2hex(random_bytes(4)));
	}                            

	   
                                                          
   
                                 
    
	private function codeExpiryDefault(): string {
		return (new DateTimeImmutable('+24 hours', new DateTimeZone('UTC')))->format('c');
	}                         

	   
                                                
   
                                                    
   
                
    
	private function deleteRedemption(string $redemptionId): void {
		[$register, $schema] = $this->config(schemaKey: 'redemption_schema');
		if ($register === '' || $schema === '' || $redemptionId === '') {
			return;
		}

		try {
			$this->getObjectService()->deleteObject(uuid: $redemptionId, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: redemption rollback delete failed',
				['redemptionId' => $redemptionId, 'exception' => $e->getMessage()]
			);
		}
	}                        

	   
                         
   
                                                  
                                       
   
                                
    
	private function persist(array $payload, ?string $uuid): array {
		[$register, $schema] = $this->config(schemaKey: 'redemption_schema');
		if ($register === '' || $schema === '') {
			throw new RuntimeException('Redemption schema is not configured.');
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

		return $object['redemptionId'] ?? $object['uuid'] ?? $object['id'] ?? null;
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

		return [];
	}               

	   
                          
   
                  
    
	private function getObjectService(): object {
		                                                                 
		                                                              
		return $this->objectService;
	}                        
}           
