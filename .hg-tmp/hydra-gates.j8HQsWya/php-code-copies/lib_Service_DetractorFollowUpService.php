<?php

   
                                     
  
                                                                             
                                           
  
                                                                             
                                                                              
                                                                          
                                                                             
                                                                             
                                                        
  
                                                                             
                                                                       
                                                                          
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                            
  
                                                                                                                                           
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                 
  
                                                                                                                                           
   
class DetractorFollowUpService {
	   
                                                                       
                                        
   
               
    
	public const THRESHOLD_KEY = 'survey_detractor_rating_threshold';

	   
                                                                    
   
               
    
	public const DEFAULT_ASSIGNEE_KEY = 'survey_default_assignee';

	   
                                                                      
   
            
    
	public const NPS_DETRACTOR_CEILING = 6;

	   
                                                              
   
            
    
	public const NPS_PROMOTER_FLOOR = 9;

	   
                
   
                                                                                            
                                                                               
                                              
    
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}                   

	   
                                                                 
   
                              
   
                                                                                                                                            
    
	public function ratingThreshold(): int {
		$configured = (int)$this->appConfig->getValueString(Application::APP_ID, self::THRESHOLD_KEY, '2');

		if ($configured > 0) {
			return $configured;
		}

		return 2;
	}                       

	   
                                   
   
                                                                         
                                                                          
                                    
   
                                                       
   
                                                       
   
                                                                                                                                            
    
	public function classify(array $response): string {
		$nps = ($response['npsScore'] ?? null);
		if (is_numeric($nps) === true && (int)$nps <= self::NPS_DETRACTOR_CEILING) {
			return 'detractor';
		}

		$rating = ($response['averageRating'] ?? null);
		if (is_numeric($rating) === true && (float)$rating > 0.0
			&& (float)$rating <= (float)$this->ratingThreshold()
		) {
			return 'detractor';
		}

		if (is_numeric($nps) === true && (int)$nps >= self::NPS_PROMOTER_FLOOR) {
			return 'promoter';
		}

		return 'passive';
	}                

	   
                                          
   
                                                                            
   
                                                                            
    
	public function assigneeFor(array $client): string {
		$owner = trim((string)($client['owner'] ?? $client['ownerId'] ?? $client['assignee'] ?? ''));
		if ($owner !== '') {
			return $owner;
		}

		return trim(
			$this->appConfig->getValueString(Application::APP_ID, self::DEFAULT_ASSIGNEE_KEY, '')
		);
	}                   

	   
                                                                        
   
                                                                           
                                                                        
                                                            
   
                                                       
                                                                  
                                                           
   
                                                        
   
                                                                                                                                            
    
	public function followUpTaskFor(array $response, array $client, ?DateTimeImmutable $now = null): ?array {
		if ($this->classify(response: $response) !== 'detractor') {
			return null;
		}

		$now = ($now ?? new DateTimeImmutable());

		return [
			'type' => 'followUp',
			'subject' => 'Follow up an unhappy response',
			'description' => trim((string)($response['verbatim'] ?? '')),
			'status' => 'open',
			'priority' => 'high',
			'deadline' => $now->modify('+2 days')->format(DateTimeInterface::ATOM),
			'assigneeUserId' => $this->assigneeFor(client: $client),
			'clientId' => trim((string)($response['clientRef'] ?? '')),
			                                                                  
			                                         
			'surveyResponseId' => trim((string)($response['id'] ?? $response['uuid'] ?? '')),
		];
	}                       

	   
                                                                  
   
                                                                           
                                                                         
                                                                         
   
                                                       
                                                                  
   
                                                                
   
                                                                                                                                            
    
	public function process(array $response, array $client): array {
		$classification = $this->classify(response: $response);
		$task = $this->followUpTaskFor(response: $response, client: $client);

		if ($task !== null) {
			$response['followUpAssignee'] = $task['assigneeUserId'];

			$taskId = $this->writeTask(task: $task);
			if ($taskId !== '') {
				$response['followUpTaskRef'] = $taskId;
			}
		}

		$response['classification'] = $classification;
		$this->writeResponse(response: $response);

		return $response;
	}               

	   
                             
   
                                               
   
                                                                       
    
	private function writeTask(array $task): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'task_schema', '');
		if ($register === '' || $schema === '') {
			$this->logger->warning('DetractorFollowUpService: the task surface is not configured');

			return '';
		}

		try {
			$saved = $this->objectService->saveObject(
				object: $task,
				register: $register,
				schema: $schema,
			);

			return (string)($saved->getUuid() ?? '');
		} catch (Throwable $e) {
			$this->logger->error(
				'DetractorFollowUpService: the follow-up task could not be written',
				['exception' => $e->getMessage()]
			);

			return '';
		}
	}                 

	   
                                       
   
                                                       
   
                
    
	private function writeResponse(array $response): void {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'surveyResponse_schema', '');
		if ($register === '' || $schema === '') {
			return;
		}

		try {
			$this->objectService->saveObject(
				object: $response,
				register: $register,
				schema: $schema,
				uuid: (string)($response['id'] ?? $response['uuid'] ?? ''),
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'DetractorFollowUpService: the classified response could not be saved',
				['exception' => $e->getMessage()]
			);
		}
	}                     
}           
