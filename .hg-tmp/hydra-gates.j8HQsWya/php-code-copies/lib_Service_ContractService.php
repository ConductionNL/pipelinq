<?php

   
                            
  
                                                                         
                                                                                               
                                                         
                                                   
  
                                                                       
                                                                                  
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                    
                                    
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Lifecycle\SchemaLifecycleGraph;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                              
  
                                                                                                   
                                                        
   
class ContractService {
	   
                                                                                    
   
               
    
	private const CONTRACT_SCHEMA_SLUG = 'salesContract';

	   
                                                                               
                                                                      
                                                                            
   
                 
    
	public const TERMINAL_STATES = ['renewed', 'churned', 'cancelled'];

	   
                                        
   
                                                                              
                                             
   
                 
    
	public const VALID_STATES = ['draft', 'active', 'expiring', 'renewed', 'churned', 'cancelled'];

	   
                
   
                                                
                                                                                 
                                              
                                                                                                
    
	public function __construct(
		private IAppConfig $appConfig,
		private ContainerInterface $container,
		private LoggerInterface $logger,
		private SchemaLifecycleGraph $lifecycleGraph = new SchemaLifecycleGraph(),
	) {
	}                   

	   
                                                                             
   
                                                                       
                                                                               
                                                                                
                                                                                
                                           
   
                                                                          
    
	private function transitionGraph(): array {
		return $this->lifecycleGraph->adjacencyFor(schemaSlug: self::CONTRACT_SCHEMA_SLUG);
	}                       

	   
                                                                     
   
                                                                             
                                                                                 
                                                                  
   
                                                   
    
	private function terminalStates(): array {
		$lifecycle = $this->lifecycleGraph->lifecycleFor(schemaSlug: self::CONTRACT_SCHEMA_SLUG);
		if ($lifecycle === null) {
			return self::TERMINAL_STATES;
		}

		$terminal = ($lifecycle['terminal'] ?? ($lifecycle['final'] ?? null));
		if (is_array($terminal) === false || $terminal === []) {
			return self::TERMINAL_STATES;
		}

		return array_map(static fn ($value): string => (string)$value, $terminal);
	}                      

	   
                                          
   
                                               
                                                                                      
                                                                            
                                                                             
                                                           
                                        
   
                                                                     
                                                 
                                                                   
   
                
   
                                                                        
   
                                                                                                                                            
                                                                                                           
                                                                                                           
   
                                                                                                    
    
	public function assertTransitionAllowed(array $contract, string $newStatus, bool $byEngine = false): void {
		if (in_array($newStatus, self::VALID_STATES, true) === false) {
			throw new InvalidArgumentException(sprintf('Unknown contract status "%s".', $newStatus));
		}

		$current = (string)($contract['status'] ?? 'draft');

		if (in_array($current, $this->terminalStates(), true) === true) {
			throw new InvalidArgumentException(
				sprintf('Contract is in terminal state "%s" and cannot transition.', $current)
			);
		}

		if ($newStatus === 'expiring' && $byEngine === false) {
			throw new InvalidArgumentException('Status "expiring" may only be set by the renewal engine.');
		}

		if ($newStatus === 'renewed' && ((string)($contract['renewalLeadOutcome'] ?? '')) !== 'won') {
			throw new InvalidArgumentException('Status "renewed" requires a won renewal lead.');
		}

		if ($newStatus === 'cancelled' && trim((string)($contract['cancellationReason'] ?? '')) === '') {
			throw new InvalidArgumentException('Cancelling a contract requires a cancellationReason.');
		}

		                                                                    
		                                                                   
		                                                                         
		                                                                          
		                                                                      
		                                                                       
		                                                                           
		                                   
		$graph = $this->transitionGraph();
		if ($graph !== [] && $current !== $newStatus && isset($graph[$current]) === true) {
			if (in_array($newStatus, $graph[$current], true) === false) {
				throw new InvalidArgumentException(
					sprintf('Transition from "%s" to "%s" is not allowed.', $current, $newStatus)
				);
			}
		}
	}                               

	   
                                                              
   
                                                                           
                                                                              
   
                                                                               
                                                         
   
                                            
   
                                                                                                    
    
	public function generateContractNumber(array $existing, ?int $year = null): string {
		$year ??= (int)date('Y');
		$prefix = sprintf('C-%d-', $year);

		$maxSeq = 0;
		$taken = [];
		foreach ($existing as $contract) {
			$number = (string)($contract['contractNumber'] ?? '');
			$taken[$number] = true;
			if (str_starts_with($number, $prefix) === true) {
				$seq = (int)substr($number, strlen($prefix));
				if ($seq > $maxSeq) {
					$maxSeq = $seq;
				}
			}
		}

		do {
			$maxSeq++;
			$candidate = sprintf('%s%03d', $prefix, $maxSeq);
		} while (isset($taken[$candidate]) === true);

		return $candidate;
	}                              

	   
                                                                
   
                                                            
                                                              
   
                                                                 
   
                                                            
   
                                                                                              
    
