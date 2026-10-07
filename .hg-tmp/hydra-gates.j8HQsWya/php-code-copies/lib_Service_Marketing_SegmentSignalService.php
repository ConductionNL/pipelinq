<?php

   
                                 
  
                                                                           
                                                                    
                                      
  
                                                                           
                                                                           
                                                                         
                                                                          
                                                                        
                                                                          
                                                                     
                                                                       
  
                                                                       
                                                                            
                                                                             
                                                                        
                                                                 
  
                    
                                           
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                                                                                              
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

use OCP\AppFramework\Utility\ITimeFactory;

   
                                                                          
  
                                                                         
                                                                             
                                                              
  
                                                                                                                                                                                              
   
class SegmentSignalService {

	   
                                                                        
   
               
    
	public const FIELD_REVENUE = 'shillinqRecognisedRevenue';

	   
                                           
   
               
    
	public const FIELD_VALUE_TIER = 'shillinqValueTier';

	   
                                                          
   
               
    
	public const FIELD_MONTHS_SINCE_INVOICE = 'shillinqMonthsSinceLastInvoice';

	   
                                                          
   
               
    
	public const FIELD_PRODUCTS = 'shillinqPurchasedProducts';

	   
                                                          
   
               
    
	public const FIELD_SERVICES = 'shillinqPurchasedServices';

	   
                                                      
   
               
    
	public const FIELD_DUNNING = 'shillinqDunningState';

	   
                                                          
   
               
    
	public const FIELD_RENEWAL_DAYS = 'pipelinqContractRenewalDays';

	   
                                                            
   
               
    
	public const FIELD_STALLED_DAYS = 'pipelinqStalledLeadDays';

	   
                                                                      
   
                           
    
	public const SUPPRESSING_DUNNING_STATES = ['overdue', 'written-off'];

	   
                                                           
   
               
    
	public const DUNNING_UNKNOWN = 'unknown';

	   
                                                    
   
            
    
	private const DAY = 86400;

	   
                                                   
   
                           
    
	private const OPEN_LEAD_STATUSES = ['new', 'open', 'in-progress', 'qualified', 'proposal', 'negotiation'];

	   
                                                                  
   
                                            
    
	private array $memo = [];

	   
                                                             
   
                                   
    
	private ?array $catalogue = null;

	   
                
   
                                                                  
                                                                           
                                    
   
                                                                                                                                                                                               
    
	public function __construct(
		private ListObjectStore $store,
		private BookkeepingSignals $bookkeeping,
		private ITimeFactory $time,
	) {
	}                   

	   
                                                                        
   
                                                                        
                                                                      
                                                           
   
                                                                                                  
                                                           
   
                                                                                                                                                                                               
    
	public function catalogue(): array {
		return [
			self::FIELD_REVENUE => [
				'type' => 'number',
				'title' => 'Recognised revenue',
				'source' => 'shillinq',
				'description' => 'Paid invoices for this customer over the signal window.',
			],
			self::FIELD_VALUE_TIER => [
				'type' => 'string',
				'title' => 'Value tier',
				'source' => 'shillinq',
				'description' => 'top, mid, low or none, from recognised revenue against the tier thresholds.',
			],
			self::FIELD_MONTHS_SINCE_INVOICE => [
				'type' => 'integer',
				'title' => 'Months since the last invoice',
				'source' => 'shillinq',
				'description' => 'Whole months since the most recent invoice that left the draft stage.',
			],
			self::FIELD_PRODUCTS => [
				'type' => 'array',
				'title' => 'Purchased products',
				'source' => 'shillinq',
				'description' => 'Catalogue products named on this customer\'s invoice lines.',
			],
			self::FIELD_SERVICES => [
				'type' => 'array',
				'title' => 'Purchased services',
				'source' => 'shillinq',
				'description' => 'Catalogue services named on this customer\'s invoice lines.',
			],
			self::FIELD_DUNNING => [
				'type' => 'string',
				'title' => 'Dunning state',
				'source' => 'shillinq',
				'description' => 'current, overdue, disputed, written-off or unknown.',
			],
			self::FIELD_RENEWAL_DAYS => [
				'type' => 'integer',
				'title' => 'Days to contract renewal',
				'source' => 'pipelinq',
				'description' => 'Days until the nearest contract of this customer ends.',
			],
			self::FIELD_STALLED_DAYS => [
				'type' => 'integer',
				'title' => 'Days a lead has been stalled',
				'source' => 'pipelinq',
				'description' => 'Days the longest-waiting open lead has sat in its current stage.',
			],
		];
	}                 

	   
                                                         
   
                                                                      
                                                                        
                                                                        
                          
   
                                                                                                
   
                                                                                                                                                                                    
    
