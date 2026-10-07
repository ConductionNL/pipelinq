<?php

   
                                 
  
                                                                          
                                                                                
                                                                                
                                                                             
                                                                              
                                                               
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use OCP\AppFramework\Http;
use OCP\IL10N;

   
                                  
  
                                                                                        
                                                                           
                                                                           
                              
   
class PortalAccountService {
	   
                             
   
               
    
	private const SCHEMA = 'crmPortalAccount';

	   
                                 
   
            
    
	private const TTL_MINUTES = 60;

	   
                
   
                                                                           
                                                        
                                                    
                                                              
                                                       
                                                
    
	public function __construct(
		private PortalObjectRepository $repository,
		private PortalTokenService $tokens,
		private PortalMailService $mail,
		private PortalSessionManager $sessions,
		private PortalAuditService $audit,
		private IL10N $l10n,
	) {
	}                   

	   
                                                             
   
                                                                   
                                          
   
                
                                                                                         
                                                                            
                                                                            
                               
    
	public function requestClosure(array $account, string $tenantId): void {
		$accountId = (string)$this->repository->idOf(object: $account);
		$token = $this->tokens->issue(self::TTL_MINUTES);
		$account['closeTokenHash'] = $token['hash'];
		$account['closeExpiresAt'] = $token['expiresAt'];
		$this->repository->save(self::SCHEMA, $account, $accountId);

		$this->mail->sendTokenLink(
			(string)($account['email'] ?? ''),
			'/index.php/apps/pipelinq/portal/account-close',
			$token['plain'],
			$this->l10n->t('Confirm closing your portal account'),
			$this->l10n->t('Click the link below to confirm that you want to close your portal account. This cannot be undone.')
		);

		$this->audit->log(accountId: $accountId, tenantId: $tenantId, eventType: 'account-close', outcome: 'pending-verification');
	}                      

	   
                                                                            
   
                                                     
                                          
   
                
   
                                                        
                                                                                         
                                                                            
                                                                            
                               
    
	public function close(string $token, string $tenantId): void {
		$account = $this->repository->findOneBy(
			self::SCHEMA,
			['closeTokenHash' => $this->tokens->hash(plain: $token), 'tenantId' => $tenantId]
		);
		$tokenValid = false;
		if ($account !== null) {
			$tokenValid = $this->tokens->verify(
				plain: $token,
				storedHash: ($account['closeTokenHash'] ?? null),
				expiresAt: ($account['closeExpiresAt'] ?? null)
			);
		}

		if ($account === null || $tokenValid === false) {
			throw new PortalException(
				Http::STATUS_BAD_REQUEST,
				'invalidToken',
				'Deze bevestigings-link is ongeldig of verlopen.'
			);
		}

		$accountId = (string)$this->repository->idOf(object: $account);
		$account['status'] = 'closed';
		$account['closeTokenHash'] = null;
		$account['closeExpiresAt'] = null;
		                                                                            
		$this->repository->save(self::SCHEMA, $account, $accountId);

		$this->sessions->revokeAllForAccount(accountId: $accountId, reason: 'account-closure');
		$this->mail->send(
			(string)($account['email'] ?? ''),
			$this->l10n->t('Your portal account has been closed'),
			$this->l10n->t('Your account is closed. You can no longer sign in.')
		);
		$this->audit->log(accountId: $accountId, tenantId: $tenantId, eventType: 'account-close', outcome: 'success');
	}             
}           
