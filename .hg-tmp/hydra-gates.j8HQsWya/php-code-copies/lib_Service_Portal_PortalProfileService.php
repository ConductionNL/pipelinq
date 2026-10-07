<?php

   
                                 
  
                                                                             
                                                                                
                                                                                
                                                                                
                                                                               
                                                            
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use OCP\IL10N;

   
                                                                
  
                                                                                
                                                          
  
                                                                                        
                                                                           
                                                                           
                              
   
class PortalProfileService {
	   
                             
   
               
    
	private const SCHEMA = 'crmPortalAccount';

	   
                                             
   
                           
    
	private const EDITABLE = ['displayName', 'phone', 'locale', 'address', 'jobTitle'];

	   
                                            
   
            
    
	private const EMAIL_TTL_MINUTES = 30;

	   
                
   
                                                                           
                                                        
                                                    
                                                       
                                                
    
	public function __construct(
		private PortalObjectRepository $repository,
		private PortalTokenService $tokens,
		private PortalMailService $mail,
		private PortalAuditService $audit,
		private IL10N $l10n,
	) {
	}                   

	   
                                                         
   
                                                            
   
                                                  
                                                                                         
                                                                            
                                                                            
                               
    
	public function present(array $account): array {
		return [
			'id' => $this->repository->idOf(object: $account),
			'email' => ($account['email'] ?? null),
			'pendingEmail' => ($account['pendingEmail'] ?? null),
			'displayName' => ($account['displayName'] ?? null),
			'phone' => ($account['phone'] ?? null),
			'address' => ($account['address'] ?? null),
			'locale' => ($account['locale'] ?? 'nl'),
			'jobTitle' => ($account['jobTitle'] ?? null),
			'accountType' => ($account['accountType'] ?? 'b2c'),
		];
	}               

	   
                                                                             
                                                      
   
                                                            
                                          
                                                               
   
                                                          
                                                           
    
	public function update(array $account, string $tenantId, array $changes): array {
		$accountId = (string)$this->repository->idOf(object: $account);

		foreach (self::EDITABLE as $field) {
			if (array_key_exists($field, $changes) === false) {
				continue;
			}

			$new = $changes[$field];
			$old = ($account[$field] ?? null);
			if ($old === $new) {
				continue;
			}

			$account[$field] = $new;
			$this->audit->log(
				$accountId,
				$tenantId,
				'profile-update',
				'success',
				[
					'fieldName' => $field,
					'previousValue' => $old,
					'newValue' => $new,
				]
			);
		}             

		if (array_key_exists('email', $changes) === true) {
			$this->startEmailChange(account: $account, accountId: $accountId, tenantId: $tenantId, newEmail: (string)$changes['email']);
		}

		$saved = $this->repository->save(self::SCHEMA, $account, $accountId);
		return $this->present(account: $saved);
	}              

	   
                                                                       
   
                                                          
                                          
   
                                                              
                                                                                         
                                                                            
                                                                            
                               
    
	public function verifyEmail(string $token, string $tenantId): bool {
		$account = $this->repository->findOneBy(
			self::SCHEMA,
			['emailVerifyTokenHash' => $this->tokens->hash(plain: $token), 'tenantId' => $tenantId]
		);
		if ($account === null) {
			return false;
		}

		if ($this->tokens->verify(
			$token,
			($account['emailVerifyTokenHash'] ?? null),
			($account['emailVerifyExpiresAt'] ?? null)
		) === false
		) {
			return false;
		}

		$accountId = (string)$this->repository->idOf(object: $account);
		$newEmail = strtolower((string)($account['pendingEmail'] ?? ''));
		$account['email'] = $newEmail;
		$account['pendingEmail'] = null;
		$account['emailVerifyTokenHash'] = null;
		$account['emailVerifyExpiresAt'] = null;
		$this->repository->save(self::SCHEMA, $account, $accountId);

		$this->audit->log(
			accountId: $accountId,
			tenantId: $tenantId,
			eventType: 'email-change',
			outcome: 'success',
			details: ['newValue' => $newEmail]
		);
		return true;
	}                   

	   
                                                                             
                                                         
   
                                                                                      
                                            
                                          
                                                    
   
                
    
	private function startEmailChange(array &$account, string $accountId, string $tenantId, string $newEmail): void {
		$newEmail = strtolower(trim($newEmail));
		if ($newEmail === '' || $newEmail === strtolower((string)($account['email'] ?? ''))) {
			return;
		}

		$token = $this->tokens->issue(self::EMAIL_TTL_MINUTES);
		$account['pendingEmail'] = $newEmail;
		$account['emailVerifyTokenHash'] = $token['hash'];
		$account['emailVerifyExpiresAt'] = $token['expiresAt'];

		$this->mail->sendTokenLink(
			$newEmail,
			'/index.php/apps/pipelinq/portal/verify-email',
			$token['plain'],
			$this->l10n->t('Confirm your new email address'),
			$this->l10n->t('Click the link below to confirm this email address for your portal account.')
		);

		$this->audit->log(
			$accountId,
			$tenantId,
			'profile-update',
			'pending-verification',
			[
				'fieldName' => 'email',
				'newValue' => $newEmail,
			]
		);
	}                        
}           
