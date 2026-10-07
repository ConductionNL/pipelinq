<?php

   
                           
  
                                                                 
                                                           
                                                                        
                                                                           
  
                                                                    
                                                                       
                                                                             
                                                                         
                                           
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                            
  
                                                                                           
  
                                                                    
                                    
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\NaviService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

   
                                                                
  
                                                                          
                                                                         
                      
  
                                                                                           
   
class NaviController extends Controller {
	   
                                                                            
                                                                             
                                                                           
                                                  
   
               
    
	public const CONVERSATION_ID_PATTERN = '/^[0-9a-f]{32}$/';

	   
                
   
                                          
                                                              
                                                         
                                          
    
	public function __construct(
		IRequest $request,
		private NaviService $naviService,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                         
   
                                                        
                                                                    
                                                                  
   
                                                                        
                                                                         
                                                                          
                                                         
   
                                                    
   
                                                                   
    
	#[NoAdminRequired]
	public function query(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$query = trim((string)$this->request->getParam('query', ''));
		if ($query === '') {
			return new JSONResponse(['message' => 'Missing query'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$conversationId = $this->resolveConversationId(raw: $this->request->getParam('conversationId', ''));
			$payload = $this->naviService->processQuery(
				query: $query,
				userId: $user->getUID(),
				conversationId: $conversationId
			);
			$payload['conversationId'] = $conversationId;

			return new JSONResponse($payload);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[NaviController] query failed',
				context: ['error' => $e->getMessage()]
			);
			return new JSONResponse(['message' => 'Navi unavailable'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}         
	}             

	   
                                                                          
   
                                                                            
                                                                           
                                                                            
                
   
                                                
   
                                                            
   
                                                                   
   
                                                                   
    
	private function resolveConversationId(mixed $raw): string {
		if (is_string($raw) === true && preg_match(self::CONVERSATION_ID_PATTERN, $raw) === 1) {
			return $raw;
		}

		return bin2hex(random_bytes(16));
	}                             
}           
