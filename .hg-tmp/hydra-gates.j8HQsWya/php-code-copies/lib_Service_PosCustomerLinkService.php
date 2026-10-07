<?php

   
                                   
  
                                                                     
                                                                       
                                                                        
                                                                         
                
  
                                                                            
                                                                        
                                                                           
                                                                           
                                                                    
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                
                                                                
                                                                
                                                                
                                                                
                                                                
                                                                
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                                                             
  
                                                                    
                                                                        
                                                                     
                                                                        
  
                                                                              
                                                                         
                                                                    
                                                                              
                                                                               
                                                                        
                                                   
                                                                               
                                                                         
                                                                    
                                                                     
                                     
  
                                                                
   
class PosCustomerLinkService {
	   
                                                                     
   
            
    
	public const DEFAULT_SEARCH_LIMIT = 20;

	   
                                                                     
   
            
    
	public const DEFAULT_HISTORY_LIMIT = 10;

	   
                                                                    
   
            
    
	private const MAX_SEARCH_LIMIT = 100;

	   
                                                                     
   
            
    
	private const MAX_HISTORY_LIMIT = 50;

	   
                
   
                                                                                         
                                              
                                                                                         
    
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                                                        
   
                                                                         
                                                                         
                                                                       
                                                                      
                                                              
   
                                                       
                                                                     
   
                                                                    
   
                                                               
   
                                                                 
                                                                 
                                                                 
    
	public function searchCustomers(string $query, int $limit = self::DEFAULT_SEARCH_LIMIT): array {
		$needle = trim($query);
		if (mb_strlen($needle) < 2) {
			throw new OCSBadRequestException('Zoekopdracht moet minimaal 2 tekens zijn.');
		}

		$cap = max(1, min(self::MAX_SEARCH_LIMIT, $limit));

		[$register, $schema] = $this->configContact();
		$fields = $this->enabledSearchFields();

		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => ['register' => $register, 'schema' => $schema],
					'limit' => 200,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: contact search failed', ['exception' => $e->getMessage()]);
			return [];
		}

		$matches = [];
		$haystack = mb_strtolower($needle);
		if (is_array($rows) === false) {
			$rows = [];
		}

		$rows = array_values($rows);

		foreach ($rows as $row) {
			$data = $this->toArray(object: $row);
			if ($this->rowMatches(data: $data, query: $haystack, fields: $fields) === false) {
				continue;
			}

			$matches[] = $this->decorateContact(data: $data);
			if (count($matches) >= $cap) {
				break;
			}
		}

		$this->logger->info(
			'Pipelinq: POS customer search',
			[
				'query' => $needle,
				'count' => count($matches),
				'fields' => $fields,
			]
		);

