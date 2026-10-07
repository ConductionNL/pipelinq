<?php

   
                                      
  
                                                                             
  
                                                                               
                                                                          
                                                                               
                              
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                                                                                                                
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\Competitor\CompetitorWatchService;
use OCA\Pipelinq\Service\Competitor\WatchEventStore;
use OCA\Pipelinq\Service\Egress\ConnectorEgress;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

   
                                  
  
                                                                                                                                                                                
   
class CompetitorWatchController extends Controller {

	   
                                         
   
            
    
	public const MAX_EVENTS = 200;

	   
                
   
                                    
                                     
                                                             
                                                   
                                                                                 
                                             
                                                               
   
                                                                                                                                                                                 
    
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CompetitorWatchService $watches,
		private readonly WatchEventStore $events,
		private readonly ConnectorEgress $egress,
		private readonly IUserSession $userSession,
		private readonly ObjectOwnerAccessPolicy $policy,
	) {
		parent::__construct(appName: $appName, request: $request);
	}                   

	   
                                                                        
                                
   
                                                                                    
   
                                                                                                                                                                                 
    
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$refusal = $this->refuseUnprivileged();
		if ($refusal !== null) {
			return $refusal;
		}

		$configured = $this->egress->isConfigured(configKey: CompetitorWatchService::SOURCE_KEY);
		$reason = '';
		if ($configured === false) {
			$reason = 'No egress source is configured for competitor watches, so nothing is read.';
		}

		return new JSONResponse(
			[
				'configured' => $configured,
				'reason' => $reason,
				'competitors' => $this->watches->competitors(),
				'watches' => $this->watches->watches(),
				'events' => $this->newestFirst(events: $this->events->all()),
			]
		);
	}             

	   
                      
   
                                   
   
                                                     
   
                                                                                                                                                               
    
	#[NoAdminRequired]
	public function run(string $id): JSONResponse {
		$refusal = $this->refuseUnprivileged();
		if ($refusal !== null) {
			return $refusal;
		}

		$watch = $this->watches->watch(id: $id);
		if ($watch === null) {
			return new JSONResponse(['error' => 'No such watch'], Http::STATUS_NOT_FOUND);
		}

		$user = $this->userSession->getUser();
		$uid = null;
		if ($user !== null) {
			$uid = $user->getUID();
		}

		return new JSONResponse($this->watches->runOne(watch: $watch, actingUserId: $uid));
	}           

	   
                                                                          
   
                                                               
   
                                                               
    
	private function newestFirst(array $events): array {
		usort(
			$events,
			static function (array $a, array $b): int {
				return (string)($b['seenAt'] ?? '') <=> (string)($a['seenAt'] ?? '');
			}
		);

		return array_slice($events, 0, self::MAX_EVENTS);
	}                   

	   
                                              
   
                                                                               
    
	private function refuseUnprivileged(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return null;
	}                          
}           
