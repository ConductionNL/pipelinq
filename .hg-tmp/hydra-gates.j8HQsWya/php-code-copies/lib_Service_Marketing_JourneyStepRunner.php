<?php

   
                              
  
                                                                             
                                       
  
                                                    
                                                                              
                                                                           
                                                                          
                                                             
  
                                                                        
                                                                          
                                                                       
                                      
  
                                                                              
                                                                              
                                                                              
                      
  
                    
                                           
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                                                                                
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

use OCA\Pipelinq\Service\ComplianceService;
use OCA\Pipelinq\Service\SegmentService;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                 
  
                                                                              
                                                                               
                                                                           
  
                                                                                                                                                                                
   
class JourneyStepRunner {

	   
                                       
   
               
    
	public const CHANNEL = 'email';

	   
                                                          
   
            
    
	private const MAX_CONTACTS = 500;

	   
                
   
                                                                  
                                                                  
                                                                          
                                                       
                                                                                            
                                    
                                          
   
                                                                                                                                                                                 
    
	public function __construct(
		private ListObjectStore $store,
		private JourneyService $journeys,
		private ComplianceService $compliance,
		private SegmentService $segments,
		private MailTransportService $transports,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                             
   
                                         
                                                                                              
                                                                      
   
                                                                                                      
   
                                                                                                                                                                                 
    
	public function run(string $journeyId, array $subject = [], string $flowRunUuid = ''): array {
		$journey = $this->journeys->find(journeyId: $journeyId);
		if ($journey === null) {
			return [];
		}

		$action = (array)($journey['action'] ?? []);
		$outcomes = [];
		foreach ($this->contactsFor(journey: $journey, subject: $subject) as $contactId) {
			$outcome = $this->act(
				journeyId: $journeyId,
				action: $action,
				contactId: $contactId,
				segmentId: trim((string)($journey['audienceSegment'] ?? ''))
			);
			$outcome['contactId'] = $contactId;
			$outcomes[] = $outcome;

			$this->journeys->recordRun(run: [
				'journeyId' => $journeyId,
				'contactId' => $contactId,
				'clientId' => $this->clientOf(contactId: $contactId),
				'state' => $outcome['state'],
				'reason' => $outcome['reason'],
				'actionKind' => (string)($action['kind'] ?? ''),
				'flowRunUuid' => $flowRunUuid,
			]);
		}

		return $outcomes;
	}           

	   
                                                   
   
                                         
                                                                   
                                         
                                                                                     
   
                                                               
    
	private function act(string $journeyId, array $action, string $contactId, string $segmentId = ''): array {
		if ((string)($action['kind'] ?? 'createTask') === 'createTask') {
			                                                               
			                                             
			return $this->createTask(action: $action, contactId: $contactId);
		}

		$gate = $this->compliance->permitsSend(
			contactId: $contactId,
			channel: self::CHANNEL,
			intent: (string)($action['intent'] ?? ComplianceService::INTENT_PROMOTIONAL),
			listId: (string)($action['listId'] ?? '')
		);

		if ($gate['allowed'] === false) {
			$this->logger->info(
				'JourneyStepRunner: refused a journey send',
				['journeyId' => $journeyId, 'contactId' => $contactId, 'reason' => $gate['reason']]
			);
			return ['state' => 'refused', 'reason' => $gate['reason']];
		}

		return $this->sendMailing(journeyId: $journeyId, action: $action, contactId: $contactId, segmentId: $segmentId);
	}           

	   
                                            
   
                                         
                                                         
                                         
                                                                                     
   
                                                               
    
	private function sendMailing(string $journeyId, array $action, string $contactId, string $segmentId = ''): array {
		$template = $this->store->find(
			schemaSlug: $this->store->schemaSlug('campaignTemplate_schema', 'campaignTemplate'),
			id: (string)($action['templateId'] ?? '')
		);
		if ($template === null) {
			return ['state' => 'failed', 'reason' => 'template_missing'];
		}

		$transport = $this->transports->resolveTransport(blast: []);
		if ($transport === null) {
			return ['state' => 'failed', 'reason' => 'no_transport'];
		}

		$contact = $this->store->find(schemaSlug: $this->store->schemaSlug('contact_schema', 'contact'), id: $contactId);
		$email = trim((string)(($contact ?? [])['email'] ?? ''));
		if ($email === '') {
			return ['state' => 'failed', 'reason' => 'no_email'];
		}

		$blastId = $this->ledgerBlast(
			journeyId: $journeyId,
			templateId: (string)($action['templateId'] ?? ''),
			segmentId: $segmentId
		);
		if ($blastId === '') {
			return ['state' => 'failed', 'reason' => 'no_ledger'];
		}

		$delivery = $this->store->save(
			schemaSlug: $this->store->schemaSlug('blastDelivery_schema', 'blastDelivery'),
			payload: ['blastId' => $blastId, 'contactId' => $contactId, 'email' => $email, 'status' => 'queued']
		);
		if ($delivery === null) {
			return ['state' => 'failed', 'reason' => 'no_delivery'];
		}

		try {
			$accepted = $this->transports->sendOneDelivery(delivery: $delivery, template: $template, transport: $transport);
		} catch (Throwable $e) {
			$this->logger->warning(
				'JourneyStepRunner.sendMailing: the transport threw',
				['journeyId' => $journeyId, 'contactId' => $contactId, 'exception' => $e->getMessage()]
			);
			return ['state' => 'failed', 'reason' => 'transport_error'];
		}

		if ($accepted === true) {
			return ['state' => 'sent', 'reason' => ''];
		}

		return ['state' => 'failed', 'reason' => 'transport_rejected'];
	}                   

	   
                                  
   
                                                         
                                                           
   
                                                               
    
	private function createTask(array $action, string $contactId): array {
		$subject = trim((string)($action['taskSubject'] ?? ''));
		if ($subject === '') {
			return ['state' => 'failed', 'reason' => 'task_subject_missing'];
		}

		$payload = [
			'subject' => $subject,
			'type' => (string)($action['taskType'] ?? 'callback'),
			'status' => 'open',
			'clientId' => $this->clientOf(contactId: $contactId),
		];

		$assignee = trim((string)($action['taskAssignee'] ?? ''));
		if ($assignee !== '') {
			$payload['assigneeUserId'] = $assignee;
		}

		$stored = $this->store->save(schemaSlug: $this->store->schemaSlug('task_schema', 'crmTask'), payload: $payload);
		if ($stored === null) {
			return ['state' => 'failed', 'reason' => 'task_write_failed'];
		}

		return ['state' => 'task-created', 'reason' => ''];
	}                  

	   
                                   
   
                                                     
                                                                                     
   
                                                              
    
	private function contactsFor(array $journey, array $subject): array {
		if ($subject === []) {
			                                                                
			                           
			return $this->capped(contacts: $this->audienceContacts(journey: $journey));
		}

		$contacts = $this->subjectContacts(subject: $subject);
		if (trim((string)($journey['audienceSegment'] ?? '')) !== '') {
			                                                              
			                                                          
			$contacts = array_intersect($contacts, $this->audienceContacts(journey: $journey));
		}

		return $this->capped(contacts: $contacts);
	}                   

	   
                                                           
   
                                                       
   
                                               
    
	private function capped(array $contacts): array {
		return array_slice(array_values(array_unique(array_filter($contacts))), 0, self::MAX_CONTACTS);
	}              

	   
                                                   
   
                                                     
   
                                           
    
	private function audienceContacts(array $journey): array {
		$segment = trim((string)($journey['audienceSegment'] ?? ''));
		if ($segment === '') {
			return [];
		}

		$contacts = [];
		foreach ($this->segments->getMembersForBlast(segmentId: $segment) as $member) {
			$contactId = trim((string)($member['contactId'] ?? ''));
			if ($contactId !== '') {
				$contacts[] = $contactId;
			}
		}

		return $contacts;
	}                        

	   
                                                 
   
                                                               
   
                                           
    
	private function subjectContacts(array $subject): array {
		foreach (['contact', 'contactId'] as $key) {
			$value = trim((string)($subject[$key] ?? ''));
			if ($value !== '') {
				return [$value];
			}
		}

		                                                                  
		                                                                  
		                                            
		$clientRef = trim((string)($subject['clientRef'] ?? ''));
		if ($clientRef === '') {
			return [];
		}

		$contacts = [];
		foreach ($this->store->findAll(schemaSlug: $this->store->schemaSlug('contact_schema', 'contact'), filters: ['client' => $clientRef]) as $contact) {
			if ((string)($contact['client'] ?? '') === $clientRef) {
				$contacts[] = $this->store->idOf($contact);
			}
		}

		return $contacts;
	}                       

	   
                                        
   
                                         
   
                                                           
    
	private function clientOf(string $contactId): string {
		$contact = $this->store->find(schemaSlug: $this->store->schemaSlug('contact_schema', 'contact'), id: $contactId);
		if ($contact === null) {
			return '';
		}

		return trim((string)($contact['client'] ?? ''));
	}                

	   
                                                                        
   
                                                                        
                                                                        
                                                                
   
                                         
                                                             
                                                                                             
   
                                                                    
    
	private function ledgerBlast(string $journeyId, string $templateId, string $segmentId = ''): string {
		$schema = $this->store->schemaSlug('blast_schema', 'blast');
		foreach ($this->store->findAll(schemaSlug: $schema, filters: ['journeyId' => $journeyId]) as $blast) {
			if ((string)($blast['journeyId'] ?? '') === $journeyId) {
				return $this->store->idOf($blast);
			}
		}

		$stored = $this->store->save(schemaSlug: $schema, payload: [
			'name' => 'Journey ' . $journeyId,
			'status' => 'sending',
			'channel' => self::CHANNEL,
			'journeyId' => $journeyId,
			'templateId' => $templateId,
			                                                                
			                                                                  
			                                                                 
			                                                       
			'segmentId' => $segmentId,
			'listId' => '',
			'createdAt' => date('c', $this->time->getTime()),
		]);

		return $this->store->idOf($stored);
	}                   
}           