		return $matches;
	}                       

	   
                                                                 
   
                                                
   
                                                            
   
                                                                                          
   
                                                                 
    
	public function getCustomer(string $contactUuid): array {
		[$register, $schema] = $this->configContact();
		$data = $this->fetchObject(id: $contactUuid, register: $register, schema: $schema);
		if ($data === null) {
			throw new OCSNotFoundException('Klant niet gevonden.');
		}

		return $this->decorateContact(data: $data);
	}                   

	   
                                                                                        
   
                                                                          
                                                                    
                                                                        
                                                                         
                                 
   
                                                          
                                                          
                                                                      
   
                                                         
   
                                                                            
                                                                                  
   
                                                                        
                                                                       
                                                                       
                                                
   
                                                                 
                                                                 
    
	public function attachCustomer(
		string $transactionId,
		string $contactUuid,
		bool $marketingConsent = false,
	): array {
		$contact = $this->getCustomer(contactUuid: $contactUuid);
		$transaction = $this->fetchTransaction(id: $transactionId);

		$status = (string)($transaction['status'] ?? 'draft');
		if (in_array($status, ['draft', 'parked'], true) === false) {
			throw new OCSBadRequestException('Klant kan alleen aan een open transactie gekoppeld worden.');
		}

		$transaction['customer'] = $contactUuid;
		$transaction['marketingConsent'] = $marketingConsent;

		$syncStatus = '';
		if ($marketingConsent === true && $this->isSyncEnabled() === true) {
			$syncStatus = $this->syncConsent(contact: $contact, consent: true);
		}

		$transaction['consentSyncStatus'] = $syncStatus;

		$saved = $this->saveTransaction(id: $transactionId, transaction: $transaction);

		$this->logger->info(
			'Pipelinq: POS customer attached',
			[
				'transactionId' => $transactionId,
				'customer' => $contactUuid,
				'consent' => $marketingConsent,
				'consentSyncState' => $syncStatus,
			]
		);

		return $saved;
	}                      

	   
                                                        
   
                                                                
                                                                     
                                                                    
                                                                        
                 
   
                                                          
   
                                                         
   
                                                                 
                                                                       
   
                                                                 
    
	public function detachCustomer(string $transactionId): array {
		$transaction = $this->fetchTransaction(id: $transactionId);

		$status = (string)($transaction['status'] ?? 'draft');
		if (in_array($status, ['draft', 'parked'], true) === false) {
			throw new OCSBadRequestException('Klant kan alleen van een open transactie ontkoppeld worden.');
		}

		$transaction['customer'] = null;
		$transaction['marketingConsent'] = false;
		$transaction['consentSyncStatus'] = '';

		return $this->saveTransaction(id: $transactionId, transaction: $transaction);
	}                      

	   
                                                                          
                                 
   
                                                                         
                                                                
                                                        
   
                                                
                                                                   
   
                                                              
   
                                                                 
    
	public function getCustomerHistory(string $contactUuid, int $limit = self::DEFAULT_HISTORY_LIMIT): array {
		$effectiveLimit = $this->historyDepth();
		if ($limit > 0) {
			$effectiveLimit = $limit;
		}

		$cap = max(1, min(self::MAX_HISTORY_LIMIT, $effectiveLimit));

		[$register, $schema] = $this->configTransaction();

		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'customer' => $contactUuid,
					],
					'limit' => 200,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: POS customer history fetch failed',
				[
					'exception' => $e->getMessage(),
					'customer' => $contactUuid,
				]
			);
			return [];
		}         

		if (is_array($rows) === false) {
			$rows = [];
		}

		$rows = array_values($rows);

		$history = [];
		foreach ($rows as $row) {
			$data = $this->toArray(object: $row);
			$status = (string)($data['status'] ?? '');
			if (in_array($status, ['draft', 'parked'], true) === true) {
				continue;
			}

			$history[] = $this->summariseTransaction(data: $data);
		}

		usort(
			$history,
			static fn (array $a, array $b): int => strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? ''))
		);

		return array_slice($history, 0, $cap);
	}                          

	   
                                                                     
   
                                                                         
                                                                          
                                                                         
                          
   
                                                                    
                                                    
   
                                                         
   
                                                                 
                                                                 
    
	public function syncConsent(array $contact, bool $consent): string {
		$contactUuid = (string)($contact['id'] ?? $contact['uuid'] ?? '');
		if ($contactUuid === '') {
			return 'failed';
		}

		if (($contact['doNotContact'] ?? false) === true) {
			$this->logger->info(
				'Pipelinq: marketing-consent sync skipped (doNotContact set)',
				['contact' => $contactUuid]
			);
			return 'skipped';
		}

		[$register, $schema] = $this->configContact();

		                                                                   
		  
		                                                                 
		                                                                   
		                                                                    
		                                                                       
		                                                                     
		  
		                                                                   
		                                                                       
		                                                                  
		                                   
		$stored = $this->fetchObject(id: $contactUuid, register: $register, schema: $schema);
		if ($stored === null) {
			$this->logger->warning(
				'Pipelinq: marketing-consent sync could not re-read the contact; not writing a partial record',
				['contact' => $contactUuid]
			);
			return 'failed';
		}

		$payload = $stored;
		unset($payload['@self'], $payload['doNotContactBadge']);
		$payload['marketingConsent'] = $consent;

		try {
			$this->getObjectService()->saveObject(
				object: $payload,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: $contactUuid
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: marketing-consent sync failed',
				[
					'contact' => $contactUuid,
					'exception' => $e->getMessage(),
				]
			);
			return 'failed';
		}

		$this->logger->info(
			'Pipelinq: marketing-consent synced to contact',
			[
				'contact' => $contactUuid,
				'consent' => $consent,
			]
		);

		return 'success';
	}                   

	   
                                                                      
   
                                                                          
                                                                          
                                            
   
                                                                  
   
                
   
                                                                                         
   
                                                                 
    
	public function assertOnAccountHasCustomer(array $transaction): void {
		$tender = (string)($transaction['tenderType'] ?? '');
		$customer = (string)($transaction['customer'] ?? '');

		if ($tender !== 'onAccount') {
			return;
		}

		                                                                    
		                                                                      
		                                                          
		if ($this->requiresCustomerForOnAccount() === false) {
			return;
		}

		if ($customer === '') {
			throw new OCSBadRequestException(
				"Klant is verplicht voor 'op rekening' transacties."
			);
		}
	}                                  

	   
                                                                             
   
                                                       
                                                                 
                                                         
   
                                         
    
	private function rowMatches(array $data, string $query, array $fields): bool {
		foreach ($fields as $field) {
			$value = mb_strtolower((string)($data[$field] ?? ''));
			if ($value !== '' && str_contains($value, $query) === true) {
				return true;
			}
		}

		return false;
	}                  

	   
                                                                             
   
                                                           
   
                                                   
    
	private function decorateContact(array $data): array {
		$self = [];
		if (is_array($data['@self'] ?? null) === true) {
			$self = $data['@self'];
		}

		$doNotContact = (bool)($data['doNotContact'] ?? false);
		$badge = '';
		if ($doNotContact === true) {
			$badge = 'Niet benaderen';
		}

		return [
			'id' => (string)($data['id'] ?? $data['uuid'] ?? ($self['id'] ?? '')),
			'name' => (string)($data['name'] ?? ''),
			'email' => (string)($data['email'] ?? ''),
			'phone' => (string)($data['phone'] ?? ''),
			'role' => (string)($data['role'] ?? ''),
			'client' => (string)($data['client'] ?? ''),
			'contactsUid' => (string)($data['contactsUid'] ?? ''),
			'marketingConsent' => (bool)($data['marketingConsent'] ?? false),
			'doNotContact' => $doNotContact,
			'doNotContactBadge' => $badge,
		];
	}                       

	   
                                                                        
   
                                                               
   
                                                            
    
	private function summariseTransaction(array $data): array {
		$self = [];
		if (is_array($data['@self'] ?? null) === true) {
			$self = $data['@self'];
		}

		return [
			'id' => (string)($data['id'] ?? $data['uuid'] ?? ($self['id'] ?? '')),
			'reference' => (string)($data['reference'] ?? ''),
			'createdAt' => (string)($data['confirmedAt'] ?? $data['settledAt'] ?? ($self['created'] ?? '')),
			'total' => (float)($data['total'] ?? 0),
			'totalTax' => (float)($data['totalTax'] ?? 0),
			'tenderType' => (string)($data['tenderType'] ?? 'cash'),
			'status' => (string)($data['status'] ?? ''),
			'itemCount' => (int)($data['itemCount'] ?? 0),
		];
	}                            

	   
                                                          
   
                                                                            
                                                
   
                                                  
   
                                                                 
    
	public function enabledSearchFields(): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, 'customerSearchFields', 'name,email,phone');
		$parts = array_filter(array_map('trim', explode(',', $raw)));

		$allowed = ['name', 'email', 'phone'];
		$filtered = array_values(array_intersect($parts, $allowed));

		if (count($filtered) === 0) {
			return $allowed;
		}

		return $filtered;
	}                           

	   
                                     
   
                                                                           
                                  
   
                                  
   
                                                                 
    
	public function historyDepth(): int {
		$value = (int)$this->appConfig->getValueString(
			Application::APP_ID,
			'customerHistoryDepth',
			(string)self::DEFAULT_HISTORY_LIMIT
		);
		if ($value <= 0) {
			return self::DEFAULT_HISTORY_LIMIT;
		}

		return min(self::MAX_HISTORY_LIMIT, $value);
	}                    

	   
                                                                    
   
                                                                            
                                                               
   
                                         
   
                                                                 
    
	public function isSyncEnabled(): bool {
		$value = $this->appConfig->getValueString(Application::APP_ID, 'enablePipelinqSync', 'true');
		return strtolower($value) !== 'false';
	}                     

	   
                                                         
   
                                                                   
   
                                                   
   
                                                                 
    
	public function requiresCustomerForOnAccount(): bool {
		$value = $this->appConfig->getValueString(Application::APP_ID, 'requireCustomerForOnAccount', 'true');
		return strtolower($value) !== 'false';
	}                                    

	   
                                                     
   
                                           
   
                                                      
   
                                                                 
    
	private function fetchTransaction(string $id): array {
		[$register, $schema] = $this->configTransaction();
		$data = $this->fetchObject(id: $id, register: $register, schema: $schema);
		if ($data === null) {
			throw new OCSNotFoundException('Transactie niet gevonden.');
		}

		return $data;
	}                        

	   
                                               
   
                                           
                                                                     
   
                                                       
    
	private function saveTransaction(string $id, array $transaction): array {
		[$register, $schema] = $this->configTransaction();

		unset($transaction['@self']);

		$saved = $this->getObjectService()->saveObject(
			object: $transaction,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: $id
		);

		return $this->toArray(object: $saved);
	}                       

	   
                                                                    
   
                                      
                                            
                                        
   
                                                                       
    
	private function fetchObject(string $id, string $register, string $schema): ?array {
		try {
			$object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
		} catch (Throwable $e) {
			return null;
		}

		if ($object === null) {
			return null;
		}

		return $this->toArray(object: $object);
	}                   

	   
                                                                
   
                                       
   
                                                        
    
	private function configContact(): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'contact_schema', '');

		if ($register === '' || $schema === '') {
			throw new OCSNotFoundException('Contact register of schema is niet geconfigureerd.');
		}

		return [$register, $schema];
	}                     

	   
                                                                       
   
                                       
   
                                                        
    
	private function configTransaction(): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'posTransaction_schema', '');

		if ($register === '' || $schema === '') {
			throw new OCSNotFoundException('POS register of schema is niet geconfigureerd.');
		}

		return [$register, $schema];
	}                         

	   
                                              
   
                                       
   
                                                 
    
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialized = $object->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$data = $object->getObject();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return (array)$object;
	}               

	   
                                                    
   
                                        
    
	private function getObjectService(): object {
		                                                                 
		                                                              
		return $this->objectService;
	}                        
}           
