<?php

   
                              
  
                                                                              
                                                                      
                                                                            
                                                                             
                                                                           
                                                                                
                                                
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\IHasher;

   
                                 
  
                                                                                    
                                                                                
                       
  
                                                                                        
                                                                           
                                                                           
                              
   
class PortalAuthService {
	   
                             
   
               
    
	private const SCHEMA = 'crmPortalAccount';

	   
                                           
   
            
    
	private const MAX_FAILED_ATTEMPTS = 5;

	   
                              
   
            
    
	private const LOCKOUT_MINUTES = 15;

	   
                
   
                                                                           
                                               
                                                              
                                                 
                                                       
                                                          
                                               
    
	public function __construct(
		private PortalObjectRepository $repository,
		private IHasher $hasher,
		private PortalSessionManager $sessions,
		private PortalMfaService $mfa,
		private PortalAuditService $audit,
		private PortalTenantService $tenant,
		private ITimeFactory $time,
	) {
	}                   

	   
                                                                          
   
                                                   
   
                                     
                                                                                         
                                                                            
                                                                            
                               
    
	public function hashPassword(string $password): string {
		return $this->hasher->hash($password);
	}                    

	   
                                                            
   
                                                                             
                                                                           
                                                                        
                                           
   
                                         
                                                   
                                                   
                                                                         
                                                
                                                               
   
                                                                                                           
   
                                                                          
                                                                                 
                                                                                         
                                                                            
                                                                            
                               
    
	public function login(
		string $email,
		string $password,
		string $tenantId,
		?string $totpCode,
		string $ipHash,
		string $userAgentHash,
	): array {
		$this->repository->requireWritable();
		$email = strtolower(trim($email));
		$account = $this->repository->findOneBy(self::SCHEMA, ['email' => $email, 'tenantId' => $tenantId]);

		                                                                            
		if ($account === null) {
			$this->audit->log(
				null,
				$tenantId,
				'login-failure',
				'denied',
				[
					'reason' => 'unknown-account',
					'ipHash' => $ipHash,
					'userAgentHash' => $userAgentHash,
				]
			);
			throw $this->invalidCredentials();
		}

		$accountId = (string)$this->repository->idOf(object: $account);

		if (($account['status'] ?? 'active') === 'closed') {
			$this->audit->log(
				accountId: $accountId,
				tenantId: $tenantId,
				eventType: 'login-failure',
				outcome: 'denied',
				details: ['reason' => 'account-closed']
			);
			throw new PortalException(
				status: Http::STATUS_UNAUTHORIZED,
				errorCode: 'accountClosed',
				message: 'Dit account is gesloten.'
			);
		}

		if ($this->isLockedOut(account: $account) === true) {
			$this->audit->log(
				accountId: $accountId,
				tenantId: $tenantId,
				eventType: 'login-failure',
				outcome: 'denied',
				details: ['reason' => 'locked-out']
			);
			throw new PortalException(
				Http::STATUS_TOO_MANY_REQUESTS,
				'rateLimited',
				'Te veel mislukte pogingen. Wacht 5 minuten alstublieft.'
			);
		}

		if ($this->hasher->verify($password, (string)($account['passwordHash'] ?? '')) === false) {
			$this->registerFailure(account: $account, accountId: $accountId, tenantId: $tenantId, ipHash: $ipHash, userAgentHash: $userAgentHash);
			throw $this->invalidCredentials();
		}

		                                                                    
		                                                           
		$this->resetFailures(account: $account);

		$mfaRequired = $this->mfaRequired(account: $account, tenantId: $tenantId);
		if ($mfaRequired === true) {
			return $this->completeMfaStage(
				account: $account,
				accountId: $accountId,
				tenantId: $tenantId,
				totpCode: $totpCode,
				ipHash: $ipHash,
				userAgentHash: $userAgentHash
			);
		}

		$created = $this->sessions->createSession(
			$accountId,
			$tenantId,
			$ipHash,
			$userAgentHash,
			$this->tenant->sessionTtlHours(tenantId: $tenantId)
		);
		$this->recordLogin(account: $account, accountId: $accountId, tenantId: $tenantId, ipHash: $ipHash, userAgentHash: $userAgentHash);

		return [
			'status' => 'authenticated',
			'mfaRequired' => false,
			'accountId' => $accountId,
			'token' => $created['token'],
			'sessionId' => (string)$this->repository->idOf(object: $created['session']),
		];
	}             

	   
                                                                           
                                                   
   
                                                            
                                            
                                          
                                                            
                                                
                                                               
   
                                                  
   
                                               
    
	private function completeMfaStage(
		array $account,
		string $accountId,
		string $tenantId,
		?string $totpCode,
		string $ipHash,
		string $userAgentHash,
	): array {
		$enrolled = ($account['mfaEnabled'] ?? false) === true && ($account['mfaSecret'] ?? '') !== '';

		                                                                          
		if ($enrolled === false) {
			return ['status' => 'mfa-enrollment-required', 'mfaRequired' => true, 'accountId' => $accountId];
		}

		if ($totpCode === null || $totpCode === '') {
			return ['status' => 'mfa-required', 'mfaRequired' => true, 'accountId' => $accountId];
		}

		if ($this->mfa->verifyCode(encryptedSecret: ($account['mfaSecret'] ?? null), code: $totpCode) === false) {
			$this->audit->log(
				accountId: $accountId,
				tenantId: $tenantId,
				eventType: 'login-failure',
				outcome: 'denied',
				details: ['reason' => 'invalid-mfa']
			);
			throw new PortalException(Http::STATUS_UNAUTHORIZED, 'invalidMfaCode', 'Ongeldige verificatiecode.');
		}

		$created = $this->sessions->createSession(
			$accountId,
			$tenantId,
			$ipHash,
			$userAgentHash,
			$this->tenant->sessionTtlHours(tenantId: $tenantId)
		);
		$this->recordLogin(account: $account, accountId: $accountId, tenantId: $tenantId, ipHash: $ipHash, userAgentHash: $userAgentHash);

		return [
			'status' => 'authenticated',
			'mfaRequired' => false,
			'accountId' => $accountId,
			'token' => $created['token'],
			'sessionId' => (string)$this->repository->idOf(object: $created['session']),
		];
	}                        

	   
                                                                  
   
                                                            
                                          
   
                                           
    
