<?php

   
                                 
  
                                                                      
                                                                              
                                                                         
                                                                               
                                                                  
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\Security\IHasher;

   
                                  
  
                                                                            
                                                                              
  
                                                                                        
                                                                           
                                                                           
                              
   
class PasswordResetService {
	   
                             
   
               
    
	private const SCHEMA = 'crmPortalAccount';

	   
                               
   
            
    
	private const TTL_MINUTES = 30;

	   
                                                                       
   
            
    
	private const MIN_PASSWORD_LENGTH = 10;

	   
                
   
                                                                           
                                                        
                                               
                                                    
                                                              
                                                       
                                                
    
	public function __construct(
		private PortalObjectRepository $repository,
		private PortalTokenService $tokens,
		private IHasher $hasher,
		private PortalMailService $mail,
		private PortalSessionManager $sessions,
		private PortalAuditService $audit,
		private IL10N $l10n,
	) {
	}                   

	   
                                                                            
                                                                          
                                                                 
   
                                           
                                          
   
                
                                                                                         
                                                                            
                                                                            
                               
    
	public function requestReset(string $email, string $tenantId): void {
		                                                                     
		                                                                       
		$this->repository->requireWritable();

		$email = strtolower(trim($email));
		$account = $this->repository->findOneBy(self::SCHEMA, ['email' => $email, 'tenantId' => $tenantId]);
		if ($account === null || ($account['status'] ?? 'active') === 'closed') {
			return;
		}

		$token = $this->tokens->issue(self::TTL_MINUTES);
		$account['passwordResetTokenHash'] = $token['hash'];
		$account['passwordResetExpiresAt'] = $token['expiresAt'];
		                                                                  
		$this->repository->save(self::SCHEMA, $account, $this->repository->idOf(object: $account));

		                                                                         
		                                                             
		$this->mail->sendTokenLink(
			$email,
			'/index.php/apps/pipelinq/portal/password-reset',
			$token['plain'],
			$this->l10n->t('Reset your portal password'),
			$this->l10n->t('Click the link below to choose a new password. This link expires in 30 minutes.')
		);
	}                    

	   
                                                                            
   
                                                   
                                                          
                                          
   
                
   
                                                                           
                                                                                         
                                                                            
                                                                            
                               
    
	public function resetPassword(string $token, string $newPassword, string $tenantId): void {
		$this->repository->requireWritable();

		if (strlen($newPassword) < self::MIN_PASSWORD_LENGTH) {
			throw new PortalException(
				Http::STATUS_UNPROCESSABLE_ENTITY,
				'weakPassword',
				'Wachtwoord moet minimaal 10 tekens bevatten.'
			);
		}

		$account = $this->findByResetToken(token: $token, tenantId: $tenantId);
		if ($account === null) {
			throw new PortalException(
				Http::STATUS_BAD_REQUEST,
				'invalidToken',
				'Deze herstel-link is ongeldig of verlopen.'
			);
		}

		$accountId = (string)$this->repository->idOf(object: $account);
		$account['passwordHash'] = $this->hasher->hash($newPassword);
		$account['passwordResetTokenHash'] = null;
		$account['passwordResetExpiresAt'] = null;
		$account['failedLoginAttempts'] = 0;
		$account['lockedUntil'] = null;
		$this->repository->save(self::SCHEMA, $account, $accountId);

		                                                       
		$this->sessions->revokeAllForAccount(accountId: $accountId, reason: 'password-reset');
		$this->audit->log(accountId: $accountId, tenantId: $tenantId, eventType: 'password-reset', outcome: 'success');
	}                     

	   
                                                                            
   
                                             
                                          
   
                                                           
    
	private function findByResetToken(string $token, string $tenantId): ?array {
		$candidate = $this->repository->findOneBy(
			self::SCHEMA,
			['passwordResetTokenHash' => $this->tokens->hash(plain: $token), 'tenantId' => $tenantId]
		);
		if ($candidate === null) {
			return null;
		}

		if ($this->tokens->verify(
			$token,
			($candidate['passwordResetTokenHash'] ?? null),
			($candidate['passwordResetExpiresAt'] ?? null)
		) === false
		) {
			return null;
		}

		return $candidate;
	}                        
}           
