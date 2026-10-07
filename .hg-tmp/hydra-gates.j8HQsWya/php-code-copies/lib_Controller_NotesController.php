<?php

   
                            
  
                                                                 
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                            
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\NoteEventService;
use OCA\Pipelinq\Service\NotesService;
use OCA\Pipelinq\Service\SettingsService;
use OCA\Pipelinq\Service\TicketService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

   
                                                      
  
                                                                                  
                                                                               
                                                                                 
                                                   
  
                                            
   
class NotesController extends Controller {
	   
                                                                                          
   
                                                                                    
                                                               
   
                                                                                
                                                                          
   
                              
    
	private const OBJECT_TYPE_TO_SCHEMA_KEY = [
		'pipelinq_client' => 'client_schema',
		'pipelinq_contact' => 'contact_schema',
		'pipelinq_lead' => 'lead_schema',
	];

	   
                                                                                  
   
                                                                                  
                                                                              
                                                                                   
                                     
   
                              
    
	private const OBJECT_TYPE_TO_TICKET_TYPE = [
		'pipelinq_request' => TicketService::TYPE_REQUEST,
	];

	   
                
   
                                         
                                                        
                                                                     
                                                      
                                                
                                              
                                                          
                                                         
                                                                 
                                                                    
                                                                          
   
                                                                                   
                                                                                   
                                                                      
    
	public function __construct(
		IRequest $request,
		private NotesService $notesService,
		private NoteEventService $noteEventService,
		private IUserSession $userSession,
		private IL10N $l10n,
		private LoggerInterface $logger,
		private ContainerInterface $container,
		private IGroupManager $groupManager,
		private SettingsService $settingsService,
		private TicketService $ticketService,
		private ObjectOwnerAccessPolicy $policy,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                                  
   
                                                                            
                                                                              
                              
   
                                                                                           
   
                                                                                               
    
	private function resolveScope(?string $schemaKey): array {
		if ($schemaKey === null) {
			return [$this->ticketService->getRegisterId(), $this->ticketService->getSchemaId()];
		}

		$settings = $this->settingsService->getSettings();

		return [
			(string)($settings['register'] ?? ''),
			(string)($settings[$schemaKey] ?? ''),
		];
	}                    

	   
                                                                           
   
                                                                               
                                                                            
                                                                     
   
                                                             
                                                                       
   
                                                                
    
	private function isTicketOfType(mixed $object, string $ticketType): bool {
		$data = null;
		if (is_array($object) === true) {
			$data = $object;
		} elseif (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$payload = $object->getObject();
			if (is_array($payload) === true) {
				$data = $payload;
			}
		}

		if ($data === null) {
			$this->logger->warning('NotesController: ticket payload unreadable', ['ticketType' => $ticketType]);
			return false;
		}

		return (string)($data['ticketType'] ?? '') === $ticketType;
	}                      

	   
                                                                                      
   
                                                                              
                                                                           
                                                                                
                                                                                
                                                                                
                                                                  
   
                                                                                
                                               
   
                                                                   
    
	private function objectExists(string $objectType, string $objectId): bool {
		$ticketType = (self::OBJECT_TYPE_TO_TICKET_TYPE[$objectType] ?? null);
		$schemaKey = (self::OBJECT_TYPE_TO_SCHEMA_KEY[$objectType] ?? null);
		if ($ticketType === null && $schemaKey === null) {
			return false;
		}

		try {
			[$registerId, $schemaId] = $this->resolveScope(schemaKey: $schemaKey);

			if ($registerId === '' || $schemaId === '') {
				                                             
				$this->logger->warning('NotesController: register or schema not configured', ['objectType' => $objectType]);
				return false;
			}

			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$object = $objectService->find(
				id: $objectId,
				register: $registerId,
				schema: $schemaId
			);
			if ($object === null) {
				return false;
			}

			if ($ticketType === null) {
				return true;
			}

			return $this->isTicketOfType(object: $object, ticketType: $ticketType);
		} catch (\OCP\DB\Exception|\OCP\AppFramework\Db\DoesNotExistException $e) {
			                                                          
			return false;
		} catch (\Throwable $e) {
			                                          
			$this->logger->error('NotesController: objectExists check failed', ['objectId' => $objectId, 'exception' => $e->getMessage()]);
			return false;
		}         
	}                    

	   
                             
   
                                              
                                          
   
                                                       
   
                    
   
                                             
    
	public function list(string $objectType, string $objectId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		                                                                      
		                                                 
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['error' => $this->l10n->t('Forbidden')], Http::STATUS_FORBIDDEN);
		}

