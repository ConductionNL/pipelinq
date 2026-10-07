<?php

   
                                 
  
                                                                            
                                
  
                                                                              
                                                                          
                                                                              
                                                                           
                                                                          
                                                                              
                                                                         
                                                                        
                                   
  
                                                                          
                                                                             
                                                                          
                                                                        
  
                                                                           
                                                                               
                                                                              
                                                                              
                                  
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCA\Pipelinq\Service\Social\BrokerCredentialReader;
use OCA\Pipelinq\Service\Social\SocialAdapterRegistry;
use OCA\Pipelinq\Service\Social\SocialBrokerGateway;
use OCP\IGroupManager;

   
                                                           
  
                                                                                                                                          
  
                                                                            
                                                                          
                                                                           
                                                  
                                                                               
                                                                               
                                                         
   
class SocialAccountService {
	   
                                                            
   
               
    
	public const SCHEMA = 'socialAccount';

	   
                                                     
   
               
    
	public const SCHEMA_CONFIG_KEY = 'social_account_schema';

	   
                                                        
   
               
    
	public const STATUS_PENDING = 'pending';

	   
                                       
   
               
    
	public const STATUS_ACTIVE = 'active';

	   
                                                     
   
               
    
	public const STATUS_RELINK_NEEDED = 'relink_needed';

	   
                                                
   
               
    
	public const STATUS_DISABLED = 'disabled';

	   
                                                             
   
               
    
	public const STATUS_NOT_CONFIGURED = 'not_configured';

	   
                                                                        
   
               
    
	public const KIND_PERSON = 'person';

	   
                                                                            
   
               
    
	public const SUBJECT_RELINK_NEEDED = 'social_relink_needed';

	   
                                                                            
                                                                    
   
                           
    
	public const CLIENT_WRITABLE = [
		'network',
		'kind',
		'handle',
		'displayName',
		'profileUrl',
		'externalAccountId',
		'instanceBaseUrl',
		'clientId',
		'publishMode',
	];

	   
                
   
                                                                      
                                                                                    
                                                                                            
                                                                                            
                                                                                                       
                                                                                                
   
                
    
	public function __construct(
		private readonly ListObjectStore $store,
		private readonly SocialAdapterRegistry $registry,
		private readonly BrokerCredentialReader $credentials,
		private readonly ObjectOwnerAccessPolicy $policy,
		private readonly IGroupManager $groupManager,
		private readonly NotificationService $notifications,
	) {
	}                   

	   
                                                 
   
                                                                                                                         
                                                       
   
                                                                                                                                                  
    
	public function listAccounts(): array {
		return [
			'data' => $this->store->findAll(schemaSlug: $this->schema()),
			'readiness' => $this->registry->readiness(),
		];
	}                    

	   
                                                                           
   
                                                                             
                                                                         
                                                                   
   
                                                                 
   
                                                                                                                                           
    
	public function activeAccounts(): array {
		$out = [];
		foreach ($this->store->findAll(schemaSlug: $this->schema()) as $row) {
			if (($row['active'] ?? true) !== true) {
				continue;
			}

			$out[] = $row;
		}

		return $out;
	}                      

	   
                         
   
                                                      
   
                                                           
   
                                                                                                                                           
    
	public function getAccount(string $accountId): ?array {
		return $this->store->find(schemaSlug: $this->schema(), id: $accountId);
	}                  

	   
                                                                       
   
                                                                         
                                                                       
                                                    
   
                                  
                                                     
   
                                                    
   
                                                                                                                                                
    
	public function mayActOn(string $uid, array $account): bool {
		if (trim($uid) === '') {
			return false;
		}

		if ((string)($account['kind'] ?? '') !== self::KIND_PERSON) {
			return $this->policy->isPrivileged(uid: $uid);
		}

		if (trim((string)($account['ownerUserId'] ?? '')) === $uid) {
			return true;
		}

		return $this->groupManager->isAdmin($uid);
	}                

	   
                                                        
   
                                                             
                                                                  
   
                                                                                                   
   
                                                                                                                                                
    
