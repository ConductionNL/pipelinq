<?php

   
                                         
  
                                                                               
                                                                        
                                                                 
                                                                  
  
                       
                                    
  
                                                              
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                              
  
                                                                                                                                                                    
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\Portal\MainRegisterReader;
use OCA\Pipelinq\Service\WooRequestConversionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

   
                                                                       
  
                                                                                                                                                                    
   
class TicketWooRequestController extends Controller {
	   
                                                    
   
               
    
	private const TICKET = 'ticket';

	   
                
   
                                                                 
                                                                           
                                                                                            
                                                                            
                                                                            
   
                                                                                                                                                                     
    
	public function __construct(
		IRequest $request,
		private readonly WooRequestConversionService $conversion,
		private readonly MainRegisterReader $tickets,
		private readonly IUserSession $userSession,
		private readonly ObjectOwnerAccessPolicy $accessPolicy,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                     
   
                                    
   
                        
   
                                                                                                                                                                     
    
	#[NoAdminRequired]
	public function availability(string $id = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['status' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->accessPolicy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['status' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		try {
			$ticket = $this->tickets->find(schemaKey: self::TICKET, id: $id);
			if ($ticket === null) {
				return new JSONResponse(['status' => 'not-found'], Http::STATUS_NOT_FOUND);
			}

			return new JSONResponse($this->conversion->availability(ticket: $ticket));
		} catch (RuntimeException $e) {
			return new JSONResponse(['status' => 'unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}
	}                    

	   
                                          
   
                                    
   
                                                                                                
   
                                                                                                                                                                     
    
	#[NoAdminRequired]
	public function convert(string $id = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['status' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		                                                                    
		                                                                     
		                                 
		if ($this->accessPolicy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['status' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		try {
			$ticket = $this->tickets->find(schemaKey: self::TICKET, id: $id);
			if ($ticket === null) {
				return new JSONResponse(['status' => 'not-found'], Http::STATUS_NOT_FOUND);
			}

			$result = $this->conversion->convert(ticketId: $id, ticket: $ticket);
		} catch (RuntimeException $e) {
			                                                                        
			return new JSONResponse(['status' => 'unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$status = match ($result['status']) {
			'not-available', 'not-convertible' => Http::STATUS_CONFLICT,
			'intake-failed' => Http::STATUS_BAD_GATEWAY,
			default => Http::STATUS_OK,
		};

		return new JSONResponse($result, $status);
	}               
}           
