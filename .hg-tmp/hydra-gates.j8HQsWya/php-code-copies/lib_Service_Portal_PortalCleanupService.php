<?php

   
                                 
  
                                                                            
                                                                                
                                                                           
                                                                             
                                                                                
                                                                    
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

   
                                                                          
  
                                                                                 
                                                                                     
  
                                                                                        
                                                                           
                                                                           
                              
   
class PortalCleanupService {
	   
                             
   
               
    
	private const ACCOUNT_SCHEMA = 'crmPortalAccount';

	   
                
   
                                                                           
                                                               
                                                       
    
	public function __construct(
		private PortalObjectRepository $repository,
		private MainRegisterReader $reader,
		private PortalAuditService $audit,
	) {
	}                   

	   
                                                
   
                                                     
                                                                                         
                                                                            
                                                                            
                               
    
	public function run(): int {
		$pseudonymised = 0;
		foreach ($this->repository->findAll(self::ACCOUNT_SCHEMA, ['status' => 'closed']) as $account) {
			if ($this->processClosedAccount(account: $account) === true) {
				$pseudonymised++;
			}
		}

		return $pseudonymised;
	}           

	   
                                                                     
                                 
   
                                                            
   
                                                       
                                                                                         
                                                                            
                                                                            
                               
    
	public function processClosedAccount(array $account): bool {
		$contactId = ($account['linkedContactId'] ?? null);
		if ($contactId === null || $contactId === '') {
			return false;
		}

		if ($this->hasRetentionObligation(contactId: (string)$contactId) === true) {
			return false;
		}

		$contact = $this->reader->find('contact', (string)$contactId);
		if ($contact === null) {
			                                     
			$account['linkedContactId'] = null;
			$this->repository->save(self::ACCOUNT_SCHEMA, $account, $this->repository->idOf(object: $account));
			return false;
		}

		$contact['name'] = '#' . substr(hash('sha256', (string)($contact['name'] ?? '')), 0, 16);
		$contact['email'] = '#' . substr(hash('sha256', (string)($contact['email'] ?? '')), 0, 16);
		$contact['phone'] = null;
		$this->reader->save('contact', $contact, $this->idOf(object: $contact));

		$accountId = (string)$this->repository->idOf(object: $account);
		$account['linkedContactId'] = null;
		$this->repository->save(self::ACCOUNT_SCHEMA, $account, $accountId);

		$this->audit->log(
			$accountId,
			(string)($account['tenantId'] ?? ''),
			'account-pseudonymised',
			'success',
			['targetObjectType' => 'contact', 'targetObjectId' => (string)$contactId]
		);

		return true;
	}                            

	   
                                                                     
                                                                   
   
                                                                           
   
                                             
    
	private function hasRetentionObligation(string $contactId): bool {
		                                                                         
		foreach ($this->reader->findAll('posTransaction') as $invoice) {
			$status = (string)($invoice['status'] ?? '');
			$owner = ($invoice['client'] ?? null);
			$ownerId = $owner;
			if (is_array($owner) === true) {
				$ownerId = ($owner['id'] ?? $owner['uuid'] ?? null);
			}

			if ((string)$ownerId === $contactId && in_array($status, ['settled', 'refunded'], true) === false) {
				return true;
			}
		}

		return false;
	}                              

	   
                                                            
   
                                                   
   
                               
    
	private function idOf(array $object): ?string {
		$self = ($object['@self'] ?? null);
		if (is_array($self) === true) {
			$id = ($self['id'] ?? $self['uuid'] ?? null);
			if ($id !== null) {
				return (string)$id;
			}
		}

		$id = ($object['id'] ?? $object['uuid'] ?? null);
		if ($id === null) {
			return null;
		}

		return (string)$id;
	}            
}           
