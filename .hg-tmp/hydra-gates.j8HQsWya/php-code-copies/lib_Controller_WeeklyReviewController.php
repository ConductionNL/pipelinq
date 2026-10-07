<?php

   
                                   
  
                                                                          
                                                                           
                                                             
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                                                                                              
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\Marketing\WeeklyReviewService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

   
                                                           
  
                                                                                                                                                                                              
   
class WeeklyReviewController extends Controller {

	   
                
   
                                  
                                         
                                                            
                                                 
                                                               
   
                                                                                                                                                                                               
    
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly WeeklyReviewService $reviews,
		private readonly IUserSession $userSession,
		private readonly ObjectOwnerAccessPolicy $policy,
	) {
		parent::__construct(appName: $appName, request: $request);
	}                   

	   
                                                                           
   
                                                     
   
                                                                                                                                                                                               
    
	#[NoAdminRequired]
	public function show(): JSONResponse {
		$denied = $this->guard();
		if ($denied !== null) {
			return $denied;
		}

		$review = $this->reviews->latest();
		if ($review === null) {
			                                                               
			                                                                  
			                          
			$review = $this->reviews->compose();
		}

		return new JSONResponse($review);
	}            

	   
                                                 
   
                                                                                
   
                                           
   
                                                                                                                                                                                               
    
	#[NoAdminRequired]
	public function generate(string $weekStarting = ''): JSONResponse {
		$denied = $this->guard();
		if ($denied !== null) {
			return $denied;
		}

		$review = $this->reviews->generate(weekStarting: $weekStarting);
		if ($review === null) {
			return new JSONResponse(['error' => 'write_failed'], Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse($review);
	}                

	   
                                                                   
   
                                                                               
    
	private function guard(): ?JSONResponse {
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