		if (in_array($objectType, NotesService::VALID_TYPES, true) === false) {
			return new JSONResponse(['error' => $this->l10n->t('Invalid object type')], 400);
		}

		if ($this->objectExists(objectType: $objectType, objectId: $objectId) === false) {
			return new JSONResponse(['error' => $this->l10n->t('Object not found')], Http::STATUS_NOT_FOUND);
		}

		try {
			$notes = $this->notesService->getNotes(
				objectType: $objectType,
				objectId: $objectId
			);
			return new JSONResponse(['notes' => $notes]);
		} catch (\Exception $e) {
			$this->logger->error('NotesController: list failed', ['exception' => $e]);
			return new JSONResponse(['error' => $this->l10n->t('An unexpected error occurred')], 500);
		}
	}            

	   
                               
   
                                              
                                          
   
                                                                  
   
                    
   
                                             
    
	public function create(string $objectType, string $objectId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		                                                                      
		                                                 
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['error' => $this->l10n->t('Forbidden')], Http::STATUS_FORBIDDEN);
		}

		if (in_array($objectType, NotesService::VALID_TYPES, true) === false) {
			return new JSONResponse(['error' => $this->l10n->t('Invalid object type')], 400);
		}

		if ($this->objectExists(objectType: $objectType, objectId: $objectId) === false) {
			return new JSONResponse(['error' => $this->l10n->t('Object not found')], Http::STATUS_NOT_FOUND);
		}

		$message = $this->request->getParam('message', '');
		if (trim($message) === '') {
			return new JSONResponse(['error' => $this->l10n->t('Message is required')], 400);
		}

		try {
			$note = $this->notesService->addNote(
				objectType: $objectType,
				objectId: $objectId,
				message: $message
			);

			$this->noteEventService->triggerNoteEvents(
				objectType: $objectType,
				objectId: $objectId
			);

			return new JSONResponse(['note' => $note]);
		} catch (\Exception $e) {
			$this->logger->error('NotesController: create failed', ['exception' => $e]);
			return new JSONResponse(['error' => $this->l10n->t('An unexpected error occurred')], 500);
		}
	}              

	   
                                                        
   
                                                                         
                                                                       
                                                                  
   
                                              
                                          
   
                                                                                                                                             
   
                                      
   
                                             
    
	public function deleteAll(string $objectType, string $objectId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		                                                                      
		                                                 
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['error' => $this->l10n->t('Forbidden')], Http::STATUS_FORBIDDEN);
		}

		if ($this->groupManager->isAdmin($user->getUID()) === false) {
			return new JSONResponse(['error' => $this->l10n->t('Admin privileges required')], Http::STATUS_FORBIDDEN);
		}

		if (in_array($objectType, NotesService::VALID_TYPES, true) === false) {
			return new JSONResponse(['error' => $this->l10n->t('Invalid object type')], 400);
		}

		if ($this->objectExists(objectType: $objectType, objectId: $objectId) === false) {
			return new JSONResponse(['error' => $this->l10n->t('Object not found')], Http::STATUS_NOT_FOUND);
		}

		try {
			$this->notesService->deleteAllNotes(
				objectType: $objectType,
				objectId: $objectId
			);
			return new JSONResponse(['success' => true]);
		} catch (\Exception $e) {
			$this->logger->error('NotesController: deleteAll failed', ['exception' => $e]);
			return new JSONResponse(['error' => $this->l10n->t('An unexpected error occurred')], 500);
		}
	}                 

	   
                                          
   
                                   
   
                                      
   
                    
   
                                             
    
	public function deleteSingle(int $noteId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		                                                                      
		                                                 
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['error' => $this->l10n->t('Forbidden')], Http::STATUS_FORBIDDEN);
		}

		try {
			$this->notesService->deleteNote(noteId: $noteId);
			return new JSONResponse(['success' => true]);
		} catch (\Exception $e) {
			$this->logger->warning('NotesController: deleteSingle denied', ['exception' => $e->getMessage(), 'noteId' => $noteId]);
			return new JSONResponse(['error' => $this->l10n->t('Not authorized or note not found')], Http::STATUS_FORBIDDEN);
		}
	}                    
}           
