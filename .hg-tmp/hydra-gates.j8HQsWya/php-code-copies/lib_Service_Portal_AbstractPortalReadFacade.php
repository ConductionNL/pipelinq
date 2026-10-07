<?php

   
                                     
  
                                                                      
                                                                              
                                                                              
                                                                       
                                                                                
                                                                               
                      
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

   
                                                   
  
                                                                                        
                                                                           
                                                                           
                              
   
abstract class AbstractPortalReadFacade {
	   
                
   
                                                               
                                                         
    
	public function __construct(
		protected MainRegisterReader $reader,
		protected PortalScopeResolver $scope,
	) {
	}                   

	   
                                                                                       
   
                                  
    
	abstract protected function schemaKey(): string;

	   
                                                              
   
                                                  
    
	abstract protected function delegationScope(): string;

	   
                                                               
   
                                                       
   
                                                                
    
	abstract protected function ownerIds(array $object): array;

	   
                                                    
   
                                                       
                                                                      
   
                                                
    
	abstract protected function present(array $object, ?string $delegatedFrom): array;

	   
                                                          
   
                                                       
   
                                
    
	abstract protected function sortKey(array $object): string;

	   
                                                                   
   
                                                                   
                                      
                                      
   
                                                                                               
                                                                                         
                                                                            
                                                                            
                               
    
	public function getForAccount(array $account, int $page = 1, int $perPage = 10): array {
		$page = max(1, $page);
		$perPage = min(100, max(1, $perPage));

		$resolved = $this->scope->resolve($account, $this->delegationScope());
		$rows = [];

		foreach ($this->reader->findAll($this->schemaKey()) as $object) {
			[$contactId, $clientId] = $this->ownerIds(object: $object);
			$classification = $this->scope->classify(resolved: $resolved, contactId: $contactId, clientId: $clientId);
			if ($classification === false) {
				continue;
			}

			$rows[] = [
				'sort' => $this->sortKey(object: $object),
				'present' => $this->present(object: $object, delegatedFrom: $classification),
			];
		}

		usort($rows, static fn (array $left, array $right): int => strcmp($right['sort'], $left['sort']));

		$total = count($rows);
		$slice = array_slice($rows, (($page - 1) * $perPage), $perPage);

		return [
			'total' => $total,
			'page' => $page,
			'perPage' => $perPage,
			'items' => array_map(static fn (array $row): array => $row['present'], $slice),
		];
	}                     

	   
                                                                  
   
                                                                            
                                                                         
                                                
   
                                                                   
                                    
   
                                                       
                                                                                         
                                                                            
                                                                            
                               
    
	public function getOneForAccount(array $account, string $id): ?array {
		$object = $this->reader->find($this->schemaKey(), $id);
		if ($object === null) {
			return null;
		}

		$resolved = $this->scope->resolve($account, $this->delegationScope());
		[$contactId, $clientId] = $this->ownerIds(object: $object);
		$classification = $this->scope->classify(resolved: $resolved, contactId: $contactId, clientId: $clientId);
		if ($classification === false) {
			return null;
		}

		return $this->present(object: $object, delegatedFrom: $classification);
	}                        

	   
                                                                        
   
                                      
   
                                        
    
	protected function readId(mixed $value): ?string {
		if ($value === null || $value === '') {
			return null;
		}

		if (is_array($value) === true) {
			$id = ($value['id'] ?? $value['uuid'] ?? null);
			if ($id === null) {
				return null;
			}

			return (string)$id;
		}

		return (string)$value;
	}              

	   
                                                                            
   
                                                       
   
                                        
    
	protected function objectId(array $object): ?string {
		$self = ($object['@self'] ?? []);
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
