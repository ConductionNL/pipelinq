<?php

   
                                      
  
                                                                               
                                                                                
                                                                           
                                                                                
                                                                                 
                                                                                 
                                                              
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                            
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\SemanticHandoffService;
use OCA\Pipelinq\Service\TicketService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                    
  
                                   
                                                
                                                   
                                                 
                                                      
  
                                                                           
                                                                          
                                                                     
                                                                           
                                                                            
                                                                                  
                                                                              
                                                                              
  
                                                                
                                                                           
                                                                             
                                                               
  
                                                                            
                                            
  
                                                                                            
                                                                                                                               
   
class SemanticHandoffController extends Controller {
	   
                                 
    
	private const KIND_CASE = 'https://openregister.app/ns#Case';
	private const KIND_INVOICE = 'https://openregister.app/ns#Invoice';

	   
                                              
    
	private const HANDOFF_CONVERT_TO_CASE = 'convert-to-case';
	private const HANDOFF_SEND_TO_INVOICING = 'send-to-invoicing';

	   
                                                                        
    
	private const TICKET_SCHEMA_SLUG = 'ticket';

	   
                
   
                                          
                                                                                 
                                                                         
                                                                    
                                                                
                                                  
                                                                                
                                          
    
	public function __construct(
		IRequest $request,
		private SemanticHandoffService $handoffService,
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private TicketService $ticketService,
		private IUserSession $userSession,
		private ObjectOwnerAccessPolicy $accessPolicy,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                                    
   
                                   
   
                                                                        
   
                                                                                             
    
	#[NoAdminRequired]
	public function requestAvailability(string $id = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['status' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		                                                                 
		                                                                      
		                                                                      
		                                                  
		if ($this->accessPolicy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['status' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$request = $this->loadRequestTicket(id: $id);
		if ($request === null) {
			return new JSONResponse(['status' => 'not-found'], Http::STATUS_NOT_FOUND);
		}

		$available = $this->handoffService->hasImplementer(kindUri: self::KIND_CASE);
		$status = (string)($request['status'] ?? '');

		return new JSONResponse(
			[
				'available' => $available,
				'status' => $status,
				'canConvert' => ($available === true && $status === 'in_progress'),
				'caseReference' => (string)($request['caseReference'] ?? ''),
			]
		);
	}                           

	   
                                                                              
   
                                          
   
                                                
   
                                                                                             
                                                                                                                                
    
	#[NoAdminRequired]
	public function convertRequestToCase(string $id = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['status' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		                                                                 
		                                                                      
		                                                                      
		                                                  
		if ($this->accessPolicy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['status' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$request = $this->loadRequestTicket(id: $id);
		if ($request === null) {
			return new JSONResponse(['status' => 'not-found'], Http::STATUS_NOT_FOUND);
		}

		if ((string)($request['status'] ?? '') !== 'in_progress') {
			return new JSONResponse(
				['status' => 'invalid-status', 'message' => 'Conversion is only available from in_progress.'],
				Http::STATUS_CONFLICT
			);
		}

		if ($this->handoffService->hasImplementer(kindUri: self::KIND_CASE) === false) {
			return new JSONResponse(['status' => 'not-available'], Http::STATUS_CONFLICT);
		}

		$result = $this->handoffService->handoff(
			register: $this->registerSlug(),
			schema: $this->ticketSchema(),
			id: $id,
			handoffId: self::HANDOFF_CONVERT_TO_CASE
		);

		if ($result['ok'] !== true) {
			return new JSONResponse(
				['status' => 'handoff-failed', 'reason' => (string)$result['reason']],
				Http::STATUS_BAD_GATEWAY
			);
		}

		                                                                    
		                                              
		$request['ticketType'] = TicketService::TYPE_REQUEST;
		$request['status'] = 'converted';
		$request['caseReference'] = (string)$result['targetUuid'];
		$saved = $this->saveObject(schema: $this->ticketSchema(), id: $id, payload: $request);
		if ($saved === false) {
			                                                              
			                                                          
			return new JSONResponse(
				['status' => 'converted-unsynced', 'caseReference' => (string)$result['targetUuid']],
				Http::STATUS_OK
			);
		}

		return new JSONResponse(
			[
				'status' => 'converted',
				'caseReference' => (string)$result['targetUuid'],
				'correlationId' => (string)$result['correlationId'],
			]
		);
	}                            

	   
                                                                       
   
                                    
   
                                                      
   
                                                                                                         
    
	#[NoAdminRequired]
	public function contractAvailability(string $id = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['status' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$contract = $this->loadObject(schema: $this->schemaSlug(key: 'contract_schema', default: 'salesContract'), id: $id);
		if ($contract === null) {
			return new JSONResponse(['status' => 'not-found'], Http::STATUS_NOT_FOUND);
		}

		if ($this->mayActOnContract(uid: $user->getUID(), contract: $contract) === false) {
			return new JSONResponse(['status' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$available = $this->handoffService->hasImplementer(kindUri: self::KIND_INVOICE);
		$status = (string)($contract['status'] ?? '');

		return new JSONResponse(
			[
				'available' => $available,
				'status' => $status,
				'canSend' => ($available === true && $status === 'active'),
			]
		);
	}                            

	   
                                                                       
   
                                    
   
                                             
   
                                                                                                         
    
	#[NoAdminRequired]
	public function sendContractToInvoicing(string $id = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['status' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$contract = $this->loadObject(schema: $this->schemaSlug(key: 'contract_schema', default: 'salesContract'), id: $id);
		if ($contract === null) {
			return new JSONResponse(['status' => 'not-found'], Http::STATUS_NOT_FOUND);
		}

		                                                                   
		                                                                        
		if ($this->mayActOnContract(uid: $user->getUID(), contract: $contract) === false) {
			return new JSONResponse(['status' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		if ((string)($contract['status'] ?? '') !== 'active') {
			return new JSONResponse(
				['status' => 'invalid-status', 'message' => 'Send to invoicing is only available on an active contract.'],
				Http::STATUS_CONFLICT
			);
		}

		if ($this->handoffService->hasImplementer(kindUri: self::KIND_INVOICE) === false) {
			return new JSONResponse(['status' => 'not-available'], Http::STATUS_CONFLICT);
		}

		$result = $this->handoffService->handoff(
			register: $this->registerSlug(),
			schema: $this->schemaSlug(key: 'contract_schema', default: 'salesContract'),
			id: $id,
			handoffId: self::HANDOFF_SEND_TO_INVOICING
		);

		if ($result['ok'] !== true) {
			return new JSONResponse(
				['status' => 'handoff-failed', 'reason' => (string)$result['reason']],
				Http::STATUS_BAD_GATEWAY
			);
		}

		$contract['invoiceReference'] = (string)$result['targetUuid'];
		$this->saveObject(
			schema: $this->schemaSlug(key: 'contract_schema', default: 'salesContract'),
			id: $id,
			payload: $contract
		);

		return new JSONResponse(
			[
				'status' => 'sent',
				'invoiceReference' => (string)$result['targetUuid'],
				'correlationId' => (string)$result['correlationId'],
			]
		);
	}                               

	   
                                                                        
   
                                                                          
                                                        
   
                                  
   
                                                                              
    
	private function loadRequestTicket(string $id): ?array {
		$ticket = $this->loadObject(schema: $this->ticketSchema(), id: $id);
		if ($ticket === null) {
			return null;
		}

		if ((string)($ticket['ticketType'] ?? '') !== TicketService::TYPE_REQUEST) {
			return null;
		}

		return $ticket;
	}                         

	   
                                                                         
   
                                     
    
	private function ticketSchema(): string {
		$schemaId = $this->ticketService->getSchemaId();
		if ($schemaId !== '') {
			return $schemaId;
		}

		return self::TICKET_SCHEMA_SLUG;
	}                    

	   
                                                                       
   
                                          
                                                             
   
                                                               
    
	private function mayActOnContract(string $uid, array $contract): bool {
		return $this->accessPolicy->mayAccess(uid: $uid, object: $contract, ownerField: 'ownerId');
	}                        

	   
                                                           
   
                                                                        
                                                                        
                                                        
   
                                            
                                  
   
                                                                      
    
	private function loadObject(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		$objectService = $this->objectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$entity = $objectService->find(
				id: $id,
				register: $this->registerSlug(),
				schema: $schema,
			);
		} catch (Throwable $e) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $this->toArray(value: $entity);
	}                  

	   
                                                         
   
                                            
                                  
                                                             
   
                                 
   
                                                                                                                                
    
	private function saveObject(string $schema, string $id, array $payload): bool {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return false;
		}

		try {
			$objectService->saveObject(
				object: $payload,
				register: $this->registerSlug(),
				schema: $schema,
				uuid: $id,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SemanticHandoffController.saveObject: save failed',
				['id' => $id, 'error' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}                  

	   
                                                   
   
                                        
    
	private function objectService(): ?object {
		try {
			$service = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($service) === false || method_exists($service, 'find') === false) {
			return null;
		}

		return $service;
	}                     

	   
                                            
   
                                        
   
                                               
    
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialised = $value->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($value) === true && method_exists($value, 'getObject') === true) {
			$payload = $value->getObject();
			if (is_array($payload) === true) {
				return $payload;
			}
		}

		return [];
	}               

	   
                                                        
   
                                                                    
                                                                       
                                                                           
                                                                         
                                                                        
                                                                            
                                                                         
                                                                    
                                                       
   
                                     
    
	private function registerSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($slug !== '') {
			return $slug;
		}

		return 'pipelinq';
	}                    

	   
                                              
   
                                      
                                            
   
                        
    
	private function schemaSlug(string $key, string $default): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, $key, '');
		if ($slug !== '') {
			return $slug;
		}

		return $default;
	}                  
}           
