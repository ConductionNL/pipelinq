<?php

   
                                 
  
                                                                            
                                                                       
                                                                            
                                                               
                                                                        
                                                                       
                                                                               
                                                                          
                      
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use OCA\Pipelinq\Event\PortalRequestSubmittedEvent;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;

   
                                                     
  
                                                                                   
                                                                            
                                                                                  
                                                                                 
                                                                             
                                                            
  
                                                                                        
                                                                           
                                                                           
                              
   
class PortalRequestService {
	   
                                                                            
                                                                           
                                              
   
               
    
	private const SCHEMA = 'ticket';

	   
                                            
   
               
    
	private const TICKET_TYPE = 'request';

	   
                                                                           
                                       
   
               
    
	private const STATUS_AWAITING_CUSTOMER = 'awaiting_customer';

	   
                                                                  
   
               
    
	private const STATUS_IN_PROGRESS = 'in_progress';

	   
                                             
   
            
    
	private const MAX_ATTACHMENT_BYTES = (25 * 1024 * 1024);

	   
                                               
   
            
    
	private const RATE_LIMIT = 5;

	   
                                 
   
            
    
	private const RATE_WINDOW_MINUTES = 60;

	   
                
   
                                                                      
                                                         
                                                       
                                                                        
                                               
                                              
    
