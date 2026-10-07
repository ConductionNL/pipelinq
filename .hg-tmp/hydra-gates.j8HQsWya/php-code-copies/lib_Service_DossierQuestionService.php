<?php

   
                                    
  
                                                                          
                                                                               
                                                                             
                                                                             
                                                         
  
                                                                             
                                                                               
  
                    
                                 
  
                                                              
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                              
  
                                                                                                                                                                       
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\Portal\MainRegisterReader;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                       
  
                                                                             
                                                                             
                                                          
  
                                                                                                                                                                       
   
class DossierQuestionService {
	   
                                                           
   
               
    
	public const SUBJECT_TYPE = 'opencatalogi.collection';

	   
                                                                        
   
               
    
	public const DOSSIER_REGISTER = 'publication';

	   
                                                         
   
               
    
	public const DOSSIER_SCHEMA = 'collection';

	   
                                             
   
               
    
	public const PUBLICATION_SCHEMA = 'publication';

	   
                                                                 
   
               
    
	public const PUBLIC_PUBLICATION_PATH = '/index.php/apps/opencatalogi/api/search/';

	   
                                                        
   
               
    
	public const CHANNEL_PORTAL = 'portal';

	   
                                                    
   
               
    
	private const TICKET = 'ticket';

	   
                                                                 
   
               
    
	private const STATUS_AWAITING_CUSTOMER = 'awaiting_customer';

	   
                                                         
   
               
    
	private const STATUS_IN_PROGRESS = 'in_progress';

	   
                
   
                                                                                   
                                                                                                     
                                                                                     
                                                           
                                                        
   
                                                                                                                                                                        
    
	public function __construct(
		private readonly MainRegisterReader $tickets,
		private readonly ObjectServiceInterface $objectService,
		private readonly IURLGenerator $urlGenerator,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}                   

	   
                                                      
   
                                                                      
                                                                  
                                                  
                                                                                      
   
                                                                                                                     
   
                                                              
   
                                                                                                                                                                        
    
	public function ask(string $subjectRef, string $collectionId, string $question, string $title = ''): ?array {
		$collection = $this->ownedCollection(subjectRef: $subjectRef, collectionId: $collectionId);
		if ($collection === null) {
			return null;
		}

		$snapshot = $this->snapshot(collectionId: $collectionId, collection: $collection);
		$subject = trim($title);
		if ($subject === '') {
			$subject = $snapshot['title'];
		}

		$saved = $this->tickets->save(
			schemaKey: self::TICKET,
			data: [
				'ticketType' => TicketService::TYPE_REQUEST,
				'title' => $subject,
				'description' => $question,
				'channel' => self::CHANNEL_PORTAL,
				'status' => 'new',
				'occurredAt' => $this->time->getDateTime()->format(DATE_ATOM),
				'portalSubject' => $subjectRef,
				'subjectReference' => $snapshot,
			]
		);

		return [
			'id' => $this->idOf(object: $saved),
			'status' => (string)($saved['status'] ?? 'new'),
		];
	}           

	   
                                                   
   
                                                                    
                                                                 
                                             
   
                                                                                                                          
   
                                                              
   
                                                                                                                                                    
    
	public function reply(string $subjectRef, string $ticketId, string $message): ?array {
		$ticket = $this->tickets->find(schemaKey: self::TICKET, id: $ticketId);
		if ($ticket === null || $this->isOwnQuestion(ticket: $ticket, subjectRef: $subjectRef) === false) {
			return null;
		}

		$replies = [];
		if (is_array($ticket['portalReplies'] ?? null) === true) {
			$replies = array_values($ticket['portalReplies']);
		}

		$replies[] = [
			'message' => $message,
			'createdAt' => $this->time->getDateTime()->format(DATE_ATOM),
		];
		$ticket['portalReplies'] = $replies;
		if (($ticket['status'] ?? null) === self::STATUS_AWAITING_CUSTOMER) {
			$ticket['status'] = self::STATUS_IN_PROGRESS;
		}

		$saved = $this->tickets->save(schemaKey: self::TICKET, data: $ticket, id: $ticketId);

		return [
			'id' => $ticketId,
			'status' => (string)($saved['status'] ?? ($ticket['status'] ?? '')),
			'portalReplies' => $replies,
		];
	}             

	   
                                                               
   
                                                                          
                                                                      
                                                                             
   
                                                             
                                                                 
   
                                                                                                                
   
                                                                                                                                                                        
    
	public function snapshot(string $collectionId, array $collection): array {
		$items = [];
		foreach ((array)($collection['items'] ?? []) as $item) {
			if (is_array($item) === false) {
				continue;
			}

			$items[] = $this->snapshotItem(item: $item);
		}

		return [
			'type' => self::SUBJECT_TYPE,
			'id' => $collectionId,
			'title' => (string)($collection['title'] ?? ''),
			'items' => $items,
		];
	}                

	   
                                           
   
                                                                           
                                                
   
                                                                      
                                               
   
                                     
    
	private function ownedCollection(string $subjectRef, string $collectionId): ?array {
		if ($subjectRef === '' || $collectionId === '') {
			return null;
		}

		$collection = $this->read(schema: self::DOSSIER_SCHEMA, id: $collectionId);
		if ($collection === null || (string)($collection['owner'] ?? '') !== $subjectRef) {
			return null;
		}

		return $collection;
	}                       

	   
                                                                   
   
                                                          
   
                                             
    
	private function snapshotItem(array $item): array {
		$publicationId = (string)($item['publication'] ?? '');
		$publication = null;
		if ($publicationId !== '') {
			$publication = $this->read(schema: self::PUBLICATION_SCHEMA, id: $publicationId);
		}

		                                                                        
		                                                 
		$title = (string)($publication['title'] ?? '');
		if ($title === '') {
			$title = (string)($item['title'] ?? '');
		}

		if ($title === '') {
			$title = (string)($item['note'] ?? '');
		}

		if ($title === '') {
			$title = $publicationId;
		}

		$url = '';
		if ($publication !== null) {
			$url = $this->urlGenerator->getAbsoluteURL(self::PUBLIC_PUBLICATION_PATH.rawurlencode($publicationId));
		}

		return ['title' => $title, 'url' => $url];
	}                    

	   
                                                                 
   
                                                       
                                                                                  
   
                
    
	private function isOwnQuestion(array $ticket, string $subjectRef): bool {
		return $subjectRef !== ''
			&& (string)($ticket['portalSubject'] ?? '') === $subjectRef
			&& is_array($ticket['subjectReference'] ?? null) === true;
	}                     

	   
                                                      
   
                                                       
                                        
   
                                     
    
	private function read(string $schema, string $id): ?array {
		try {
			$entity = $this->objectService->find(
				id: $id,
				register: self::DOSSIER_REGISTER,
				schema: $schema,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->debug('Pipelinq: dossier object not readable', ['schema' => $schema, 'reason' => $e->getMessage()]);
			return null;
		}

		if ($entity === null) {
			return null;
		}

		                                                                    
		                                                                      
		                       
		return $entity->getObject();
	}            

	   
                             
   
                                                         
   
                  
    
	private function idOf(array $object): string {
		foreach ([($object['id'] ?? null), ($object['uuid'] ?? null), ($object['@self']['id'] ?? null)] as $candidate) {
			if (is_string($candidate) === true && $candidate !== '') {
				return $candidate;
			}
		}

		return '';
	}            
}           
