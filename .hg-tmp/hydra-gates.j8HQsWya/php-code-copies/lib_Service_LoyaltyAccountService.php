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

   
                                            
  
                                                              
   
class LoyaltyAccountService {
	   
                
   
                                                       
                                              
                                                                                 
    
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                        
   
                                                        
                                                  
                                                                          
                                                              
   
                                                     
   
                                                                      
   
                                                                  
   
                                                                                 
                                                                                    
    
	public function createAccount(
		string $customerId,
		string $programmeId,
		bool $optIn = false,
		string $termsVersion = '1.0',
	): array {
		if ($customerId === '' || $programmeId === '') {
			throw new RuntimeException('klantId and programmeId are required.');
		}

		if ($optIn === false) {
			throw new RuntimeException('Opt-in is mandatory for loyalty account creation (REQ-LOY-010-01).');
		}

		$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');

		$payload = [
			'customerId' => $customerId,
			'programmeId' => $programmeId,
			'currentBalance' => 0,
			'lifetimePoints' => 0,
			'status' => 'active',
			'createdOn' => $now,
			'lastActivityDate' => $now,
			'optInAccepted' => true,
			'optInTimestamp' => $now,
			'optInTermsVersion' => $termsVersion,
			'anonymized' => false,
		];

		return $this->persist(payload: $payload, uuid: null);
	}                     

	   
                           
   
                                              
   
                                                                          
                                                                                       
                              
    
	public function getAccount(string $accountId): ?array {
		[$register, $schema] = $this->config();
		if ($register === '' || $schema === '' || $accountId === '') {
			return null;
		}

		try {
			$object = $this->getObjectService()->find(
				id: $accountId,
				register: $register,
				schema: $schema
			);
		} catch (\Throwable $e) {
			$this->logger->debug('Pipelinq: account lookup failed', ['exception' => $e->getMessage()]);
			return null;
		}

		if ($object === null) {
			return null;
		}

		return $this->toArray(object: $object);
	}                  

	   
                                                        
   
                                                                             
   
                                                        
                                                  
                                                                              
                                                  
   
                                                                       
   
                                                                                                                                              
                                                               
    
	public function getOrCreateAccount(
		string $customerId,
		string $programmeId,
		bool $optIn = true,
		string $termsVersion = '1.0',
	): array {
		$existing = $this->findAccountByKlantAndProgramme(customerId: $customerId, programmeId: $programmeId);
		if ($existing !== null) {
			return $existing;
		}

		return $this->createAccount(
			customerId: $customerId,
			programmeId: $programmeId,
			optIn: $optIn,
			termsVersion: $termsVersion
		);
	}                          

	   
                                                        
   
                                                        
                                                  
   
                                                           
                                                               
    
	public function findAccountByKlantAndProgramme(string $customerId, string $programmeId): ?array {
		[$register, $schema] = $this->config();
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$result = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'customerId' => $customerId,
						'programmeId' => $programmeId,
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 1,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->debug('Pipelinq: account findAll failed', ['exception' => $e->getMessage()]);
			return null;
		}

		if (is_array($result) === false || count($result) === 0) {
			return null;
		}

		return $this->toArray(object: reset($result));
	}                                      

	   
                                             
   
                                              
                                                    
   
                                                                   
                                                               
    
	public function disableAccount(string $accountId, string $reason): ?array {
		$account = $this->getAccount(accountId: $accountId);
		if ($account === null) {
			return null;
		}

		$account['status'] = 'blocked';
		$this->logger->info(
			'Pipelinq: loyalty account disabled',
			['accountId' => $accountId, 'reason' => $reason]
		);

		return $this->persist(payload: $account, uuid: $accountId);
	}                      

	  
                                                  
   
                                                                           
                                                                     
                                                                 
                                                                         
                                                                       
                                                                        
           
    

	   
                                                                   
   
                                                                         
   
                                              
                                                          
                                                                                          
                                                                       
   
                                                                   
                                                                                       
                              
    
	public function updateBalances(
		string $accountId,
		int $newCurrentBalance,
		int $lifetimeDelta,
		string $lastActivityDate,
	): ?array {
		$account = $this->getAccount(accountId: $accountId);
		if ($account === null) {
			return null;
		}

		$account['currentBalance'] = $newCurrentBalance;
		$account['lifetimePoints'] = (int)($account['lifetimePoints'] ?? 0) + max(0, $lifetimeDelta);
		$account['lastActivityDate'] = $lastActivityDate;

		return $this->persist(payload: $account, uuid: $accountId);
	}                      

	   
                                                           
   
                                              
                                                         
                                                                  
                                                         
   
                                                          
                                                               
    
	public function setTier(
		string $accountId,
		?string $tierId,
		?string $tierAchievedOn = null,
		?string $tierValidTo = null,
	): ?array {
		$account = $this->getAccount(accountId: $accountId);
		if ($account === null) {
			return null;
		}

		$account['currentTierId'] = $tierId;
		if ($tierAchievedOn !== null) {
			$account['tierAchievedOn'] = $tierAchievedOn;
		}

		if ($tierValidTo !== null) {
			$account['tierValidTo'] = $tierValidTo;
		}

		return $this->persist(payload: $account, uuid: $accountId);
	}               

	   
                                                                 
   
                                                  
                                  
                                         
   
                                                          
                                                               
    
	public function listAccountsForProgramme(string $programmeId, int $limit = 1000, int $offset = 0): array {
		[$register, $schema] = $this->config();
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
					'limit' => $limit,
					'offset' => $offset,
				]
			);
		} catch (\Throwable $e) {
			return [];
		}

		$values = [];
		if (is_array($rows) === true) {
			$values = array_values($rows);
		}

		return array_map([$this, 'toArray'], $values);
	}                                

	   
                                                                    
   
                                                          
                                              
   
                                                   
    
	private function persist(array $payload, ?string $uuid): array {
		[$register, $schema] = $this->config();
		if ($register === '' || $schema === '') {
			throw new RuntimeException('CustomerLoyaltyAccount schema is not configured.');
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

	   
                                                         
   
                                                                        
                                                                            
                                                                             
                                                                             
                                                                           
                                                                      
   
                                                                           
                                                          
    
	private function config(): array {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schemaId = $this->appConfig->getValueString(Application::APP_ID, 'klantLoyaltyAccount_schema', '');

		                                                                     
		                                                                         
		if (in_array('', [$registerId, $schemaId], true) === true) {
			$this->logger->warning(
				'Pipelinq: register/klantLoyaltyAccount_schema not configured; OpenRegister calls are refused, not run unscoped'
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