	public function buildSuccessorDraft(array $predecessor): array {
		$successorStart = '';
		$endDate = (string)($predecessor['endDate'] ?? '');
		if ($endDate !== '') {
			$timestamp = strtotime($endDate . ' +1 day');
			if ($timestamp !== false) {
				$successorStart = date('Y-m-d', $timestamp);
			}
		}

		$valuePerInterval = (float)($predecessor['valuePerInterval'] ?? 0);

		return [
			'title' => (string)($predecessor['title'] ?? ''),
			'clientRef' => (string)($predecessor['clientRef'] ?? ''),
			'lineItems' => $predecessor['lineItems'] ?? [],
			'billingInterval' => (string)($predecessor['billingInterval'] ?? 'monthly'),
			'valuePerInterval' => $valuePerInterval,
			'value' => $valuePerInterval,
			'currency' => (string)($predecessor['currency'] ?? 'EUR'),
			'startDate' => $successorStart,
			'autoRenew' => (bool)($predecessor['autoRenew'] ?? false),
			'noticePeriodDays' => (int)($predecessor['noticePeriodDays'] ?? 0),
			'status' => 'draft',
			'ownerId' => (string)($predecessor['ownerId'] ?? ''),
			'predecessorContractRef' => (string)($predecessor['id'] ?? ($predecessor['uuid'] ?? '')),
			'renewalLeadOutcome' => '',
			'noticeReminderSent' => false,
		];
	}                           

	   
                                                            
   
                                                                        
                                                                            
                                                                             
                                                                             
                                                                           
                                                                      
   
                                                                         
                                                  
   
                                                                                                      
    
	public function getRegisterAndSchema(): array {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schemaId = $this->appConfig->getValueString(Application::APP_ID, 'contract_schema', '');
		if ($registerId === '' || $schemaId === '') {
			$this->logger->warning(
				'Pipelinq: register/contract_schema not configured; OpenRegister calls are refused, not run unscoped'
			);
		}

		return [$registerId, $schemaId];
	}                            

	   
                                               
   
                                                          
                                                                  
   
                                                                                  
   
                                                                                                    
    
	public function save(array $data, ?string $uuid = null): ?array {
		[$registerId, $schemaId] = $this->getRegisterAndSchema();
		if ($registerId === '' || $schemaId === '') {
			$this->logger->warning('ContractService: register/contract_schema not configured');
			return null;
		}

		                                                                        
		if (isset($data['valuePerInterval']) === true) {
			$data['value'] = $data['valuePerInterval'];
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$saved = $objectService->saveObject($data, [], $registerId, $schemaId, $uuid);
			if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
				return $saved->jsonSerialize();
			}

			return (array)$saved;
		} catch (Throwable $e) {
			$this->logger->error('ContractService: save failed', ['error' => $e->getMessage()]);
			return null;
		}         
	}            

	   
                                                         
   
                                                                 
   
                                                                                                
    
	public function loadAll(): array {
		[$registerId, $schemaId] = $this->getRegisterAndSchema();
		if ($registerId === '' || $schemaId === '') {
			return [];
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$results = $objectService->findAll(
				[
					'filters' => ['register' => $registerId, 'schema' => $schemaId],
					'limit' => 10000,
				]
			);

			$contracts = [];
			foreach ($results as $row) {
				if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
					$contracts[] = $row->jsonSerialize();
				} elseif (is_array($row) === true) {
					$contracts[] = $row;
				}
			}

			return $contracts;
		} catch (Throwable $e) {
			$this->logger->warning('ContractService: loadAll failed', ['error' => $e->getMessage()]);
			return [];
		}         
	}               
}           
