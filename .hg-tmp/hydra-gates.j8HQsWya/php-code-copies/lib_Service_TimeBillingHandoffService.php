<?php

   
                                      
  
                                                                
                                                                           
                                                                             
                                                            
                                                                           
                                                                          
                                                                         
                                                                          
                                                       
  
                                                                         
                                                                             
                                                                    
                                                                             
                                                                                
                                         
  
                                                                              
                                                                             
                                                                          
                                                                          
                                                                      
                                                                      
                                                                         
                                                                 
                                                                             
                                               
                                                                             
                                                                                     
                                                                        
                                                                             
                                                                      
                                                                  
                                                        
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                                    
                                               
  
                         
  
                                                 
  
                                                      
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                          
  
                                                                               
                                                                        
                                                              
  
                                                      
   
class TimeBillingHandoffService {
	   
                                                                           
                                                                          
                                                                
   
               
    
	private const BATCH_NAMESPACE_UUID = '6ba7b811-9dad-11d1-80b4-00c04fd430c8';

	   
                                                                        
   
               
    
	private const BILLING_MODEL = 't_and_m';

	   
                                                                   
                                                                 
   
               
    
	private const SHILLINQ_INTAKE_SERVICE = 'OCA\\Shillinq\\Service\\TimeIntakeService';

	   
                                                                        
                                                                                                               
   
               
    
	private const SHILLINQ_ADMINISTRATION_CONTEXT_SERVICE = 'OCA\\Shillinq\\Service\\AdministrationContextService';

	   
                
   
                                                
                                                                                        
                                                                       
                                                                                                    
                                                                       
                                              
    
