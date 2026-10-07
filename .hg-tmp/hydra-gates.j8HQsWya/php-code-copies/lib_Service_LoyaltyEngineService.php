<?php

   
                                 
  
                                                                          
                                                                          
                                                                               
  
                                                                        
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                              
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\EventDispatcher\GenericEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                                                    
  
                                                              
  
                                                                                                                                                  
                                                                                                                                
   
class LoyaltyEngineService {
	   
                
   
                                                       
                                                                     
                                                                 
                                                        
                                                     
                                                            
                                              
                                                                                         
    
	public function __construct(
		private IAppConfig $appConfig,
		private LoyaltyAccountService $accountService,
		private PointsLedgerService $ledgerService,
		private PointsRuleEngine $ruleEngine,
		private TierService $tierService,
		private IEventDispatcher $eventDispatcher,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                                                           
   
                                                                         
                                                                     
                                                                            
   
                                                        
                                                                         
                                                                                          
                                                                       
   
                                                                             
                                                                                    
                                                                            
   
                                           
    
	public function processPosTransaction(string $customerId, array $transaction): array {
		if ($customerId === '') {
			return [];
		}

		$results = [];
		$programmes = $this->getActiveProgrammes();
		foreach ($programmes as $programme) {
			$programmeId = $this->extractProgrammeUuid(programme: $programme);
			if ($programmeId === null) {
				continue;
			}

			try {
				$results[] = $this->processForProgramme(
					customerId: $customerId,
					programmeId: $programmeId,
					transaction: $transaction
				);
			} catch (Throwable $e) {
				$this->logger->warning(
					'Pipelinq: loyalty processing failed for programme; POS flow unaffected',
					[
						'programmeId' => $programmeId,
						'customerId' => $customerId,
						'exception' => $e->getMessage(),
					]
				);
				$results[] = [
					'programmeId' => $programmeId,
					'status' => 'error',
					'reason' => $e->getMessage(),
				];
			}         
		}             

		return $results;
	}                             

	   
                                   
   
                                              
                                                  
                                                                     
   
                                
    
	private function processForProgramme(
		string $customerId,
		string $programmeId,
		array $transaction,
	): array {
		$account = $this->accountService->findAccountByKlantAndProgramme(
			customerId: $customerId,
			programmeId: $programmeId
		);

		if ($account === null) {
			                                                                 
			return [
				'programmeId' => $programmeId,
				'status' => 'skipped',
				'reason' => 'not enrolled',
			];
		}

		$accountId = (string)$this->extractUuid(object: $account);
		if ((string)($account['status'] ?? '') !== 'active') {
			$this->logger->info(
				'Pipelinq: account disabled, points credit skipped',
				['accountId' => $accountId, 'customerId' => $customerId]
			);
			return [
				'programmeId' => $programmeId,
				'accountId' => $accountId,
				'status' => 'blocked',
				'reason' => 'account ' . (string)($account['status'] ?? 'unknown'),
			];
		}

		return $this->awardPointsForActiveAccount(
			programmeId: $programmeId,
			account: $account,
			accountId: $accountId,
			transaction: $transaction
		);
	}                           

	   
                                                                             
   
                                                  
                                                                    
                                              
                                                                     
   
                                
    
	private function awardPointsForActiveAccount(string $programmeId, array $account, string $accountId, array $transaction): array {
		$context = $this->buildTransactionContext(transaction: $transaction);

		$matches = $this->ruleEngine->evaluateRules(
			programmeId: $programmeId,
			trigger: (string)($transaction['trigger'] ?? 'purchase'),
			context: $context
		);
		$rule = $this->ruleEngine->getHighestPriorityRule($matches);
		if ($rule === null) {
			return [
				'programmeId' => $programmeId,
				'accountId' => $accountId,
				'status' => 'skipped',
				'reason' => 'no matching rule',
			];
		}

		$multiplier = $this->resolveTierMultiplier(account: $account, programmeId: $programmeId);

		$formula = $rule['formula'] ?? [];
		if (is_array($formula) === false) {
			$formula = [];
		}

		$rawPoints = $this->ruleEngine->calculatePoints(
			formula: $formula,
			amount: $context['amount'],
			multiplier: $multiplier
		);

		$toAward = $this->enforceMaxPerPeriod(rule: $rule, accountId: $accountId, rawPoints: $rawPoints);

		if ($toAward <= 0) {
			$this->logger->info(
				'Pipelinq: max-per-period reached or zero points; skipping credit',
				['accountId' => $accountId, 'ruleId' => $this->extractUuid(object: $rule)]
			);
			return [
				'programmeId' => $programmeId,
				'accountId' => $accountId,
				'status' => 'skipped',
				'reason' => 'max reached for this customer this period',
				'points' => 0,
			];
		}

		return $this->creditAndFinalize(
			programmeId: $programmeId,
			accountId: $accountId,
			rule: $rule,
			toAward: $toAward,
			context: $context,
			transaction: $transaction,
			multiplier: $multiplier
		);
	}                                   

	   
                                                                       
   
                                                                     
   
                                
    
	private function buildTransactionContext(array $transaction): array {
		return [
			'amount' => (float)($transaction['amount'] ?? 0),
			'category' => (string)($transaction['category'] ?? ''),
			'channel' => (string)($transaction['channel'] ?? ''),
			'segment' => (string)($transaction['segment'] ?? ''),
			'timestamp' => (string)($transaction['timestamp'] ?? (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c')),
		];
	}                               

	   
                                                                               
   
                                                             
                                                  
   
                 
    
	private function resolveTierMultiplier(array $account, string $programmeId): float {
		$multiplier = 1.0;
		$currentTierId = (string)($account['currentTierId'] ?? '');
		if ($currentTierId === '') {
			return $multiplier;
		}

		$tierRules = $this->tierService->getTierRules(programmeId: $programmeId);
		foreach ($tierRules as $tierRule) {
			if ((string)$this->extractUuid(object: $tierRule) === $currentTierId) {
				$multiplier = $this->tierService->applyTierBenefits(tier: $tierRule);
				break;
			}
		}

		return $multiplier;
	}                             

	   
                                                                           
   
                                                             
                                              
                                                         
   
               
    
	private function enforceMaxPerPeriod(array $rule, string $accountId, int $rawPoints): int {
		$max = $rule['maxPerCustomerPerPeriod'] ?? null;
		$period = (string)($rule['maxPerCustomerPeriod'] ?? 'day');
		$already = 0;
		if ($max !== null && (int)$max > 0) {
			$already = $this->countAlreadyEarned(
				accountId: $accountId,
				ruleId: (string)$this->extractUuid(object: $rule),
				period: $period
			);
		}

		$maxInt = null;
		if ($max !== null) {
			$maxInt = (int)$max;
		}

		return $this->ruleEngine->applyMaxPerCustomer(
			earnedInPeriod: $already,
			pointsToAward: $rawPoints,
			max: $maxInt
		);
	}                           

	   
                                                                              
   
                                                  
                                              
                                                             
                                         
                                                                     
                                                                     
                                                         
   
                                
    
	private function creditAndFinalize(
		string $programmeId,
		string $accountId,
		array $rule,
		int $toAward,
		array $context,
		array $transaction,
		float $multiplier,
	): array {
		$ledgerEntry = $this->ledgerService->creditPoints(
			accountId: $accountId,
			amount: $toAward,
			ruleId: $this->extractUuid(object: $rule),
			sourceDocument: [
				'transactionId' => $transaction['posTransactionId'] ?? null,
				'amount' => $context['amount'],
				'channel' => $context['channel'],
			],
			processedBy: (string)($transaction['posTerminalId'] ?? 'system')
		);

		                    
		$tierResult = $this->tierService->updateTierIfNeeded(accountId: $accountId);

		$this->emitCreditedEvent(
			accountId: $accountId,
			programmeId: $programmeId,
			ledgerEntry: $ledgerEntry
		);

		return [
			'programmeId' => $programmeId,
			'accountId' => $accountId,
			'status' => 'credited',
			'points' => $toAward,
			'ruleId' => $this->extractUuid(object: $rule),
			'tierChanged' => $tierResult['changed'],
			'multiplier' => $multiplier,
		];
	}                         

	   
                                                
   
                                            
    
	private function getActiveProgrammes(): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'loyaltyProgramme_schema', '');
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'status' => 'active',
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 200,
				]
			);
		} catch (Throwable $e) {
			return [];
		}

		$values = [];
		if (is_array($rows) === true) {
			$values = array_values($rows);
		}

		return array_map([$this, 'toArray'], $values);
	}                           

	   
                                                                                           
   
                                              
                                        
                                              
   
                                                           
    
	private function countAlreadyEarned(string $accountId, string $ruleId, string $period): int {
		$from = $this->periodStart(period: $period);
		$history = $this->ledgerService->getLedgerHistory(accountId: $accountId, from: $from);
		$sum = 0;
		foreach ($history as $e) {
			if ((string)($e['type'] ?? '') === 'credit' && (string)($e['ruleId'] ?? '') === $ruleId) {
				$sum += (int)($e['count'] ?? 0);
			}
		}

		return $sum;
	}                          

	   
                                                                   
   
                                              
   
                                 
    
	private function periodStart(string $period): string {
		$tz = new DateTimeZone('UTC');
		$now = new DateTimeImmutable('now', $tz);
		switch ($period) {
			case 'week':
				$start = $now->modify('monday this week 00:00:00');
				break;
			case 'month':
				$start = $now->modify('first day of this month 00:00:00');
				break;
			case 'year':
				$start = $now->modify('first day of January ' . $now->format('Y') . ' 00:00:00');
				break;
			case 'day':
			default:
				$start = $now->setTime(0, 0, 0);
		}

		return $start->format('c');
	}                   

	   
                                         
   
                                              
                                                  
                                                                           
   
                
    
	private function emitCreditedEvent(string $accountId, string $programmeId, array $ledgerEntry): void {
		try {
			$event = new GenericEvent(
				null,
				[
					'type' => 'loyalty.points.credited',
					'accountId' => $accountId,
					'programmeId' => $programmeId,
					'count' => (int)($ledgerEntry['count'] ?? 0),
					'balanceAfter' => (int)($ledgerEntry['balanceAfter'] ?? 0),
				]
			);
			$this->eventDispatcher->dispatchTyped($event);
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: points-credited event dispatch failed', ['exception' => $e->getMessage()]);
		}
	}                         

	   
                           
   
                                                         
   
                   
    
	private function extractProgrammeUuid(array $programme): ?string {
		$self = $programme['@self'] ?? [];
		if (is_array($self) === true && isset($self['id']) === true) {
			return (string)$self['id'];
		}

		return $programme['programmeId'] ?? $programme['uuid'] ?? $programme['id'] ?? null;
	}                            

	   
                    
   
                                                      
   
                   
    
	private function extractUuid(array $object): ?string {
		$self = $object['@self'] ?? [];
		if (is_array($self) === true && isset($self['id']) === true) {
			return (string)$self['id'];
		}

		return $object['uuid'] ?? $object['id'] ?? null;
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
