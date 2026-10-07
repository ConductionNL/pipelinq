<?php

   
                               
  
                                                                         
                                                                   
                                                                        
                                                                           
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                                  
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCP\EventDispatcher\Event;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

   
                                                  
  
                                                                              
                                                                          
                                                                              
  
                                                                                                                                  
   
class ShillinqWipService {
	   
                                                              
   
               
    
	public const EVENT_TIME_APPROVED = 'nl.conduction.pipelinq.time.approved';

	   
                                      
   
               
    
	private const EVENT_SOURCE = '/apps/pipelinq/time-entries';

	   
                
   
                                                                                     
                                                                                               
                                              
    
	public function __construct(
		private IntegrationDetector $integrations,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                    
   
                                                                          
                                                                    
                                                                            
                                                  
   
                                                 
   
                                                                                                                                             
    
	public function shouldDispatch(): bool {
		return $this->integrations->shillinq()['installed'] === true;
	}                      

	   
                                                         
   
                                                                            
                                                                           
   
                                                                        
                                                     
                                                                  
   
                                                               
   
                                                                                                                                   
    
	public function dispatchWipEvent(array $timeEntry, string $approvedBy, string $approvedAt): bool {
		if ($this->shouldDispatch() === false) {
			return false;
		}

		$payload = $this->buildPayload(timeEntry: $timeEntry, approvedBy: $approvedBy, approvedAt: $approvedAt);

		return $this->dispatch(eventName: self::EVENT_TIME_APPROVED, payload: $payload);
	}                        

	   
                                                       
   
                                                               
                                                     
                                                                  
   
                                                        
   
                                                                                                                                   
    
	private function buildPayload(array $timeEntry, string $approvedBy, string $approvedAt): array {
		$uuid = (string)($timeEntry['id'] ?? $timeEntry['uuid'] ?? '');
		$time = $approvedAt;
		if ($time === '') {
			$time = $this->now();
		}

		return [
			'specversion' => '1.0',
			'type' => self::EVENT_TIME_APPROVED,
			'source' => self::EVENT_SOURCE,
			'id' => $uuid,
			'time' => $time,
			'datacontenttype' => 'application/json',
			'data' => [
				'timeEntryId' => $uuid,
				'hours' => (float)($timeEntry['hours'] ?? 0),
				'billingCategoryId' => (string)($timeEntry['billingCategory'] ?? ''),
				'clientId' => (string)($timeEntry['client'] ?? ''),
				'leadId' => (string)($timeEntry['lead'] ?? ''),
				'projectId' => (string)($timeEntry['project'] ?? ''),
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
				'Pipelinq: Shillinq WIP CloudEvent not dispatched (no consumer or OpenRegister unavailable)',
				['exception' => $e->getMessage(), 'eventName' => $eventName]
			);
			return false;
		}         
	}                

	   
                                             
   
                                                                        
                                                       
   
                                          
                                                                                                                              
    
	public function now(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
	}           
}           
