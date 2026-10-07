<?php

   
                                   
  
                                                                           
                                                                           
                                                                           
                                                                        
                                             
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                                                 
  
                                    
                                                                    
  
                                                                             
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\TicketService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

   
                                          
  
                                       
   
class TicketAssignController extends Controller {

	   
                
   
                                                  
                                                                                                    
                                                         
    
	public function __construct(
		IRequest $request,
		private readonly TicketService $tickets,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                  
   
                                                                          
                                                                   
                                                                       
                                  
   
                                    
   
                                                                       
   
                                                                              
    
	#[NoAdminRequired]
	public function assignToMe(string $id = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->tickets->isConfigured() === false) {
			return new JSONResponse(['error' => 'Tickets are not configured'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$register = $this->tickets->getRegisterId();
		$schema = $this->tickets->getSchemaId();
		$objectService = $this->tickets->getObjectService();

		try {
			$ticket = $objectService->find(id: $id, register: $register, schema: $schema);
		} catch (Throwable) {
			$ticket = null;
		}

		if ($id === '' || $ticket === null) {
			return new JSONResponse(['error' => 'Ticket not found'], Http::STATUS_NOT_FOUND);
		}

		$data = $ticket->getObject();
		unset($data['@self']);
		$data['assignee'] = $user->getUID();

		try {
			$objectService->saveObject(object: $data, register: $register, schema: $schema, uuid: $id);
		} catch (Throwable) {
			return new JSONResponse(['error' => 'You cannot change this ticket'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(['id' => $id, 'assignee' => $user->getUID()]);
	}                  
}           
