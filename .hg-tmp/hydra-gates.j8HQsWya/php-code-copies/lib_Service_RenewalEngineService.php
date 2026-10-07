<?php

   
                                 
  
                                         
                                                                               
                                                                                
                                                                                   
                                                
                                                                               
                                                   
                                                            
  
                                                                        
                                                                           
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                    
                                    
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                             
  
                                                                                              
                                                                                             
   
class RenewalEngineService {
	   
                                                                      
   
            
    
	private const FALLBACK_LEAD_TIME_DAYS = 60;

	   
                
   
                                                
                                                                                 
                                                                           
                                              
    
	public function __construct(
		private IAppConfig $appConfig,
		private ContainerInterface $container,
		private ContractService $contractService,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                                            
   
                     
   
                                                                                               
    
	public function getDefaultLeadTimeDays(): int {
		$configured = (int)$this->appConfig->getValueString(
			Application::APP_ID,
			'renewal_default_lead_time_days',
			(string)self::FALLBACK_LEAD_TIME_DAYS
		);

		return max($configured, self::FALLBACK_LEAD_TIME_DAYS);
	}                              

	   
                                                                           
                                                       
   
                                                      
   
                                         
   
                                                                                               
    
	public function renewalWindowDays(array $contract): int {
		$notice = (int)($contract['noticePeriodDays'] ?? 0);
		return max($notice, $this->getDefaultLeadTimeDays());
	}                         

	   
                                                                      
   
                                                           
                                         
   
                                                      
                                                        
   
                
   
                                                                                               
    
	public function isInRenewalWindow(array $contract, string $today): bool {
		if ((string)($contract['status'] ?? '') !== 'active') {
			return false;
		}

		$endDate = (string)($contract['endDate'] ?? '');
		if ($endDate === '') {
			return false;
		}

		$windowOpen = $this->dateMinusDays(
			isoDate: $endDate,
			days: $this->renewalWindowDays(contract: $contract)
		);
		return ($windowOpen !== '' && $today >= $windowOpen);
	}                         

	   
                                                                        
   
                                                      
   
                                       
   
                                                                                              
    
	public function annualizedValue(array $contract): float {
		$value = (float)($contract['valuePerInterval'] ?? 0);
		switch ((string)($contract['billingInterval'] ?? '')) {
			case 'monthly':
				return round(($value * 12.0), 2);
			case 'quarterly':
				return round(($value * 4.0), 2);
			case 'annual':
			case 'one-off':
			default:
				return round($value, 2);
		}
	}                       

	   
                                                  
   
                                                                          
   
                                                 
   
                                                                                              
    
	public function buildRenewalLead(array $contract): array {
		return [
			'title' => sprintf('Renewal: %s', (string)($contract['title'] ?? '')),
			'client' => (string)($contract['clientRef'] ?? ''),
			'value' => $this->annualizedValue(contract: $contract),
			'assignee' => (string)($contract['ownerId'] ?? ''),
			'status' => 'open',
			'tags' => ['renewal'],
			'source' => 'contract-renewal',
			'contractRef' => (string)($contract['id'] ?? ($contract['uuid'] ?? '')),
		];
	}                        

	   
                                                                          
                                                                
   
                                                             
                                                        
   
                                                                                           
   
                                                                                               
                                                                                              
    
	public function processContract(array $contract, string $today): string {
		$uuid = (string)($contract['id'] ?? ($contract['uuid'] ?? ''));

		                                                                         
		                                          
		if ((string)($contract['status'] ?? '') === 'expiring') {
			return $this->processExpiringContract(contract: $contract, today: $today, uuid: $uuid);
		}

		                                                                         
		if ($this->isInRenewalWindow(contract: $contract, today: $today) === true
			&& ((string)($contract['renewalLeadRef'] ?? '')) === ''
		) {
			$leadUuid = $this->createRenewalLead(contract: $contract);
			$contract['status'] = 'expiring';
			$contract['renewalLeadRef'] = $leadUuid;
			                                    
			$this->contractService->assertTransitionAllowed($contract, 'expiring', true);
			$this->contractService->save($contract, $uuid);
			return 'expiring';
		}

		return 'noop';
	}                       

	   
                                                                         
                                             
   
                                                             
                                                        
                                          
   
                                                                               
    
	private function processExpiringContract(array $contract, string $today, string $uuid): string {
		$endDate = (string)($contract['endDate'] ?? '');
		$outcome = (string)($contract['renewalLeadOutcome'] ?? '');
		if ($endDate !== '' && $today > $endDate && $outcome === '') {
			$this->transition(contract: $contract, newStatus: 'churned', uuid: $uuid);
			return 'churned-silent';
		}

		                                              
		if ((bool)($contract['noticeReminderSent'] ?? false) === false) {
			$deadline = $this->dateMinusDays(
				isoDate: $endDate,
				days: (int)($contract['noticePeriodDays'] ?? 0)
			);
			if ($deadline !== '' && $today >= $deadline) {
				$this->createNoticeMyWorkEntry(contract: $contract);
				$contract['noticeReminderSent'] = true;
				$this->contractService->save($contract, $uuid);
				return 'noticed';
			}
		}

		return 'noop';
	}                               

	   
                                                                 
   
                                        
                    
   
                                                                                        
   
                                                   
   
                                                                                              
    
	public function reconcile(array $contract): string {
		$uuid = (string)($contract['id'] ?? ($contract['uuid'] ?? ''));
		$outcome = (string)($contract['renewalLeadOutcome'] ?? '');

		if ($outcome === 'won') {
			$this->transition(contract: $contract, newStatus: 'renewed', uuid: $uuid);
			$successor = $this->contractService->buildSuccessorDraft($contract);
			$this->contractService->save($successor, null);
			return 'renewed';
		}

		if ($outcome === 'lost') {
			$this->transition(contract: $contract, newStatus: 'churned', uuid: $uuid);
			return 'churned';
		}

		return 'noop';
	}                 

	   
                                                     
   
                                                      
                                               
                                          
   
                
    
	private function transition(array $contract, string $newStatus, string $uuid): void {
		$this->contractService->assertTransitionAllowed($contract, $newStatus, true);
		$contract['status'] = $newStatus;
		$this->contractService->save($contract, $uuid);
	}                  

	   
                                                                                  
   
                                                      
   
                                                         
    
	private function createRenewalLead(array $contract): string {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$leadSchema = $this->appConfig->getValueString(Application::APP_ID, 'lead_schema', '');
		if ($registerId === '' || $leadSchema === '') {
			return '';
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$saved = $objectService->saveObject(
				$this->buildRenewalLead(contract: $contract),
				[],
				$registerId,
				$leadSchema,
				null
			);
			$arr = (array)$saved;
			if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
				$arr = $saved->jsonSerialize();
			}

			return (string)($arr['id'] ?? ($arr['uuid'] ?? ''));
		} catch (Throwable $e) {
			$this->logger->error('RenewalEngineService: renewal-lead creation failed', ['error' => $e->getMessage()]);
			return '';
		}         
	}                         

	   
                                                                  
   
                                                      
   
                
    
	private function createNoticeMyWorkEntry(array $contract): void {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$taskSchema = $this->appConfig->getValueString(Application::APP_ID, 'task_schema', '');
		if ($registerId === '' || $taskSchema === '') {
			return;
		}

		$autoRenew = (bool)($contract['autoRenew'] ?? false);
		$title = sprintf(
			'Renewal decision due for contract %s',
			(string)($contract['contractNumber'] ?? '')
		);
		if ($autoRenew === true) {
			$title = sprintf(
				'Contract %s renews automatically unless cancelled by the notice deadline',
				(string)($contract['contractNumber'] ?? '')
			);
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$objectService->saveObject(
				[
					'type' => 'follow_up',
					'subject' => $title,
					'status' => 'open',
					'assigneeUserId' => (string)($contract['ownerId'] ?? ''),
					'relatedEntityType' => 'contract',
					'relatedEntityId' => (string)($contract['id'] ?? ($contract['uuid'] ?? '')),
				],
				[],
				$registerId,
				$taskSchema,
				null
			);
		} catch (Throwable $e) {
			$this->logger->error('RenewalEngineService: notice My Work entry failed', ['error' => $e->getMessage()]);
		}         
	}                               

	   
                                                                              
   
                                                
                                                    
   
                                                                            
    
	private function dateMinusDays(string $isoDate, int $days): string {
		if ($isoDate === '') {
			return '';
		}

		$timestamp = strtotime(sprintf('%s -%d days', $isoDate, $days));
		if ($timestamp === false) {
			return '';
		}

		return date('Y-m-d', $timestamp);
	}                     
}           
