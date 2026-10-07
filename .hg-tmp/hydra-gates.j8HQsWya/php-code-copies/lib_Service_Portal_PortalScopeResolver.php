<?php

   
                                
  
                                                                                
                                                                             
                                                                               
                                                                             
                                                                                 
                                                                                
                               
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

   
                                                     
  
                                                                                        
                                                                           
                                                                           
                              
   
class PortalScopeResolver {
	   
                             
   
               
    
	private const ACCOUNT_SCHEMA = 'crmPortalAccount';

	   
                
   
                                                                           
                                                                       
    
	public function __construct(
		private PortalObjectRepository $repository,
		private PortalDelegationService $delegations,
	) {
	}                   

	   
                                                                     
   
                                                                          
                                                                        
                                                                    
   
                                                                          
                                                                   
   
                  
                                        
                                       
                                                                                                               
     
   
                                                                                         
                                                                            
                                                                            
                               
    
	public function resolve(array $account, string $scope): array {
		$ownContactIds = $this->idList(value: ($account['linkedContactId'] ?? null));
		$ownClientIds = $this->idList(value: ($account['linkedOrganisationId'] ?? null));

		$delegated = [];
		$accountId = (string)$this->repository->idOf(object: $account);
		foreach ($this->delegations->getActiveScopes(granteeAccountId: $accountId) as $grant) {
			if (in_array($scope, $grant['scopes'], true) === false) {
				continue;
			}

			$grantor = $this->repository->find(self::ACCOUNT_SCHEMA, $grant['grantorAccountId']);
			if ($grantor === null) {
				continue;
			}

			$delegated[] = [
				'from' => $grant['grantorAccountId'],
				'contactIds' => $this->idList(value: ($grantor['linkedContactId'] ?? null)),
				'clientIds' => $this->idList(value: ($grantor['linkedOrganisationId'] ?? null)),
			];
		}

		return [
			'ownContactIds' => $ownContactIds,
			'ownClientIds' => $ownClientIds,
			'delegated' => $delegated,
		];
	}               

	   
                                                                          
                                               
   
                                                                            
                                                                    
   
                                                                            
                                                                 
                                                               
   
                                                                                
   
                                                                             
                                                                             
                                                                
   
                                                                                         
                                                                            
                                                                            
                               
    
	public function classify(array $resolved, ?string $contactId, ?string $clientId): string|null|false {
		if (($contactId !== null && in_array($contactId, $resolved['ownContactIds'], true) === true)
			|| ($clientId !== null && in_array($clientId, $resolved['ownClientIds'], true) === true)
		) {
			return null;
		}

		foreach ($resolved['delegated'] as $entry) {
			if (($contactId !== null && in_array($contactId, $entry['contactIds'], true) === true)
				|| ($clientId !== null && in_array($clientId, $entry['clientIds'], true) === true)
			) {
				return $entry['from'];
			}
		}

		return false;
	}                

	   
                                                                     
   
                                         
   
                                           
    
	private function idList(mixed $value): array {
		if ($value === null || $value === '') {
			return [];
		}

		if (is_array($value) === true) {
			return array_values(array_filter(array_map('strval', $value), static fn ($id): bool => $id !== ''));
		}

		return [(string)$value];
	}              
}           
