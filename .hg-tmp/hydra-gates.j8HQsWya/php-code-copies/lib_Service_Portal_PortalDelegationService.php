<?php

   
                                    
  
                                                                            
                                                                               
                                                                          
                                                                              
                                                                                
                                                                       
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;

   
                                  
  
                                                                                        
                                                                           
                                                                           
                              
   
class PortalDelegationService {
	   
                                
   
               
    
	private const SCHEMA = 'portalDelegation';

	   
                             
   
               
    
	private const ACCOUNT_SCHEMA = 'crmPortalAccount';

	   
                                      
   
                           
    
	public const VALID_SCOPES = ['view-invoices', 'view-contracts', 'submit-requests'];

	   
                
   
                                                                           
                                                       
                                               
    
	public function __construct(
		private PortalObjectRepository $repository,
		private PortalAuditService $audit,
		private ITimeFactory $time,
	) {
	}                   

	   
                                                                          
   
                                                                             
                                          
                                                      
                                                           
                                                            
   
                                                        
   
                                                              
                                                                                         
                                                                            
                                                                            
                               
    
	public function grant(
		string $granterAccountId,
		string $tenantId,
		string $granteeEmail,
		array $scopes,
		?string $validUntil,
	): array {
		$granteeEmail = strtolower(trim($granteeEmail));
		$scopes = $this->sanitiseScopes(scopes: $scopes);

		$granter = $this->repository->find(self::ACCOUNT_SCHEMA, $granterAccountId);
		if ($granter !== null && strtolower((string)($granter['email'] ?? '')) === $granteeEmail) {
			throw new PortalException(
				Http::STATUS_UNPROCESSABLE_ENTITY,
				'cannotDelegateToSelf',
				'U kunt geen toegang aan uzelf verlenen.'
			);
		}

		$grantee = $this->repository->findOneBy(
			self::ACCOUNT_SCHEMA,
			['email' => $granteeEmail, 'tenantId' => $tenantId]
		);
		$granteeId = null;
		if ($grantee !== null) {
			$granteeId = $this->repository->idOf(object: $grantee);
		}

		$delegation = $this->repository->save(
			self::SCHEMA,
			[
				'granterAccountId' => $granterAccountId,
				'granteeAccountId' => $granteeId,
				'granteeEmail' => $granteeEmail,
				'tenantId' => $tenantId,
				'scopes' => $scopes,
				'validFrom' => $this->time->getDateTime()->format(DATE_ATOM),
				'validUntil' => $validUntil,
				'revokedAt' => null,
			]
		);

		$this->audit->log(
			$granterAccountId,
			$tenantId,
			'delegation-grant',
			'success',
			[
				'targetObjectType' => 'portalDelegation',
				'targetObjectId' => $this->repository->idOf(object: $delegation),
				'granteeEmail' => $granteeEmail,
				'scopes' => $scopes,
			]
		);

		return $delegation;
	}             

	   
                                                           
   
                                                                          
                                         
   
                                                  
                                                              
                                          
   
                
   
                                                                     
                                                                                         
                                                                            
                                                                            
                               
    
	public function revoke(string $delegationId, string $granterAccountId, string $tenantId): void {
		$delegation = $this->repository->find(self::SCHEMA, $delegationId);
		if ($delegation === null
			|| ($delegation['granterAccountId'] ?? null) !== $granterAccountId
			|| ($delegation['tenantId'] ?? null) !== $tenantId
		) {
			                                                                     
			throw new PortalException(Http::STATUS_NOT_FOUND, 'notFound', 'Niet gevonden.');
		}

		$delegation['revokedAt'] = $this->time->getDateTime()->format(DATE_ATOM);
		$this->repository->save(self::SCHEMA, $delegation, $delegationId);
		$this->audit->log(
			$granterAccountId,
			$tenantId,
			'delegation-revoke',
			'success',
			[
				'targetObjectType' => 'portalDelegation',
				'targetObjectId' => $delegationId,
			]
		);
	}              

	   
                                                                 
   
                                                   
   
                                                             
                                                                                         
                                                                            
                                                                            
                               
    
	public function listGrantedBy(string $granterAccountId): array {
		return $this->repository->findAll(self::SCHEMA, ['granterAccountId' => $granterAccountId]);
	}                     

	   
                                                                        
                                         
   
                                                   
   
                                                                                   
                                                                                         
                                                                            
                                                                            
                               
    
	public function getActiveScopes(string $granteeAccountId): array {
		$delegations = $this->repository->findAll(self::SCHEMA, ['granteeAccountId' => $granteeAccountId]);
		$active = [];
		foreach ($delegations as $delegation) {
			if ($this->isActive(delegation: $delegation) === false) {
				continue;
			}

			$active[] = [
				'grantorAccountId' => (string)($delegation['granterAccountId'] ?? ''),
				'scopes' => $this->sanitiseScopes(scopes: ($delegation['scopes'] ?? [])),
			];
		}

		return $active;
	}                       

	   
                                                                              
   
                                                           
   
                                  
    
	private function isActive(array $delegation): bool {
		$revokedAt = ($delegation['revokedAt'] ?? null);
		if ($revokedAt !== null && $revokedAt !== '') {
			return false;
		}

		$now = $this->time->getTime();

		$from = ($delegation['validFrom'] ?? null);
		if ($from !== null && $from !== '' && strtotime((string)$from) > $now) {
			return false;
		}

		$until = ($delegation['validUntil'] ?? null);
		if ($until !== null && $until !== '' && strtotime((string)$until) < $now) {
			return false;
		}

		return true;
	}                

	   
                                                     
   
                                        
   
                                                
    
	private function sanitiseScopes(mixed $scopes): array {
		if (is_array($scopes) === false) {
			return [];
		}

		$clean = [];
		foreach ($scopes as $scope) {
			if (is_string($scope) === true && in_array($scope, self::VALID_SCOPES, true) === true) {
				$clean[$scope] = $scope;
			}
		}

		return array_values($clean);
	}                      
}           
