<?php

   
                                
  
                                                                                 
                                                                                 
                                                                              
                                                                           
                                         
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                              
                                                              
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;

   
                             
  
                                                              
  
                                                                      
                                                                              
                                                                   
                           
                                                                             
                                                                             
                                                                              
                                          
   
class PointsLedgerService {
	   
                
   
                                                       
                                                                             
                                              
                                                                                         
                                                                              
    
	public function __construct(
		private IAppConfig $appConfig,
		private LoyaltyAccountService $accountService,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly AggregationRunner $aggregationRunner,
	) {
	}                   

	   
                                                                       
   
                                              
                                                         
                                                                        
                                                                                    
                                                                               
   
                                                               
   
                                                                            
   
                                                                  
    
	public function creditPoints(
		string $accountId,
		int $amount,
		?string $ruleId,
		array $sourceDocument,
		string $processedBy,
	): array {
		if ($amount <= 0) {
			throw new RuntimeException('Credit amount must be positive.');
		}

		return $this->appendAndUpdate(
			accountId: $accountId,
			type: 'credit',
			signedCount: $amount,
			ruleId: $ruleId,
			sourceDocument: $sourceDocument,
			processedBy: $processedBy,
			lifetimeDelta: $amount
		);
	}                    

	   
                                    
   
                                              
                                                        
                                                    
                                                               
                                                     
   
                                                       
   
                                                                              
   
                                                                  
    
	public function debitPoints(
		string $accountId,
		int $amount,
		string $redemptionId,
		array $sourceDocument,
		string $processedBy,
	): array {
		if ($amount <= 0) {
			throw new RuntimeException('Debit amount must be positive.');
		}

		$account = $this->accountService->getAccount(accountId: $accountId);
		if ($account === null) {
			throw new RuntimeException('Account not found.');
		}

		if ((int)($account['currentBalance'] ?? 0) < $amount) {
			throw new RuntimeException('Insufficient balance.');
		}

		$sourceDocument['redemptionId'] = $redemptionId;

		return $this->appendAndUpdate(
			accountId: $accountId,
			type: 'debit',
			signedCount: -$amount,
			ruleId: null,
			sourceDocument: $sourceDocument,
			processedBy: $processedBy,
			lifetimeDelta: 0
		);
	}                   

	   
                                                             
   
                                              
                                                  
                                                                
   
                                                  
   
                                                                  
    
	public function expirePoints(string $accountId, int $amount, string $reason): array {
		if ($amount <= 0) {
			throw new RuntimeException('Expiry amount must be positive.');
		}

		return $this->appendAndUpdate(
			accountId: $accountId,
			type: 'expiry',
			signedCount: -$amount,
			ruleId: null,
			sourceDocument: ['expiryPolicyRef' => $reason],
			processedBy: 'system:expiry-batch',
			lifetimeDelta: 0
		);
	}                    

	   
                                     
   
                                              
                                   
                                 
                                             
   
                                                  
                                                               
    
	public function adjustPoints(string $accountId, int $delta, string $reason, string $processedBy): array {
		if ($delta === 0) {
			throw new RuntimeException('Adjustment delta cannot be zero.');
		}

		return $this->appendAndUpdate(
			accountId: $accountId,
			type: 'adjustment',
			signedCount: $delta,
			ruleId: null,
			sourceDocument: ['reason' => $reason],
			processedBy: $processedBy,
			lifetimeDelta: max(0, $delta)
		);
	}                    

	   
                                                           
   
                                              
                                                      
                                                              
                                             
   
                                                  
                                                               
    
	public function refundPoints(
		string $accountId,
		int $amount,
		string $redemptionId,
		string $processedBy,
	): array {
		if ($amount <= 0) {
			throw new RuntimeException('Refund amount must be positive.');
		}

		return $this->appendAndUpdate(
			accountId: $accountId,
			type: 'refund',
			signedCount: $amount,
			ruleId: null,
			sourceDocument: ['redemptionId' => $redemptionId, 'reason' => 'redemption cancelled'],
			processedBy: $processedBy,
			lifetimeDelta: 0
		);
	}                    

	   
                                                               
   
                                              
   
                                                    
   
                                                                                                                                      
                                                               
    
	public function getAccountBalance(string $accountId): int {
		[$register, $schema] = $this->config();
		if ($register === '' || $schema === '' || $accountId === '') {
			return 0;
		}

		                                                                        
		                                                                    
		                                                                        
		                                                                      
		                                                                        
		                                                                       
		                                                       
		try {
			$query = AggregationQuery::create(
				metric: 'sum',
				field: 'count',
				filter: ['accountId' => $accountId],
			);
			$result = $this->getAggregationRunner()->runAdhocByRef(
				registerRef: $register,
				schemaRef: $schema,
				query: $query
			);
		} catch (\Throwable $e) {
			$this->logger->debug('Pipelinq: ledger balance aggregation failed', ['exception' => $e->getMessage()]);
			return 0;
		}

		return (int)round((float)($result['value'] ?? 0));
	}                         

	   
                                                                        
   
                                              
                                                                       
                                                                     
   
                                                                              
   
                                           
    
	public function getLedgerHistory(string $accountId, ?string $from = null, ?string $to = null): array {
		[$register, $schema] = $this->config();
		if ($register === '' || $schema === '' || $accountId === '') {
			return [];
		}

		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'accountId' => $accountId,
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 10000,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->debug('Pipelinq: ledger findAll failed', ['exception' => $e->getMessage()]);
			return [];
		}

