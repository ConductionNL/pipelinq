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

   
                                                        
  
                                                                                                                                                    
   
class SurveyResponseService {
	   
                
   
                                                                        
                                                                               
                                                                               
                                                                             
                                                                             
                                              
    
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly SurveyDispatchService $dispatchService,
		private readonly DetractorFollowUpService $followUp,
		private readonly SurveyOptOutService $optOut,
		private readonly LoggerInterface $logger,
	) {
	}                   

	   
                                  
   
                                                                           
                                                                 
   
                                                                             
                                      
                                                              
   
                                                         
   
                                                                                                                                                     
    
	public function stateOf(?array $invitation, ?DateTimeImmutable $now = null): string {
		if ($invitation === null) {
			return 'unknown';
		}

		$now = ($now ?? new DateTimeImmutable());
		$status = (string)($invitation['status'] ?? '');

		if ($status === 'responded') {
			return 'closed';
		}

		if ($status === 'expired') {
			return 'expired';
		}

		$expiresAt = trim((string)($invitation['expiresAt'] ?? ''));
		if ($expiresAt !== '') {
			try {
				if (new DateTimeImmutable($expiresAt) < $now) {
					return 'expired';
				}
			} catch (Throwable $e) {
				$this->logger->debug(
					'SurveyResponseService: an invitation carries an unusable expiry',
					['expiresAt' => $expiresAt]
				);
			}
		}

		if ($status === 'sent' || $status === 'scheduled') {
			return 'open';
		}

		                                                                      
		                                                  
		return 'unknown';
	}               

	   
                                                 
   
                                                                   
                                                                          
                                                                          
                         
   
                                                      
   
                                                              
    
	private function refusalStatus(string $state): int {
		if ($state === 'unknown') {
			return 404;
		}

		return 410;
	}                     

	   
                                               
   
                                   
   
                                                     
    
	public function invitationFor(string $token): ?array {
		$token = trim($token);
		if ($token === '') {
			return null;
		}

		foreach ($this->dispatchService->read(filters: ['token' => $token]) as $invitation) {
			                                                               
			                                                        
			if (hash_equals((string)($invitation['token'] ?? ''), $token) === true) {
				return $invitation;
			}
		}

		return null;
	}                     

	   
                                                             
   
                                   
   
                                                                            
   
                                                                                                                                                     
    
	public function show(string $token): array {
		$invitation = $this->invitationFor(token: $token);
		$state = $this->stateOf(invitation: $invitation);

		if ($state !== 'open') {
			return ['status' => $this->refusalStatus(state: $state), 'state' => $state];
		}

		return [
			'status' => 200,
			'state' => 'open',
			'survey' => $this->readPublic(uuid: (string)($invitation['surveyRef'] ?? ''), what: 'survey'),
		];
	}            

	   
                                                        
   
                                                                           
                                                                       
   
                                                   
                                                                            
   
                                                                                  
                         
   
                                                                                                                                                     
    
	public function score(array $survey, array $answers): array {
		$nps = null;
		$ratings = [];
		$verbatim = '';

		foreach (($survey['questions'] ?? []) as $question) {
			$key = (string)($question['key'] ?? '');
			$kind = (string)($question['kind'] ?? '');
			$answer = ($answers[$key] ?? null);

			if ($kind === 'nps' && is_numeric($answer) === true) {
				$nps = (int)$answer;
				continue;
			}

			if ($kind === 'rating' && is_numeric($answer) === true) {
				$ratings[] = (float)$answer;
				continue;
			}

			if ($verbatim === '') {
				$verbatim = $this->verbatimOf(kind: $kind, answer: $answer);
			}
		}

		                                                                     
		$averageRating = null;
		if ($ratings !== []) {
			$averageRating = round((array_sum($ratings) / count($ratings)), 2);
		}

		return [
			'npsScore' => $nps,
			'averageRating' => $averageRating,
			'verbatim' => $verbatim,
		];
	}             

	   
                                                                             
   
                                            
                                          
   
                                           
    
	private function verbatimOf(string $kind, mixed $answer): string {
		if ($kind !== 'text') {
			return '';
		}

		return trim((string)$answer);
	}                  

	   
                                     
   
                                   
                                                     
                                                                           
                                                                           
                                        
   
                                                                              
   
                                                                                                                                                     
    
	public function submit(string $token, array $answers, ?bool $optOut = null): array {
		$invitation = $this->invitationFor(token: $token);
		$state = $this->stateOf(invitation: $invitation);

		if ($state !== 'open') {
			return ['status' => $this->refusalStatus(state: $state), 'state' => $state];
		}

		$now = new DateTimeImmutable();
		$survey = $this->readPublic(uuid: (string)($invitation['surveyRef'] ?? ''), what: 'survey');
		$scored = $this->score(survey: $survey, answers: $answers);

		$response = array_merge(
			[
				'surveyRef' => (string)($invitation['surveyRef'] ?? ''),
				'invitationRef' => $this->uuidOf(row: $invitation),
				'clientRef' => (string)($invitation['clientRef'] ?? ''),
				'contactRef' => (string)($invitation['contactRef'] ?? ''),
				'linkedEntityType' => (string)($invitation['linkedEntityType'] ?? ''),
				'linkedEntityId' => (string)($invitation['linkedEntityId'] ?? ''),
				'answers' => $answers,
				'submittedAt' => $now->format(DateTimeInterface::ATOM),
			],
			$scored
		);

		$responseId = $this->writeResponse(response: $response);
		if ($responseId === '') {
			return ['status' => 500, 'error' => 'The response could not be saved.'];
		}

		$response['id'] = $responseId;

		                                                                  
		                                                                   
		$invitation['status'] = 'responded';
		$invitation['respondedAt'] = $now->format(DateTimeInterface::ATOM);
		$invitation['responseRef'] = $responseId;
		$this->dispatchService->write(
			invitation: $invitation,
			uuid: $this->uuidOf(row: $invitation),
		);

		if ($optOut === true) {
			$this->optOut->record(contactRef: (string)($invitation['contactRef'] ?? ''));
		}

		$response = $this->followUp->process(
			response: $response,
			client: $this->readPublic(uuid: (string)($invitation['clientRef'] ?? ''), what: 'client'),
		);

		return ['status' => 201, 'response' => $response];
	}              

	   
                                       
   
                                                                          
                                                                        
                                            
   
                                          
                                                             
   
                                                   
    
	private function readPublic(string $uuid, string $what): array {
		if (trim($uuid) === '') {
			return [];
		}

		try {
			                                                            
			                                                                   
			$entity = $this->objectService->find(id: $uuid, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SurveyResponseService: an object on the public path could not be read',
				['what' => $what, 'uuid' => $uuid, 'exception' => $e->getMessage()]
			);

			return [];
		}

		$data = null;
		if ($entity !== null) {
			$data = $entity->jsonSerialize();
		}

		if (is_array($data) === false) {
			return [];
		}

		return $data;
	}                  

	   
                                                                         
   
                                                    
   
                                   
    
	private function uuidOf(array $row): string {
		return (string)($row['id'] ?? $row['uuid'] ?? '');
	}              

	   
                       
   
                                                       
   
                                                                           
    
	private function writeResponse(array $response): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'surveyResponse_schema', '');
		if ($register === '' || $schema === '') {
			return '';
		}

		try {
			$saved = $this->objectService->saveObject(
				object: $response,
				register: $register,
				schema: $schema,
				_rbac: false,
				_multitenancy: false,
			);

			return (string)($saved->getUuid() ?? '');
		} catch (Throwable $e) {
			$this->logger->error(
				'SurveyResponseService: the response could not be saved',
				['exception' => $e->getMessage()]
			);

			return '';
		}
	}                     
}           
