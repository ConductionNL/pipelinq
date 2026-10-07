<?php

   
                              
  
                                                                         
                                                                
                                                                        
                                                                          
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                             
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCP\EventDispatcher\Event;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

   
                                                         
  
                                                                              
                                                                              
                                                                      
  
                                                                             
   
class ShillinqApService {
	   
                                             
   
               
    
	public const EVENT_EXPENSE_APPROVED = 'nl.conduction.pipelinq.expense.approved';

	   
                                     
   
               
    
	private const EVENT_SOURCE = '/apps/pipelinq/expenses';

	   
                
   
                                                                                     
                                                                                               
                                              
    
	public function __construct(
		private IntegrationDetector $integrations,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                   
   
                                                                          
                                                                    
                                                                            
                                                  
   
                                                 
   
                                                                                                                                             
    
	public function shouldDispatch(): bool {
		return $this->integrations->shillinq()['installed'] === true;
	}                      

	   
                                                                  
   
                                                                                              
                                                    
                                                              
   
                                                                                    
   
                                                                              
    
	public function dispatchApEvent(array $expense, string $approvedBy, string $approvedAt): bool {
		if ($this->shouldDispatch() === false) {
			return false;
		}

		$payload = $this->buildApPayload(
			expense: $expense,
			approvedBy: $approvedBy,
			approvedAt: $approvedAt
		);

		return $this->dispatch(eventName: self::EVENT_EXPENSE_APPROVED, payload: $payload);
	}                       

	   
                                                               
   
                                                                       
                                                                  
   
                                                                 
                                                    
                                                              
   
                                                        
    
	private function buildApPayload(array $expense, string $approvedBy, string $approvedAt): array {
		$expenseId = (string)($expense['uuid'] ?? $expense['id'] ?? '');
		$time = $this->now();
		if ($approvedAt !== '') {
			$time = $approvedAt;
		}

		$projectId = null;
		if (isset($expense['project']) === true && $expense['project'] !== '') {
			$projectId = (string)$expense['project'];
		}

		return [
			'specversion' => '1.0',
			'type' => self::EVENT_EXPENSE_APPROVED,
			'source' => self::EVENT_SOURCE,
			'id' => $expenseId,
			'time' => $time,
			'datacontenttype' => 'application/json',
			'data' => [
				'expenseId' => $expenseId,
				'amount' => (float)($expense['amount'] ?? 0),
				'currency' => (string)($expense['currency'] ?? 'EUR'),
				'categoryId' => (string)($expense['category'] ?? ''),
				'clientId' => (string)($expense['client'] ?? ''),
				'projectId' => $projectId,
				'billable' => (bool)($expense['billable'] ?? false),
				'approvedBy' => $approvedBy,
				'approvedAt' => $approvedAt,
			],
		];
	}                      

	   
                                                                
   
                                                                           
                                                                    
                                                                        
                              
   
                                                    
                                                                
   
                                                               
    
	private function dispatch(string $eventName, array $payload): bool {
		try {
			$webhookService = $this->container->get('OCA\OpenRegister\Service\WebhookService');
			$event = new Event();
			$webhookService->dispatchEvent(
				_event: $event,
				eventName: $eventName,
				payload: $payload
			);
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: Shillinq AP CloudEvent not dispatched (no consumer or OpenRegister unavailable)',
				['exception' => $e->getMessage(), 'eventName' => $eventName]
			);
			return false;
		}         
	}                

	   
                                             
   
                                                                     
                                                                         
   
                                          
                                                                                                                              
    
	public function now(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
	}           
}           