	public function __construct(
		private MainRegisterReader $reader,
		private PortalScopeResolver $scope,
		private PortalAuditService $audit,
		private IEventDispatcher $dispatcher,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                                     
                                                                  
   
                                                                   
                                      
                                      
   
                                                                                               
                                                                                         
                                                                            
                                                                            
                               
    
	public function getForAccount(array $account, int $page = 1, int $perPage = 10): array {
		$page = max(1, $page);
		$perPage = min(100, max(1, $perPage));

		$resolved = $this->scope->resolve($account, 'submit-requests');
		$rows = [];
		foreach ($this->reader->findAll(self::SCHEMA, ['ticketType' => self::TICKET_TYPE]) as $request) {
			if ($this->visibleTo(resolved: $resolved, request: $request) === false) {
				continue;
			}

			$rows[] = $this->presentSummary(request: $request);
		}

		usort(
			$rows,
			static fn (array $left, array $right): int => strcmp((string)$right['date'], (string)$left['date'])
		);

		$total = count($rows);
		return [
			'total' => $total,
			'page' => $page,
			'perPage' => $perPage,
			'items' => array_slice($rows, (($page - 1) * $perPage), $perPage),
		];
	}                     

	   
                                                                         
                                                        
   
                                                                   
                                            
                                                                         
   
                                                          
                                                                                         
                                                                            
                                                                            
                               
    
	public function getDetailForAccount(array $account, string $requestId, bool $exposeAssigneeName): ?array {
		$request = $this->reader->find(self::SCHEMA, $requestId);
		if ($request === null || $this->isRequest(ticket: $request) === false) {
			return null;
		}

		$resolved = $this->scope->resolve($account, 'submit-requests');
		if ($this->visibleTo(resolved: $resolved, request: $request) === false) {
			return null;
		}

		return $this->presentDetail(request: $request, exposeAssigneeName: $exposeAssigneeName);
	}                           

	   
                                         
   
                                                                   
                                          
                                               
                                         
                                                                                            
                                                     
   
                                                                   
   
                                                               
                                                                                         
                                                                            
                                                                            
                               
    
	public function submit(
		array $account,
		string $tenantId,
		string $subject,
		string $body,
		array $attachments,
		string $categoryId,
	): array {
		$subject = trim($subject);
		$body = trim($body);
		if ($subject === '' || $body === '') {
			throw new PortalException(Http::STATUS_BAD_REQUEST, 'missingFields', 'Onderwerp en bericht zijn verplicht.');
		}

		$accountId = (string)$this->idOf(object: $account);
		$this->assertWithinRateLimit(accountId: $accountId);
		$this->assertAttachmentsWithinLimit(attachments: $attachments);
		$this->assertCategoryExposed(categoryId: $categoryId);

		$contactId = $this->firstId(value: ($account['linkedContactId'] ?? null));
		$clientId = $this->firstId(value: ($account['linkedOrganisationId'] ?? null));

		$attachmentIds = [];
		foreach ($attachments as $attachment) {
			if (isset($attachment['id']) === true) {
				$attachmentIds[] = (string)$attachment['id'];
			}
		}

		$record = [
			'ticketType' => self::TICKET_TYPE,
			'title' => $subject,
			'description' => $body,
			'contact' => $contactId,
			'client' => $clientId,
			'category' => $categoryId,
			'status' => 'new',
			'channel' => 'portal',
			'reporterAccountId' => $accountId,
			'occurredAt' => $this->time->getDateTime()->format(DATE_ATOM),
			'attachmentIds' => $attachmentIds,
		];

		$saved = $this->reader->save(self::SCHEMA, $record);
		$requestId = (string)$this->idOf(object: $saved);

		$this->dispatchSubmitted(requestId: $requestId, tenantId: $tenantId);
		$this->audit->log(
			$accountId,
			$tenantId,
			'request-submit',
			'success',
			[
				'targetObjectType' => 'request',
				'targetObjectId' => $requestId,
			]
		);

		return [
			'requestId' => $requestId,
			'status' => 'new',
			'estimatedResponseTime' => '24 uur',
		];
	}              

	   
                                                                            
                          
   
                                                                   
                                          
                                            
                                          
   
                                                            
   
                                                                             
                                                                                         
                                                                            
                                                                            
                               
    
	public function addReply(array $account, string $tenantId, string $requestId, string $message): array {
		$message = trim($message);
		if ($message === '') {
			throw new PortalException(Http::STATUS_BAD_REQUEST, 'missingFields', 'Bericht is verplicht.');
		}

		$request = $this->reader->find(self::SCHEMA, $requestId);
		$resolved = $this->scope->resolve($account, 'submit-requests');
		if ($request === null
			|| $this->isRequest(ticket: $request) === false
			|| $this->visibleTo(resolved: $resolved, request: $request) === false
		) {
			throw new PortalException(Http::STATUS_NOT_FOUND, 'notFound', 'Niet gevonden.');
		}

		                                                                    
		                                            
		$replies = [];
		if (is_array($request['portalReplies'] ?? null) === true) {
			$replies = array_values($request['portalReplies']);
		}

		$replies[] = [
			'message' => $message,
			'createdAt' => $this->time->getDateTime()->format(DATE_ATOM),
		];
		$request['portalReplies'] = $replies;

		if (($request['status'] ?? null) === self::STATUS_AWAITING_CUSTOMER) {
			$request['status'] = self::STATUS_IN_PROGRESS;
		}

		$saved = $this->reader->save(self::SCHEMA, $request, $requestId);
		$this->audit->log(
			(string)$this->idOf(object: $account),
			$tenantId,
			'request-reply',
			'success',
			[
				'targetObjectType' => 'request',
				'targetObjectId' => $requestId,
			]
		);

		return $this->presentDetail(request: $saved, exposeAssigneeName: false);
	}                

	   
                                                                             
                                     
   
                                                             
                                                            
   
                                   
    
	private function visibleTo(array $resolved, array $request): bool {
		$contactId = $this->readId(value: ($request['contact'] ?? null));
		$clientId = $this->readId(value: ($request['client'] ?? null));
		return $this->scope->classify(resolved: $resolved, contactId: $contactId, clientId: $clientId) !== false;
	}                 

	   
                                                  
   
                                            
   
                
   
                                                       
    
	private function assertWithinRateLimit(string $accountId): void {
		$cutoff = $this->time->getTime() - (self::RATE_WINDOW_MINUTES * 60);
		$recent = 0;
		$filters = ['ticketType' => self::TICKET_TYPE, 'reporterAccountId' => $accountId];
		foreach ($this->reader->findAll(self::SCHEMA, $filters) as $request) {
			$timestamp = strtotime((string)($request['occurredAt'] ?? ''));
			if ($timestamp !== false && $timestamp >= $cutoff) {
				$recent++;
			}
		}

		if ($recent >= self::RATE_LIMIT) {
			throw new PortalException(
				Http::STATUS_TOO_MANY_REQUESTS,
				'rateLimited',
				'Wacht alstublieft 60 minuten voordat u een nieuw verzoek indient.'
			);
		}
	}                             

	   
                                              
   
                                                                                    
   
                
   
                                                     
    
	private function assertAttachmentsWithinLimit(array $attachments): void {
		foreach ($attachments as $attachment) {
			$size = (int)($attachment['size'] ?? 0);
			if ($size > self::MAX_ATTACHMENT_BYTES) {
				throw new PortalException(
					Http::STATUS_REQUEST_ENTITY_TOO_LARGE,
					'fileTooLarge',
					sprintf(
						'Bestand mag maximaal 25 MB zijn. Dit bestand is %d MB.',
						(int)ceil($size / 1024 / 1024)
					)
				);
			}
		}
	}                                    

	   
                                                   
   
                                                                           
                                                                     
                                                
   
                                                     
   
                
   
                                                               
    
	private function assertCategoryExposed(string $categoryId): void {
		if ($this->reader->hasSchema(schemaKey: 'requestCategory') === false) {
			return;
		}

		$category = $this->reader->find('requestCategory', $categoryId);
		if ($category === null || ($category['exposeToCustomer'] ?? false) !== true) {
			throw new PortalException(
				Http::STATUS_UNPROCESSABLE_ENTITY,
				'categoryNotAvailable',
				'Deze categorie is niet beschikbaar.'
			);
		}
	}                             

	   
                                                                             
   
                                                                          
                                                                       
                                
   
                                                    
                                          
   
                
    
	private function dispatchSubmitted(string $requestId, string $tenantId): void {
		try {
			$this->dispatcher->dispatchTyped(
				new PortalRequestSubmittedEvent(requestId: $requestId, tenantId: $tenantId)
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq portal: SLA dispatch failed',
				['requestId' => $requestId, 'exception' => $e->getMessage()]
			);
		}
	}                         

	   
                                          
   
                                                            
   
                                                 
    
	private function presentSummary(array $request): array {
		return [
			'id' => $this->idOf(object: $request),
			'number' => ($request['caseReference'] ?? $request['reference'] ?? null),
			'subject' => ($request['title'] ?? null),
			'category' => $this->readId(value: ($request['category'] ?? null)),
			'status' => ($request['status'] ?? null),
			'date' => ($request['occurredAt'] ?? null),
		];
	}                      

	   
                                                                              
                                         
   
                                                            
                                                               
   
                                            
    
	private function presentDetail(array $request, bool $exposeAssigneeName): array {
		$detail = $this->presentSummary(request: $request);
		$detail['body'] = ($request['description'] ?? null);
		$detail['notes'] = $this->customerNotes(request: $request);
		$detail['canReply'] = ($request['status'] ?? null) === self::STATUS_AWAITING_CUSTOMER;

		$detail['assigneeHidden'] = true;
		if ($exposeAssigneeName === true) {
			$detail['assignee'] = ($request['assignee'] ?? null);
			unset($detail['assigneeHidden']);
		}

		return $detail;
	}                     

	   
                                                                          
                                                                      
                                                                    
                                
   
                                                            
   
                                                                        
    
	private function customerNotes(array $request): array {
		$kept = [];
		$handlerMessage = trim((string)($request['customerMessage'] ?? ''));
		if ($handlerMessage !== '') {
			$kept[] = [
				'author' => 'handler',
				'message' => $handlerMessage,
				'createdAt' => ($request['@self']['updated'] ?? null),
			];
		}

		$replies = [];
		if (is_array($request['portalReplies'] ?? null) === true) {
			$replies = $request['portalReplies'];
		}

		foreach ($replies as $reply) {
			if (is_array($reply) === true && trim((string)($reply['message'] ?? '')) !== '') {
				$kept[] = [
					'author' => 'customer',
					'message' => $reply['message'],
					'createdAt' => ($reply['createdAt'] ?? null),
				];
			}
		}

		usort(
			$kept,
			static fn (array $left, array $right): int => strcmp((string)$left['createdAt'], (string)$right['createdAt'])
		);

		return $kept;
	}                     

	   
                                                                                
   
                                                   
   
                                           
    
	private function isRequest(array $ticket): bool {
		return ($ticket['ticketType'] ?? null) === self::TICKET_TYPE;
	}                 

	   
                                                           
   
                                      
   
                               
    
	private function readId(mixed $value): ?string {
		if ($value === null || $value === '') {
			return null;
		}

		if (is_array($value) === true) {
			$id = ($value['id'] ?? $value['uuid'] ?? null);
			if ($id === null) {
				return null;
			}

			return (string)$id;
		}

		return (string)$value;
	}              

	   
                                                     
   
                                      
   
                                     
    
	private function firstId(mixed $value): ?string {
		if (is_array($value) === true) {
			foreach ($value as $entry) {
				$id = $this->readId(value: $entry);
				if ($id !== null) {
					return $id;
				}
			}

			return null;
		}

		return $this->readId(value: $value);
	}               

	   
                                                          
   
                                                   
   
                               
    
	private function idOf(array $object): ?string {
		$self = ($object['@self'] ?? null);
		if (is_array($self) === true) {
			$id = ($self['id'] ?? $self['uuid'] ?? null);
			if ($id !== null) {
				return (string)$id;
			}
		}

		$id = ($object['id'] ?? $object['uuid'] ?? null);
		if ($id === null) {
			return null;
		}

		return (string)$id;
	}            
}           