	public function schemaProperties(): array {
		$properties = [];
		foreach ($this->catalogue() as $field => $entry) {
			$properties[$field] = [
				'type' => $entry['type'],
				'title' => $entry['title'],
				'description' => $entry['description'],
			];
		}

		return $properties;
	}                        

	   
                                                                      
   
                                               
   
                                                         
   
                                                                                                                                                                                    
    
	public function isSignalField(string $field): bool {
		return array_key_exists($field, $this->catalogue());
	}                     

	   
                                                                  
   
                                                                          
   
                                                                                                                                                            
    
	public function availability(): array {
		$available = $this->bookkeeping->isAvailable();
		$reason = 'shillinq_not_installed';
		if ($available === true) {
			$reason = '';
		}

		return ['shillinq' => $available, 'reason' => $reason];
	}                    

	   
                                                            
   
                                                                    
                                                                        
                                                                        
   
                                          
                                                                      
   
                                                           
   
                                                                                                                                                            
    
	public function resolves(string $field, array $entity): bool {
		return ($this->valueFor(field: $field, entity: $entity) !== null);
	}                

	   
                                                          
   
                                          
                                                                      
   
                                                              
   
                                                                                                                                                                                               
    
	public function valueFor(string $field, array $entity): mixed {
		if ($this->isSignalField(field: $field) === false) {
			return null;
		}

		$clientId = $this->clientIdOf(entity: $entity);
		if ($clientId === '') {
			return null;
		}

		$signals = $this->signalsFor(clientId: $clientId);
		return ($signals[$field] ?? null);
	}                

	   
                                                                       
   
                                                                         
                                                                          
                                                                         
              
   
                                                   
   
                                                                
   
                                                                                                                                                                                               
    
	public function signalsFor(string $clientId): array {
		if (array_key_exists($clientId, $this->memo) === true) {
			return $this->memo[$clientId];
		}

		$client = $this->store->find(schemaSlug: $this->store->schemaSlug('client_schema', 'client'), id: $clientId);
		$signals = array_merge(
			$this->bookkeeping->forCustomer(
				customerRef: (string)(($client ?? [])['shillinqOrganisationRef'] ?? ''),
				catalogue: $this->productCatalogue()
			),
			$this->crmSignals(clientId: $clientId)
		);

		$this->memo[$clientId] = $signals;
		return $signals;
	}                  

	   
                                                                
   
                                                                         
                                                                        
                                                                       
                                                                   
                                                                    
   
                                            
   
                                                                           
   
                                                                                                                                                                 
    
	public function dunningStateForContact(string $contactId): ?string {
		$contactId = trim($contactId);
		if ($contactId === '') {
			return null;
		}

		$contact = $this->store->find(
			schemaSlug: $this->store->schemaSlug('contact_schema', 'contact'),
			id: $contactId
		);
		if ($contact === null) {
			return null;
		}

		$state = $this->valueFor(field: self::FIELD_DUNNING, entity: $contact);
		if (is_string($state) === false || $state === self::DUNNING_UNKNOWN) {
			return null;
		}

		return $state;
	}                              

	   
                                 
   
                                                                     
                                                                   
   
                
   
                                                                                                                                                                                               
    