	private function mfaRequired(array $account, string $tenantId): bool {
		if (($account['mfaEnabled'] ?? false) === true) {
			return true;
		}

		return $this->tenant->mfaEnforced(tenantId: $tenantId);
	}                   

	   
                                                         
   
                                                            
   
                                      
    
	private function isLockedOut(array $account): bool {
		$until = ($account['lockedUntil'] ?? null);
		if ($until === null || $until === '') {
			return false;
		}

		$timestamp = strtotime((string)$until);
		return $timestamp !== false && $timestamp > $this->time->getTime();
	}                   

	   
                                                                              
   
                                                            
                                            
                                          
                                                
                                                               
   
                
    
	private function registerFailure(
		array $account,
		string $accountId,
		string $tenantId,
		string $ipHash,
		string $userAgentHash,
	): void {
		$attempts = ((int)($account['failedLoginAttempts'] ?? 0) + 1);
		$account['failedLoginAttempts'] = $attempts;
		if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
			$lock = $this->time->getDateTime();
			$lock->modify('+' . self::LOCKOUT_MINUTES . ' minutes');
			$account['lockedUntil'] = $lock->format(DATE_ATOM);
		}

		$this->repository->save(self::SCHEMA, $account, $accountId);
		$this->audit->log(
			$accountId,
			$tenantId,
			'login-failure',
			'denied',
			[
				'reason' => 'invalid-password',
				'failedAttemptCount' => $attempts,
				'ipHash' => $ipHash,
				'userAgentHash' => $userAgentHash,
			]
		);
	}                       

	   
                                                                             
   
                                                                             
                                                                             
                                                                
   
                                                                           
   
                
    
	private function resetFailures(array &$account): void {
		$account['failedLoginAttempts'] = 0;
		$account['lockedUntil'] = null;
	}                     

	   
                                                              
   
                                                            
                                            
                                          
                                                
                                                               
   
                
    
	private function recordLogin(
		array $account,
		string $accountId,
		string $tenantId,
		string $ipHash,
		string $userAgentHash,
	): void {
		$account['lastLoginAt'] = $this->time->getDateTime()->format(DATE_ATOM);
		$this->repository->save(self::SCHEMA, $account, $accountId);
		$this->audit->log(
			$accountId,
			$tenantId,
			'login-success',
			'success',
			[
				'ipHash' => $ipHash,
				'userAgentHash' => $userAgentHash,
			]
		);
	}                   

	   
                                                                           
                                         
   
                                      
    
	private function invalidCredentials(): PortalException {
		return new PortalException(
			Http::STATUS_UNAUTHORIZED,
			'invalidCredentials',
			'E-mailadres of wachtwoord is onjuist.'
		);
	}                          
}           