		$rowsToMap = [];
		if (is_array($rows) === true) {
			$rowsToMap = array_values($rows);
		}

		$rows = array_map([$this, 'toArray'], $rowsToMap);

		$filtered = array_filter(
			$rows,
			fn (array $entry): bool => $this->isWithinWindow(entry: $entry, from: $from, to: $to)
		);

		usort(
			$filtered,
			static fn (array $a, array $b): int => strcmp((string)($a['timestamp'] ?? ''), (string)($b['timestamp'] ?? ''))
		);

		                                                             
		                                            
		return $filtered;
	}                        

	   
                                                                                  
   
                                                        
                                                                   
                                                                 
   
                
    
	private function isWithinWindow(array $entry, ?string $from, ?string $to): bool {
		$timestamp = (string)($entry['timestamp'] ?? '');
		if ($from !== null && $timestamp < $from) {
			return false;
		}

		if ($to !== null && $timestamp > $to) {
			return false;
		}

		return true;
	}                      

	   
                                                                       
   
                                                  
                                                                         
                                                                       
                                                        
   
                                            
                                                               
    
	public function getLedgerEntriesForProgramme(
		string $programmeId,
		string $type,
		?string $from = null,
		?string $to = null,
	): array {
		                                                                   
		$accounts = $this->accountService->listAccountsForProgramme(programmeId: $programmeId, limit: 10000);

		$entries = [];
		foreach ($accounts as $account) {
			$accountId = (string)($account['accountId'] ?? $account['@self']['id'] ?? $account['uuid'] ?? '');
			if ($accountId === '') {
				continue;
			}

			$history = $this->getLedgerHistory(accountId: $accountId, from: $from, to: $to);
			foreach ($history as $e) {
				if ((string)($e['type'] ?? '') === $type) {
					$entries[] = $e;
				}
			}
		}

		return $entries;
	}                                    

	   
                                                                    
   
                                                                       
                                                                            
                                                                      
                                                     
   
                                              
                                                                     
                                         
                                                    
                                                               
                                                    
                                                                                     
   
                                                  
    
	private function appendAndUpdate(
		string $accountId,
		string $type,
		int $signedCount,
		?string $ruleId,
		array $sourceDocument,
		string $processedBy,
		int $lifetimeDelta,
	): array {
		$account = $this->accountService->getAccount(accountId: $accountId);
		if ($account === null) {
			throw new RuntimeException('Account not found.');
		}

		$currentBalance = (int)($account['currentBalance'] ?? 0);
		$newBalance = $currentBalance + $signedCount;
		$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');

		$entry = [
			'accountId' => $accountId,
			'customerId' => $account['customerId'] ?? null,
			'type' => $type,
			'count' => $signedCount,
			'balanceAfter' => $newBalance,
			'sourceDocument' => $sourceDocument,
			'ruleId' => $ruleId,
			'timestamp' => $now,
			'processedBy' => $processedBy,
		];

		$saved = $this->persist(payload: $entry);

		try {
			$this->accountService->updateBalances(
				accountId: $accountId,
				newCurrentBalance: $newBalance,
				lifetimeDelta: $lifetimeDelta,
				lastActivityDate: $now
			);
		} catch (\Throwable $e) {
			                                                                   
			$this->logger->error(
				'Pipelinq: account balance denorm update failed; ledger entry retained',
				['accountId' => $accountId, 'exception' => $e->getMessage()]
			);
		}

		return $saved;
	}                       

	   
                                                                     
   
                                                                  
   
                                                 
    
	private function persist(array $payload): array {
		[$register, $schema] = $this->config();
		if ($register === '' || $schema === '') {
			throw new RuntimeException('PointsLedgerEntry schema is not configured.');
		}

		$saved = $this->getObjectService()->saveObject(
			object: $payload,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: null
		);

		return $this->toArray(object: $saved);
	}               

	   
                                         
   
                                                                        
                                                                            
                                                                             
                                                                             
                                                                           
                                                                      
   
                                                                           
                                                          
    
	private function config(): array {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schemaId = $this->appConfig->getValueString(Application::APP_ID, 'pointsLedgerEntry_schema', '');
		if ($registerId === '' || $schemaId === '') {
			$this->logger->warning(
				'Pipelinq: register/pointsLedgerEntry_schema not configured; OpenRegister calls are refused, not run unscoped'
			);
		}

		return [$registerId, $schemaId];
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

	   
                                                  
   
                                                                          
                                                                              
                                                                          
                                                                             
                                                         
   
                                          
    
	private function getAggregationRunner(): object {
		return $this->aggregationRunner;
	}                            
}           