	public function createAccount(array $payload, string $uid): array {
		$network = trim((string)($payload['network'] ?? ''));
		if ($this->registry->forNetwork(network: $network) === null) {
			return ['error' => 'Pick a network Pipelinq can publish to.'];
		}

		if (trim((string)($payload['handle'] ?? '')) === '') {
			return ['error' => 'An account needs the handle its own network shows.'];
		}

		$account = $this->clientFields(payload: $payload);
		$account['ownerUserId'] = $uid;
		$account['credentialRef'] = '';
		$account['active'] = true;
		$account['followerCount'] = 0;

		$readiness = $this->registry->readinessFor(network: $network);
		$account['status'] = self::STATUS_PENDING;
		$account['statusReason'] = '';
		if ($readiness['state'] === SocialBrokerGateway::NOT_CONFIGURED) {
			$account['status'] = self::STATUS_NOT_CONFIGURED;
			$account['statusReason'] = $readiness['reason'];
		}

		$saved = $this->store->save(schemaSlug: $this->schema(), payload: $account);
		if ($saved === null) {
			return ['error' => 'The account could not be saved.'];
		}

		return ['account' => $saved];
	}                     

	   
                                                                        
   
                                                                           
                                                                            
                                                         
   
                                                    
                                  
                                                                     
   
                                                                                              
   
                                                                                                                                           
    
	public function connectRequest(string $accountId, string $uid, string $returnUrl): array {
		$account = $this->getAccount(accountId: $accountId);
		if ($account === null) {
			return ['error' => 'That account does not exist.'];
		}

		if ($this->mayActOn(uid: $uid, account: $account) === false) {
			return ['error' => 'You may not connect this account.'];
		}

		$network = (string)($account['network'] ?? '');
		$readiness = $this->registry->readinessFor(network: $network);
		if ($readiness['state'] === SocialBrokerGateway::NOT_CONFIGURED) {
			                                                                   
			                                                                 
			$this->stampStatus(
				accountId: $accountId,
				account: $account,
				status: self::STATUS_NOT_CONFIGURED,
				reason: $readiness['reason'],
			);

			return ['error' => $readiness['reason']];
		}

		$scope = 'organisation';
		if ((string)($account['kind'] ?? '') === self::KIND_PERSON) {
			$scope = 'personal';
		}

		$connect = [
			'provider' => $this->registry->providerFor(network: $network),
			'scope' => $scope,
			'allowedApps' => [SocialBrokerGateway::APP_ID],
			'name' => trim((string)($account['displayName'] ?? $account['handle'] ?? $network)),
			'returnUrl' => $returnUrl,
			'readiness' => $readiness,
		];

		return ['connect' => array_merge($connect, $this->optionalConnectFields(account: $account))];
	}                      

	   
                                                                         
                                                                           
                                         
   
                                                                           
                                                                         
                                 
   
                                                     
   
                                                       
   
                                                                                                                                           
    
	private function optionalConnectFields(array $account): array {
		$out = [];

		$scopes = ($account['scopes'] ?? []);
		if (is_array($scopes) === true && $scopes !== []) {
			$out['scopes'] = array_values(array_map('strval', $scopes));
		}

		foreach (['instanceBaseUrl' => 'instanceBaseUrl', 'clientId' => 'clientId', 'credentialRef' => 'credentialId'] as $from => $to) {
			$value = trim((string)($account[$from] ?? ''));
			if ($value !== '') {
				$out[$to] = $value;
			}
		}

		return $out;
	}                             

	   
                                                          
   
                                                                     
                                                                          
                                                                     
   
                                         
                                                                   
                                                              
   
                                                                                                   
   
                                                                                                                                           
    
	public function attachCredential(string $accountId, string $uid, array $payload): array {
		$account = $this->getAccount(accountId: $accountId);
		if ($account === null) {
			return ['error' => 'That account does not exist.'];
		}

		if ($this->mayActOn(uid: $uid, account: $account) === false) {
			return ['error' => 'You may not connect this account.'];
		}

		$provider = $this->registry->providerFor(network: (string)($account['network'] ?? ''));
		$credential = $this->resolveCredential(payload: $payload, provider: $provider, uid: $uid);
		if ($credential === null) {
			return ['error' => 'No connection was found for this account. Start the connection again.'];
		}

		if ((string)($credential['owner'] ?? '') !== $uid && $this->groupManager->isAdmin($uid) === false) {
			return ['error' => 'That connection belongs to somebody else.'];
		}

		$update = $this->withCredential(account: $account, credential: $credential);
		$saved = $this->store->save(schemaSlug: $this->schema(), payload: $update, id: $accountId);
		if ($saved === null) {
			return ['error' => 'The connection could not be saved.'];
		}

		return ['account' => $saved];
	}                        

	   
                                                                                
   
                                                                         
                                                                         
                              
   
                                         
                                  
   
                                                                                                   
   
                                                                                                                                           
    
