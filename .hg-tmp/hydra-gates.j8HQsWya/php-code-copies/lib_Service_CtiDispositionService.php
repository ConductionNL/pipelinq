<?php

   
                                  
  
                                                                              
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Util\EntityAccessorTrait;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

   
                           
  
                                                                         
             
                                                                                   
                                                                    
                                                                                  
  
                                                                   
                                                                             
                                              
  
                                                                 
                                                                                                                               
   
class CtiDispositionService {
	use EntityAccessorTrait;

	   
                                 
   
                          
    
	public const OUTCOMES = [
		'resolved',
		'callback',
		'escalated',
		'wrong-number',
		'no-answer',
		'abandoned',
	];

	   
                
   
                                                                  
                                            
                                                                
                                          
    
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private TicketService $ticketService,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                          
   
                                                        
                                                           
                                                 
                                                        
   
                                                                              
   
                                                                             
   
                                                                  
    
	public function processDisposition(
		string $interactionId,
		string $subject,
		string $outcome,
		string $notes,
	): array {
		if (in_array($outcome, self::OUTCOMES, true) === false) {
			throw new InvalidArgumentException('Unknown disposition outcome: ' . $outcome);
		}

		$this->updateContactmoment(
			id: $interactionId,
			subject: $subject,
			outcome: $outcome,
			notes: $notes,
		);

		$taskId = null;
		if ($outcome === 'callback') {
			$taskId = $this->createTask(
				type: 'callbackRequest',
				subject: $subject,
				notes: $notes,
				interactionId: $interactionId,
			);
		} elseif ($outcome === 'escalated') {
			$taskId = $this->createTask(
				type: 'followUpTask',
				subject: $subject,
				notes: $notes,
				interactionId: $interactionId,
				queueName: $this->appConfig->getValueString(
					Application::APP_ID,
					'cti_escalation_queue',
					''
				),
			);
		}

		return [
			'outcome' => $outcome,
			'interactionId' => $interactionId,
			'taskId' => $taskId,
		];
	}                          

	   
                                                             
   
                                                
                                               
                                              
                                         
   
                
   
                                                                                                                                
    
	private function updateContactmoment(string $id, string $subject, string $outcome, string $notes): void {
		if ($this->ticketService->isConfigured() === false) {
			$this->logger->warning('CTI disposition: ticket register/schema not configured.');
			return;
		}

		try {
			$this->ticketService->save(
				ticketType: TicketService::TYPE_CONTACTMOMENT,
				payload: [
					'disposition_subject' => $subject,
					'disposition_outcome' => $outcome,
					'disposition_notes' => $notes,
				],
				uuid: $id,
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CTI disposition: contactmoment save failed',
				['exception' => $e->getMessage(), 'id' => $id]
			);
		}
	}                           

	   
                                                 
   
                                                               
                                        
                                    
                                                           
                                                                  
   
                                                                           
   
                                                                            
                                                                         
                                                                             
                                                             
    
	private function createTask(
		string $type,
		string $subject,
		string $notes,
		string $interactionId,
		?string $queueName = null,
	): ?string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$taskSchema = $this->appConfig->getValueString(Application::APP_ID, 'task_schema', '');
		if ($register === '' || $taskSchema === '') {
			$this->logger->warning('CTI disposition: task register/schema not configured -- skipping task creation.');
			return null;
		}

		                                                                         
		                                                                   
		                                                                      
		                                                                       
		                                                                     
		                                        
		if (class_exists('\OCA\OpenRegister\Service\ObjectService') === false) {
			return null;
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$saved = $objectService->saveObject(
				array_filter(
					[
						'type' => $type,
						'subject' => $subject,
						'description' => $notes,
						'status' => 'open',
						'queueName' => $queueName,
						'interaction' => $interactionId,
					],
					static fn ($value): bool => ($value !== null && $value !== '')
				),
				[],
				$register,
				$taskSchema,
				null
			);

			$id = null;
			if (is_array($saved) === true) {
				$id = ($saved['id'] ?? ($saved['uuid'] ?? null));
			} elseif (is_object($saved) === true) {
				                                                                    
				                                                                     
				                                                          
				$uuid = $this->readEntityValue(entity: $saved, getter: 'getUuid');
				if ($uuid !== '') {
					$id = $uuid;
				}
			}

			if ($id !== null) {
				return (string)$id;
			}

			return null;
		} catch (\Throwable $e) {
			$this->logger->error(
				'CTI disposition: task save failed',
				['exception' => $e->getMessage(), 'interactionId' => $interactionId]
			);
			return null;
		}         
	}                  
}           