	public function __construct(
		private IAppConfig $appConfig,
		private IAppManager $appManager,
		private IUserSession $userSession,
		private ContainerInterface $container,
		private BillingHandoffNotifier $notifier,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                                    
   
                                                                        
                                                                          
                                                                            
              
   
                                                          
   
                                                       
    
	public function handoffAvailable(): bool {
		$flag = $this->appConfig->getValueString(Application::APP_ID, 'shillinq_time_intake_enabled', 'false');
		if ($flag !== 'true') {
			return false;
		}

		try {
			return $this->appManager->isEnabledForUser('shillinq');
		} catch (Throwable $e) {
			return false;
		}
	}                        

	   
                                                                      
   
                                                                  
                                                                          
                                                                        
                                                                     
   
                                            
                                                                           
                                                                       
   
                                                                       
   
                                                       
    
	public function selectBatch(string $clientId, string $periodStart, string $periodEnd): array {
		if ($clientId === '') {
			return [];
		}

		$objectService = $this->objectService();
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'timeEntry_schema', '');
		if ($objectService === null || $register === '' || $schema === '') {
			return [];
		}

		try {
			$rows = $objectService->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
					],
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'TimeBillingHandoffService: failed to read time entries',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		$selected = [];
		foreach (($rows ?? []) as $row) {
			$data = $this->toArray(value: $row);
			if ((string)($data['status'] ?? '') !== 'approved') {
				continue;
			}

			if ((string)($data['client'] ?? '') !== $clientId) {
				continue;
			}

			if ((string)($data['billingInvoiceId'] ?? '') !== '') {
				continue;
			}

			$date = (string)($data['date'] ?? '');
			if ($date === '' || $date < $periodStart || $date > $periodEnd) {
				continue;
			}

			$selected[] = $data;
		}             

		usort(
			$selected,
			static fn (array $a, array $b): int => strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''))
		);

		return $selected;
	}                   

	   
                                                                        
   
                                                        
                                                                      
                                                                       
                                                                                                         
                                                                       
                                                                                                          
                                                                                                    
   
                                            
                                                                           
                                                                       
   
                                             
   
                                                       
    
	public function sendToBilling(string $clientId, string $periodStart, string $periodEnd): array {
		if ($this->handoffAvailable() === false) {
			return ['status' => 'not-available'];
		}

		$entries = $this->selectBatch(clientId: $clientId, periodStart: $periodStart, periodEnd: $periodEnd);
		if (empty($entries) === true) {
			return ['status' => 'empty'];
		}

		return $this->dispatchBatch(
			clientId: $clientId,
			periodStart: $periodStart,
			periodEnd: $periodEnd,
			entries: $entries
		);
	}                     

	   
                                                               
   
                                                                     
                                                                   
                                                                          
                                                                          
                                                                       
   
                                                                   
   
                                                       
    
	public function notifyPendingFailures(): array {
		$objectService = $this->objectService();
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'timeEntry_schema', '');
		if ($objectService === null || $register === '' || $schema === '') {
			return [];
		}

		try {
			$rows = $objectService->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
					],
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'TimeBillingHandoffService: failed to read failed batches',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		$byBatch = [];
		foreach (($rows ?? []) as $row) {
			$data = $this->toArray(value: $row);
			if ((string)($data['billingSyncStatus'] ?? '') !== 'failed') {
				continue;
			}

			$batchId = (string)($data['billingBatchId'] ?? '');
			$clientId = (string)($data['client'] ?? '');
			if ($batchId === '' || $clientId === '') {
				continue;
			}

			if (isset($byBatch[$batchId]) === false) {
				$byBatch[$batchId] = $clientId;
			}
		}             

		$notified = [];
		foreach ($byBatch as $batchId => $clientId) {
			$client = $this->fetchClient(clientId: $clientId);
			$clientName = (string)($client['name'] ?? $clientId);
			$this->notifier->notifyFailure(clientName: $clientName, clientId: $clientId, batchId: $batchId);
			$notified[] = $batchId;
		}

		return $notified;
	}                             

	   
                                                                 
   
                                            
                                                     
                                                 
                                                                               
   
                                             
    
	private function dispatchBatch(string $clientId, string $periodStart, string $periodEnd, array $entries): array {
		$client = $this->fetchClient(clientId: $clientId);
		$clientName = (string)($client['name'] ?? $clientId);
		$organisationRef = (string)($client['shillinqOrganisationRef'] ?? '');

		$batchId = $this->computeBatchId(
			clientId: $clientId,
			periodStart: $periodStart,
			periodEnd: $periodEnd,
			entries: $entries
		);

		                                                                   
		                                                                   
		                                    
		foreach ($entries as &$entry) {
			$entry['billingSyncStatus'] = 'pending';
			$entry['billingBatchId'] = $batchId;
			$this->persistEntry(entry: $entry);
		}

		unset($entry);

		$intakeService = $this->resolveIntakeService();
		if ($intakeService === null) {
			$this->markFailed(entries: $entries);
			$this->notifier->notifyFailure(clientName: $clientName, clientId: $clientId, batchId: $batchId);
			return [
				'status' => 'failed',
				'batchId' => $batchId,
				'message' => 'The shillinq time-intake service could not be resolved.',
			];
		}

		$payload = $this->buildPayload(
			batchId: $batchId,
			organisationRef: $organisationRef,
			periodStart: $periodStart,
			periodEnd: $periodEnd,
			entries: $entries
		);

		try {
			$result = $intakeService->ingest(
				administrationId: $this->resolveAdministrationId(),
				personId: $this->actingUserId(),
				body: $payload
			);
		} catch (\InvalidArgumentException $e) {
			                                                              
			                                                            
			                                                              
			$this->markFailed(entries: $entries);
			$this->notifier->notifyFailure(clientName: $clientName, clientId: $clientId, batchId: $batchId);
			return ['status' => 'failed', 'batchId' => $batchId, 'message' => $e->getMessage()];
		} catch (\RuntimeException $e) {
			$message = $e->getMessage();
			if (str_starts_with($message, 'Conflict:') === true) {
				                                                         
				                                                           
				return ['status' => 'conflict', 'batchId' => $batchId, 'message' => $message];
			}

			                                                                   
			                                                             
			                                                               
			return [
				'status' => 'unmapped',
				'batchId' => $batchId,
				'message' => sprintf('%s (client: %s)', $message, $clientName),
			];
		} catch (Throwable $e) {
			$this->markFailed(entries: $entries);
			$this->notifier->notifyFailure(clientName: $clientName, clientId: $clientId, batchId: $batchId);
			return ['status' => 'failed', 'batchId' => $batchId, 'message' => $e->getMessage()];
		}         

		$invoiceId = (string)($result['invoiceId'] ?? '');
		$invoiceNumber = (string)($result['invoiceNumber'] ?? '');
		$duplicated = (bool)($result['duplicated'] ?? false);

		foreach ($entries as $entry) {
			$entry['billingSyncStatus'] = 'synced';
			$entry['billingInvoiceId'] = $invoiceId;
			$this->persistEntry(entry: $entry);
		}

		return [
			'status' => 'synced',
			'batchId' => $batchId,
			'invoiceId' => $invoiceId,
			'invoiceNumber' => $invoiceNumber,
			'duplicated' => $duplicated,
			'entryCount' => count($entries),
		];
	}                     

	   
                                                            
   
                                                      
                                                                                
                                                     
                                                 
                                                                               
   
                                                         
    
	private function buildPayload(
		string $batchId,
		string $organisationRef,
		string $periodStart,
		string $periodEnd,
		array $entries,
	): array {
		$currency = $this->appConfig->getValueString(Application::APP_ID, 'currency', 'EUR');

		$entryPayloads = [];
		foreach ($entries as $entry) {
			$projectRef = (string)($entry['project'] ?? '');
			if ($projectRef === '') {
				$projectRef = null;
			}

			$entryPayloads[] = [
				'externalId' => (string)($entry['id'] ?? ''),
				'date' => (string)($entry['date'] ?? ''),
				'minutes' => (int)round(((float)($entry['hours'] ?? 0)) * 60),
				'description' => (string)($entry['description'] ?? ($entry['title'] ?? '')),
				'hourlyRate' => null,
				'rateRef' => null,
				'projectRef' => $projectRef,
			];
		}

		return [
			'batchId' => $batchId,
			'organisationRef' => $organisationRef,
			'currency' => $currency,
			'billingModel' => self::BILLING_MODEL,
			'period' => [
				'start' => $periodStart,
				'end' => $periodEnd,
			],
			                                                         
			                                                                  
			'rateCardId' => null,
			                                                                 
			                                                           
			'projectRef' => null,
			'notes' => '',
			'entries' => $entryPayloads,
			                                                                 
			'expenses' => [],
		];
	}                    

	   
                                              
   
                                            
                                                     
                                                 
                                                                               
   
                                              
    
	private function computeBatchId(string $clientId, string $periodStart, string $periodEnd, array $entries): string {
		$entryIds = array_map(
			static fn (array $entry): string => (string)($entry['id'] ?? ''),
			$entries
		);
		sort($entryIds);

		$name = sprintf(
			'pipelinq:billing-batch:%s:%s:%s:%s',
			$clientId,
			$periodStart,
			$periodEnd,
			implode(',', $entryIds)
		);

		return $this->uuidV5(namespace: self::BATCH_NAMESPACE_UUID, name: $name);
	}                      

	   
                                                                      
                                                        
   
                                                
                                                              
   
                                     
    
	private function uuidV5(string $namespace, string $name): string {
		$namespaceHex = str_replace(['-', '{', '}'], '', $namespace);
		$namespaceBin = '';
		for ($i = 0, $length = strlen($namespaceHex); $i < $length; $i += 2) {
			$namespaceBin .= chr((int)hexdec($namespaceHex[$i] . $namespaceHex[$i + 1]));
		}

		$hash = sha1($namespaceBin . $name);

		return sprintf(
			'%08s-%04s-%04x-%04x-%12s',
			substr($hash, 0, 8),
			substr($hash, 8, 4),
			(hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x5000,
			(hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000,
			substr($hash, 20, 12)
		);
	}              

	   
                                                        
   
                                                                       
   
                
    
	private function markFailed(array $entries): void {
		foreach ($entries as $entry) {
			$entry['billingSyncStatus'] = 'failed';
			$this->persistEntry(entry: $entry);
		}
	}                  

	   
                                                      
   
                                                                   
   
                
    
	private function persistEntry(array $entry): void {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'timeEntry_schema', '');
		$uuid = (string)($entry['id'] ?? '');
		if ($register === '' || $schema === '' || $uuid === '') {
			return;
		}

		$objectService = $this->objectService();
		if ($objectService === null) {
			return;
		}

		try {
			$objectService->saveObject(
				object: $entry,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: $uuid
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'TimeBillingHandoffService: failed to persist time entry billing status',
				['exception' => $e->getMessage(), 'uuid' => $uuid]
			);
		}         
	}                    

	   
                                  
   
                                            
   
                                                                              
    
	private function fetchClient(string $clientId): ?array {
		if ($clientId === '') {
			return null;
		}

		$objectService = $this->objectService();
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'client_schema', '');
		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		try {
			$entity = $objectService->find(id: $clientId, register: $register, schema: $schema);
		} catch (Throwable $e) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $this->toArray(value: $entity);
	}                   

	   
                                                   
   
                                                              
    
	private function objectService(): ?object {
		try {
			$service = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($service) === false) {
			return null;
		}

		return $service;
	}                     

	   
                                                                     
   
                                                                       
                                                             
   
                                                                     
    
	private function resolveIntakeService(): ?object {
		try {
			$service = $this->container->get(self::SHILLINQ_INTAKE_SERVICE);
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($service) === false || method_exists($service, 'ingest') === false) {
			return null;
		}

		return $service;
	}                            

	   
                                                                        
                                                                                      
                            
   
                                                                    
                                                             
    
	private function resolveAdministrationId(): string {
		try {
			$contextService = $this->container->get(self::SHILLINQ_ADMINISTRATION_CONTEXT_SERVICE);
			if (is_object($contextService) === true && method_exists($contextService, 'buildContext') === true) {
				$context = $contextService->buildContext();
				$candidate = (string)($context['activeAdministrationId'] ?? '');
				if ($candidate !== '') {
					return $candidate;
				}
			}
		} catch (Throwable $e) {
			                                     
		}

		return 'default';
	}                               

	   
                                                                        
                                                              
   
                                       
    
	private function actingUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();
	}                    

	   
                                                                
   
                                      
   
                                                        
    
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialised = $value->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($value) === true && method_exists($value, 'getObject') === true) {
			$data = $value->getObject();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return (array)$value;
	}               
}           