	public function revoke(string $accountId, string $uid): array {
		$account = $this->getAccount(accountId: $accountId);
		if ($account === null) {
			return ['error' => 'That account does not exist.'];
		}

		if ($this->mayActOn(uid: $uid, account: $account) === false) {
			return ['error' => 'You may not revoke this account.'];
		}

		$update = $account;
		$update['credentialRef'] = '';
		$update['status'] = self::STATUS_DISABLED;
		$update['statusReason'] = 'The connection was revoked.';
		$update['active'] = false;
		$update['expiresAt'] = '';

		$saved = $this->store->save(schemaSlug: $this->schema(), payload: $update, id: $accountId);
		if ($saved === null) {
			return ['error' => 'The connection could not be revoked.'];
		}

		return ['account' => $saved];
	}              

	   
                                                                             
   
                                                                          
                                                                
   
                                         
                                                    
   
                
   
                                                                                                                                        
    
	public function markRelinkNeeded(string $accountId, string $reason): void {
		$account = $this->getAccount(accountId: $accountId);
		if ($account === null) {
			return;
		}

		if ((string)($account['status'] ?? '') === self::STATUS_RELINK_NEEDED) {
			                                                                    
			                                                           
			return;
		}

		$this->stampStatus(
			accountId: $accountId,
			account: $account,
			status: self::STATUS_RELINK_NEEDED,
			reason: $reason,
		);

		$owner = trim((string)($account['ownerUserId'] ?? ''));
		if ($owner === '') {
			return;
		}

		$this->notifications->sendNotification(
			userId: $owner,
			subject: self::SUBJECT_RELINK_NEEDED,
			parameters: [
				'handle' => (string)($account['handle'] ?? ''),
				'network' => (string)($account['network'] ?? ''),
				'title' => (string)($account['displayName'] ?? $account['handle'] ?? ''),
			],
			objectType: 'socialAccount',
			objectId: $accountId,
		);
	}                        

	   
                                         
   
                                         
                                                         
   
                
   
                                                                                                                                                
    
	public function recordFollowerCount(string $accountId, int $followers): void {
		$account = $this->getAccount(accountId: $accountId);
		if ($account === null) {
			return;
		}

		$account['followerCount'] = max(0, $followers);
		$account['followerCountAt'] = gmdate('Y-m-d\TH:i:s\Z');
		$this->store->save(schemaSlug: $this->schema(), payload: $account, id: $accountId);
	}                           

	   
                                                                        
   
                                                                       
                                                                             
   
                                         
   
                                                                   
   
                                                                                                                                           
    
	public function syncStatus(string $accountId): ?array {
		$account = $this->getAccount(accountId: $accountId);
		if ($account === null) {
			return null;
		}

		$ref = trim((string)($account['credentialRef'] ?? ''));
		if ($ref === '') {
			return $account;
		}

		$status = $this->credentials->status(credentialRef: $ref);
		if ($status === '' || $status === (string)($account['status'] ?? '')) {
			return $account;
		}

		return $this->stampStatus(accountId: $accountId, account: $account, status: $status, reason: '');
	}                  

	   
                                                      
   
                                   
   
                                                                                                                                           
    
	public function schema(): string {
		return $this->store->schemaSlug(configKey: self::SCHEMA_CONFIG_KEY, default: self::SCHEMA);
	}              

	   
                                                                
   
                                                                        
                                                                           
                                                                            
                                                    
   
                                                             
                                                                      
   
                                                      
   
                                                                                                                                           
    
	private function withCredential(array $account, array $credential): array {
		$account['credentialRef'] = (string)($credential['id'] ?? '');
		$account['status'] = (string)($credential['status'] ?? self::STATUS_ACTIVE);
		$account['statusReason'] = '';
		$account['connectedAt'] = gmdate('Y-m-d\TH:i:s\Z');
		$account['expiresAt'] = (string)($credential['expiresAt'] ?? '');
		$account['active'] = true;

		$identity = [
			'accountId' => 'externalAccountId',
			'accountHandle' => 'handle',
			'accountDisplayName' => 'displayName',
		];
		foreach ($identity as $from => $to) {
			$value = trim((string)($credential[$from] ?? ''));
			if ($value !== '') {
				$account[$to] = $value;
			}
		}

		$scopes = ($credential['scopes'] ?? null);
		if (is_array($scopes) === true) {
			$account['scopes'] = array_values(array_map('strval', $scopes));
		}

		return $account;
	}                      

	   
                                          
   
                                                              
   
                                                     
    
	private function clientFields(array $payload): array {
		$out = [];
		foreach (self::CLIENT_WRITABLE as $field) {
			if (array_key_exists($field, $payload) === true) {
				$out[$field] = $payload[$field];
			}
		}

		return $out;
	}                    

	   
                                                                            
                                                                        
             
   
                                                              
                                                
                                  
   
                                                                       
    
	private function resolveCredential(array $payload, string $provider, string $uid): ?array {
		$named = trim((string)($payload['credentialRef'] ?? ''));
		if ($named !== '') {
			return $this->credentials->read(credentialRef: $named);
		}

		return $this->credentials->findLatest(provider: $provider, ownerUid: $uid);
	}                         

	   
                                                  
   
                                         
                                                             
                                              
                                              
   
                                                                   
    
	private function stampStatus(string $accountId, array $account, string $status, string $reason): ?array {
		$account['status'] = $status;
		$account['statusReason'] = $reason;

		return $this->store->save(schemaSlug: $this->schema(), payload: $account, id: $accountId);
	}                   
}           
