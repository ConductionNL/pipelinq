<?php

   
                                    
  
                                                                  
                                                       
                                                                              
                                                                             
                                                                            
  
                    
                                 
  
                                                              
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                            
  
                                                                                                                                                                  
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                             
  
                                                                                                                                                                  
   
class ConsentMigrationService {

	   
                
   
                                                                                       
                                                                             
                                                                                                            
                                                                     
                                                                                            
                                                               
                                                                
    
	public function __construct(
		private readonly IntegriqConsentClient $integriq,
		private readonly ConsentService $messaging,
		private readonly ContainerInterface $container,
		private readonly IntegriqMarketingConsent $marketing,
		private readonly ContactAddressLookup $addresses,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}                   

	   
                                  
   
                                                                               
    
	private array $counts = ['migrated' => 0, 'skippedBounce' => 0, 'skippedNoAddress' => 0, 'refused' => 0];

	   
                                                                      
   
                                                                                                                     
   
                                                                                                                                                                   
    
	public function run(): array {
		$this->counts = ['migrated' => 0, 'skippedBounce' => 0, 'skippedNoAddress' => 0, 'refused' => 0];
		if ($this->integriq->canRecord() === false) {
			$this->logger->info('Pipelinq consent migration: integriq is not installed, nothing migrated and consent.store stays pipelinq');
			return $this->result(status: 'integriq-missing');
		}

		foreach ($this->messaging->latestPerPair(rows: $this->messaging->allRecords()) as $row) {
			$address = $this->addresses->addressFor(contactId: (string)($row['contactId'] ?? ''), channel: (string)($row['channel'] ?? ''));
			$this->hand(request: $this->messaging->changeRequestFor(row: $row, address: $address));
		}

		foreach ($this->consentRecords() as $row) {
			$request = $this->marketing->changeRequestFor(row: $row, id: $this->uuidOf(row: $row));
			if ($request === null) {
				$this->counts['skippedBounce']++;
				continue;
			}

			$this->hand(request: $request);
		}

		$status = 'done';
		if ($this->counts['refused'] === 0) {
			if ($this->integriq->isCutover() === false) {
				$this->appConfig->setValueString(Application::APP_ID, IntegriqConsentClient::CONFIG_CUTOVER_AT, (string)time());
			}

			$this->appConfig->setValueString(Application::APP_ID, IntegriqConsentClient::CONFIG_STORE, IntegriqConsentClient::STORE_INTEGRIQ);
		}

		if ($this->counts['refused'] > 0) {
			$status = 'refusals';
		}

		$result = $this->result(status: $status);
		$this->logger->info('Pipelinq consent migration into integriq', $result);
		return $result;
	}           

	   
                                                       
   
                                                           
   
                
    
	private function hand(array $request): void {
		if (trim((string)($request['address'] ?? '')) === '') {
			$this->counts['skippedNoAddress']++;
			return;
		}

		$outcome = $this->integriq->record(request: $request);
		if ($outcome['recorded'] === true) {
			$this->counts['migrated']++;
			return;
		}

		$this->counts['refused']++;
		$this->logger->warning(
			'Pipelinq consent migration: integriq refused a record, consent.store stays pipelinq',
			['legacyRef' => (string)($request['legacyRef'] ?? ''), 'code' => $outcome['code'], 'reason' => $outcome['reason']]
		);
	}            

	   
                      
   
                                                                   
   
                                                                                                                     
    
	private function result(string $status): array {
		return ['status' => $status] + $this->counts + ['store' => $this->store()];
	}              

	   
                                               
   
                                                       
    
	private function consentRecords(): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'consent_record_schema', '');
		if ($register === '') {
			$register = 'pipelinq';
		}

		if ($schema === '') {
			$schema = 'consentRecord';
		}

		try {
			$rows = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService')
				->findAll(config: ['filters' => ['register' => $register, 'schema' => $schema]]);
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq consent migration: consentRecords unreadable', ['exception' => $e->getMessage()]);
			return [];
		}

		$out = [];
		foreach ((array)$rows as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$out[] = $row;
			}
		}

		return $out;
	}                      

	   
                         
   
                                               
   
                                      
    
	private function uuidOf(array $row): string {
		foreach ([$row['uuid'] ?? null, $row['id'] ?? null, $row['@self']['uuid'] ?? null, $row['@self']['id'] ?? null] as $value) {
			if (is_scalar($value) === true && (string)$value !== '') {
				return (string)$value;
			}
		}

		return '';
	}              

	   
                     
   
                             
    
	private function store(): string {
		if ($this->integriq->isCutover() === true) {
			return IntegriqConsentClient::STORE_INTEGRIQ;
		}

		return IntegriqConsentClient::STORE_PIPELINQ;
	}             
}           
