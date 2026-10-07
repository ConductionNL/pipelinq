<?php

   
                                 
  
                                                                            
                                                                             
                                                                       
                                                                                
                                                                               
                                  
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;

   
                                 
  
                                                                                        
                                                                           
                                                                           
                              
   
class PortalSessionManager {
	   
                             
   
               
    
	private const SCHEMA = 'crmPortalSession';

	   
                                 
   
            
    
	public const DEFAULT_TTL_HOURS = 8;

	   
                
   
                                                                           
                                                        
                                               
    
	public function __construct(
		private PortalObjectRepository $repository,
		private PortalTokenService $tokens,
		private ITimeFactory $time,
	) {
	}                   

	   
                                                                           
                                                                         
   
                                            
                                          
                                                
                                                               
                                              
                                                                  
   
                                                                                     
   
                                                                                   
                                                                                         
                                                                            
                                                                            
                               
    
	public function createSession(
		string $accountId,
		string $tenantId,
		string $ipHash,
		string $userAgentHash,
		int $ttlHours = self::DEFAULT_TTL_HOURS,
		bool $mfaPending = false,
	): array {
		$plain = $this->tokens->randomToken();
		$expiry = $this->time->getDateTime();
		$expiry->modify('+' . max(1, $ttlHours) . ' hours');

		$session = $this->repository->save(
			self::SCHEMA,
			[
				'accountId' => $accountId,
				'tenantId' => $tenantId,
				'tokenHash' => $this->tokens->hash(plain: $plain),
				'ipHash' => $ipHash,
				'userAgentHash' => $userAgentHash,
				'expiresAt' => $expiry->format(DATE_ATOM),
				'revoked' => false,
				'mfaPending' => $mfaPending,
			]
		);

		return ['token' => $plain, 'session' => $session];
	}                     

	   
                                                      
   
                                                                        
                                                                      
                                                                          
                                                 
   
                                                            
                                                   
   
                                                                 
                                                                                         
                                                                            
                                                                            
                               
    
	public function validateSession(?string $token, string $tenantId): ?array {
		if ($token === null || $token === '') {
			return null;
		}

		$session = $this->repository->findOneBy(self::SCHEMA, ['tokenHash' => $this->tokens->hash(plain: $token)]);
		if ($session === null) {
			return null;
		}

		if (($session['revoked'] ?? false) === true) {
			return null;
		}

		if (($session['mfaPending'] ?? false) === true) {
			return null;
		}

		if (($session['tenantId'] ?? null) !== $tenantId) {
			return null;
		}

		if ($this->tokens->isUnexpired(expiresAt: ($session['expiresAt'] ?? null)) === false) {
			return null;
		}

		return $session;
	}                       

	  
                                                     
   
                                                                 
                                                                          
                                                                          
                                                                            
                                                                      
                                                                       
                                                    
    

	   
                                    
   
                                            
                                                   
   
                                                                               
                                                                                         
                                                                            
                                                                            
                               
    
	public function extendSession(string $sessionId, int $ttlHours = self::DEFAULT_TTL_HOURS): ?array {
		$session = $this->repository->find(self::SCHEMA, $sessionId);
		if ($session === null || ($session['revoked'] ?? false) === true) {
			return null;
		}

		$expiry = $this->time->getDateTime();
		$expiry->modify('+' . max(1, $ttlHours) . ' hours');
		$session['expiresAt'] = $expiry->format(DATE_ATOM);

		return $this->repository->save(self::SCHEMA, $session, $sessionId);
	}                     

	   
                                                                            
                                                                           
                                                                           
   
                                            
                                                   
   
                                                     
   
                                                                  
                                                                                         
                                                                            
                                                                            
                               
    
	public function extendSessionOrThrow(string $sessionId, int $ttlHours = self::DEFAULT_TTL_HOURS): array {
		$updated = $this->extendSession(sessionId: $sessionId, ttlHours: $ttlHours);
		if ($updated === null) {
			throw new PortalException(
				status: Http::STATUS_UNAUTHORIZED,
				errorCode: 'unauthenticated',
				message: 'Niet ingelogd.'
			);
		}

		return $updated;
	}                            

	   
                            
   
                                            
                                                
   
                
                                                                                         
                                                                            
                                                                            
                               
    
	public function revokeSession(string $sessionId, string $reason): void {
		$session = $this->repository->find(self::SCHEMA, $sessionId);
		if ($session === null) {
			return;
		}

		$session['revoked'] = true;
		$session['revokedAt'] = $this->time->getDateTime()->format(DATE_ATOM);
		$session['revokedReason'] = $reason;
		$this->repository->save(self::SCHEMA, $session, $sessionId);
	}                     

	   
                                                                        
   
                                            
                                                
   
                                               
                                                                                         
                                                                            
                                                                            
                               
    
	public function revokeAllForAccount(string $accountId, string $reason): int {
		$sessions = $this->repository->findAll(self::SCHEMA, ['accountId' => $accountId]);
		$count = 0;
		foreach ($sessions as $session) {
			if (($session['revoked'] ?? false) === true) {
				continue;
			}

			$id = $this->repository->idOf(object: $session);
			if ($id === null) {
				continue;
			}

			$this->revokeSession(sessionId: $id, reason: $reason);
			$count++;
		}

		return $count;
	}                           
}           