	public function forget(): void {
		$this->memo = [];
		$this->catalogue = null;
	}              

	   
                                            
   
                                                   
   
                                                                     
    
	private function crmSignals(string $clientId): array {
		return [
			self::FIELD_RENEWAL_DAYS => $this->renewalDays(clientId: $clientId),
			self::FIELD_STALLED_DAYS => $this->stalledDays(clientId: $clientId),
		];
	}                  

	   
                                                        
   
                                                                         
                                                                        
                                                              
   
                                                   
   
                                                                        
    
	private function renewalDays(string $clientId): ?int {
		$rows = $this->store->findAll(
			schemaSlug: $this->store->schemaSlug('contract_schema', 'salesContract'),
			filters: ['clientRef' => $clientId]
		);

		$nearest = null;
		foreach ($rows as $row) {
			if ((string)($row['clientRef'] ?? '') !== $clientId) {
				continue;
			}

			$days = $this->daysUntil(value: ($row['endDate'] ?? null));
			if ($days === null) {
				continue;
			}

			if ($nearest === null || $days < $nearest) {
				$nearest = $days;
			}
		}

		return $nearest;
	}                   

	   
                                                                           
   
                                                   
   
                                                                          
    
	private function stalledDays(string $clientId): ?int {
		$rows = $this->store->findAll(
			schemaSlug: $this->store->schemaSlug('lead_schema', 'lead'),
			filters: ['client' => $clientId]
		);

		$longest = null;
		foreach ($rows as $row) {
			if ((string)($row['client'] ?? '') !== $clientId) {
				continue;
			}

			$status = strtolower(trim((string)($row['status'] ?? '')));
			if ($status !== '' && in_array($status, self::OPEN_LEAD_STATUSES, true) === false) {
				continue;
			}

			$days = $this->daysUntil(value: ($row['stageEnteredAt'] ?? null));
			if ($days === null) {
				continue;
			}

			$waited = -$days;
			if ($longest === null || $waited > $longest) {
				$longest = $waited;
			}
		}

		return $longest;
	}                   

	   
                                                                          
   
                                                                        
                                                                           
                                                                         
                                                             
   
                                                   
   
                                                                         
    
	private function daysUntil(mixed $value): ?int {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		$stamp = strtotime(substr(trim($value), 0, 10) . ' 00:00:00');
		if ($stamp === false) {
			return null;
		}

		$today = strtotime(date('Y-m-d', $this->time->getTime()) . ' 00:00:00');
		if ($today === false) {
			return null;
		}

		return (int)round((($stamp - $today) / self::DAY));
	}                 

	   
                                                                      
   
                                                
    
	private function productCatalogue(): array {
		if ($this->catalogue !== null) {
			return $this->catalogue;
		}

		$catalogue = [];
		foreach ($this->store->findAll(schemaSlug: $this->store->schemaSlug('product_schema', 'product')) as $row) {
			$name = strtolower(trim((string)($row['name'] ?? '')));
			if ($name === '') {
				continue;
			}

			$catalogue[$name] = 'product';
			if (strtolower(trim((string)($row['type'] ?? 'product'))) === 'service') {
				$catalogue[$name] = 'service';
			}
		}

		$this->catalogue = $catalogue;
		return $catalogue;
	}                        

	   
                                                                      
   
                                                                       
                                                                        
               
   
                                                                      
   
                                                           
    
	private function clientIdOf(array $entity): string {
		$link = ($entity['client'] ?? null);
		if (is_scalar($link) === true && trim((string)$link) !== '') {
			return trim((string)$link);
		}

		if (is_array($link) === true) {
			$nested = $this->store->idOf($link);
			if ($nested !== '') {
				return $nested;
			}
		}

		return $this->store->idOf($entity);
	}                  

}           
