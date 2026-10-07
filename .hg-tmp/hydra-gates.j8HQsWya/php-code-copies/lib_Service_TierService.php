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
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                            
  
                                                              
  
                                                                                                                                                      
                                                                                                                                      
   
class TierService {
	   
                
   
                                                       
                                                                             
                                                                  
                                              
                                                                                         
    
	public function __construct(
		private IAppConfig $appConfig,
		private LoyaltyAccountService $accountService,
		private IEventDispatcher $eventDispatcher,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                                                              
   
                                                  
   
                                                            
                                                               
    
	public function getTierRules(string $programmeId): array {
		[$register, $schema] = $this->config(schemaKey: 'tierRule_schema');
		if ($register === '' || $schema === '' || $programmeId === '') {
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
					'limit' => 100,
				]
			);
		} catch (\Throwable $e) {
			return [];
		}

		$rowList = [];
		if (is_array($rows) === true) {
			$rowList = array_values($rows);
		}

		$rules = array_map([$this, 'toArray'], $rowList);

		usort(
			$rules,
			static fn (array $a, array $b): int => (int)($a['sequence'] ?? 0) <=> (int)($b['sequence'] ?? 0)
		);

		return $rules;
	}                    

	   
                                                                           
   
                                                                             
                                                        
   
                                                  
                                                
   
                                                             
                                                               
    
	public function calculateTier(string $programmeId, int $lifetimePoints): ?array {
		$rules = $this->getTierRules(programmeId: $programmeId);
		$matched = null;
		foreach ($rules as $rule) {
			$threshold = (float)($rule['thresholdValue'] ?? 0);
			if ($lifetimePoints >= $threshold) {
				$matched = $rule;
			}
		}

		return $matched;
	}                     

	   
                                                        
   
                                              
   
                                                                     
                                                               
    
	public function updateTierIfNeeded(string $accountId): array {
		$account = $this->accountService->getAccount(accountId: $accountId);
		if ($account === null) {
			return ['from' => null, 'to' => null, 'changed' => false];
		}

		$programmeId = (string)($account['programmeId'] ?? '');
		$lifetimePoints = (int)($account['lifetimePoints'] ?? 0);
		$currentTierId = $account['currentTierId'] ?? null;

		$newTier = $this->calculateTier(programmeId: $programmeId, lifetimePoints: $lifetimePoints);
		if ($newTier === null) {
			return ['from' => $currentTierId, 'to' => null, 'changed' => false];
		}

		$newTierUuid = $this->extractUuid(object: $newTier);
		if ($newTierUuid === $currentTierId) {
			return ['from' => $currentTierId, 'to' => $currentTierId, 'changed' => false];
		}

		                               
		$isUpgrade = $this->isUpgrade(
			programmeId: $programmeId,
			currentTierId: $currentTierId,
			newTierId: $newTierUuid
		);

		if ($isUpgrade === true) {
			$this->handleTierUpgrade(accountId: $accountId, newTier: $newTier);
			$this->emitTierChangedEvent(
				accountId: $accountId,
				fromTierId: $currentTierId,
				toTier: $newTier
			);
			return ['from' => $currentTierId, 'to' => $newTierUuid, 'changed' => true];
		}

		                                                
		$currentTier = $this->findRuleByUuid(programmeId: $programmeId, tierId: (string)$currentTierId);
		$downgradePolicy = 'none';
		if ($currentTier !== null) {
			$downgradePolicy = (string)($currentTier['downgradePolicy'] ?? 'none');
		}

		if ($downgradePolicy === 'end_of_year' || $downgradePolicy === 'end_of_quarter') {
			                                                               
			$end = $this->endOfPeriodTimestamp(policy: $downgradePolicy);
			$this->accountService->setTier(
				accountId: $accountId,
				tierId: $currentTierId,
				tierAchievedOn: null,
				tierValidTo: $end
			);
			return ['from' => $currentTierId, 'to' => $currentTierId, 'changed' => false];
		}

		                       
		$this->handleTierDowngrade(accountId: $accountId, newTier: $newTier);
		$this->emitTierChangedEvent(
			accountId: $accountId,
			fromTierId: $currentTierId,
			toTier: $newTier
		);
		return ['from' => $currentTierId, 'to' => $newTierUuid, 'changed' => true];
	}                          

	   
                               
   
                                              
                                                           
   
                
                                                               
    
	public function handleTierUpgrade(string $accountId, array $newTier): void {
		$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
		$policy = (string)($newTier['downgradePolicy'] ?? 'none');
		$valid = null;
		if ($policy === 'end_of_year') {
			$valid = $this->endOfPeriodTimestamp(policy: $policy);
		}

		$this->accountService->setTier(
			accountId: $accountId,
			tierId: $this->extractUuid(object: $newTier),
			tierAchievedOn: $now,
			tierValidTo: $valid
		);
	}                         

	   
                                 
   
                                              
                                                                   
   
                
                                                               
    
	public function handleTierDowngrade(string $accountId, array $newTier): void {
		$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
		$this->accountService->setTier(
			accountId: $accountId,
			tierId: $this->extractUuid(object: $newTier),
			tierAchievedOn: $now,
			tierValidTo: null
		);
	}                           

	   
                                                                       
   
                                                    
   
                                 
                                                                                       
                              
    
	public function applyTierBenefits(array $tier): float {
		$benefits = $tier['benefits'] ?? [];
		if (is_array($benefits) === false) {
			return 1.0;
		}

		return (float)($benefits['pointsMultiplier'] ?? 1.0);
	}                         

	   
                                                      
   
                                              
                                                 
                                                     
   
                
                                                               
    
	public function emitTierChangedEvent(string $accountId, ?string $fromTierId, array $toTier): void {
		try {
			$event = new GenericEvent(
				null,
				[
					'type' => 'loyalty.tier.changed',
					'accountId' => $accountId,
					'fromTierId' => $fromTierId,
					'toTierId' => $this->extractUuid(object: $toTier),
					'toTierNaam' => (string)($toTier['name'] ?? ''),
					'benefits' => $toTier['benefits'] ?? [],
				]
			);
			$this->eventDispatcher->dispatchTyped($event);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: tier-changed event dispatch failed', ['exception' => $e->getMessage()]);
		}
	}                            

	   
                                                                   
   
                                                  
                                                                         
                                            
   
                                                        
    
	private function isUpgrade(string $programmeId, ?string $currentTierId, ?string $newTierId): bool {
		if ($currentTierId === null || $currentTierId === '') {
			return true;
		}

		if ($newTierId === null || $newTierId === '') {
			return false;
		}

		$current = $this->findRuleByUuid(programmeId: $programmeId, tierId: $currentTierId);
		$new = $this->findRuleByUuid(programmeId: $programmeId, tierId: $newTierId);
		if ($current === null || $new === null) {
			return false;
		}

		return (int)($new['sequence'] ?? 0) > (int)($current['sequence'] ?? 0);
	}                 

	   
                                            
   
                                                  
                                        
   
                                               
    
	private function findRuleByUuid(string $programmeId, string $tierId): ?array {
		if ($tierId === '') {
			return null;
		}

		foreach ($this->getTierRules(programmeId: $programmeId) as $rule) {
			if ($this->extractUuid(object: $rule) === $tierId) {
				return $rule;
			}
		}

		return null;
	}                      

	   
                                                     
   
                                                            
   
                                      
    
	private function endOfPeriodTimestamp(string $policy): string {
		$tz = new DateTimeZone('UTC');
		$now = new DateTimeImmutable('now', $tz);
		$year = (int)$now->format('Y');

		if ($policy === 'end_of_quarter') {
			$month = (int)$now->format('n');
			$endMonth = ((int)ceil($month / 3)) * 3;
			return (new DateTimeImmutable(sprintf('%d-%02d-01 00:00:00', $year, $endMonth), $tz))
				->modify('last day of this month 23:59:59')->format('c');
		}

		                         
		return (new DateTimeImmutable(sprintf('%d-12-31 23:59:59', $year), $tz))->format('c');
	}                            

	   
                                                                             
   
                                                      
   
                                      
    
	private function extractUuid(array $object): ?string {
		$self = $object['@self'] ?? [];
		if (is_array($self) === true && isset($self['id']) === true) {
			return (string)$self['id'];
		}

		return $object['tierId'] ?? $object['uuid'] ?? $object['id'] ?? null;
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
