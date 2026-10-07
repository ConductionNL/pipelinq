<?php

   
                                 
  
                                                                             
                                                                      
                                                                           
                                                                       
           
  
                                                                               
                                                                         
                                                                            
                                                                             
                                                                    
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                                                                       
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\Social\SocialPublicationStore;
use OCA\Pipelinq\Service\SocialMetricsService;
use OCA\Pipelinq\Service\SocialPostService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

   
                                                                        
  
                                                                                                                                       
  
                                                                       
                                                                              
   
class SocialPostController extends Controller {
	   
                
   
                                         
                                                                      
                                                                            
                                                     
                                                          
                                                                   
   
                
    
	public function __construct(
		IRequest $request,
		private readonly SocialPostService $posts,
		private readonly SocialPublicationStore $publications,
		private readonly SocialMetricsService $metrics,
		private readonly IUserSession $userSession,
		private readonly ObjectOwnerAccessPolicy $policy,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                             
   
                                                                    
   
                                    
   
                                                                                                                         
    
	#[NoAdminRequired]
	public function index(string $status = ''): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		return new JSONResponse($this->posts->listPosts(status: $status));
	}             

	   
                                          
   
                               
   
                                          
   
                                                                                                                         
    
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		$post = $this->posts->getPost(postId: $id);
		if ($post === null) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['post' => $post]);
	}            

	   
                                           
   
                                                   
   
                                                                                                                                        
    
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		$result = $this->posts->createPost(payload: $this->collectBody(), uid: $uid);
		if (isset($result['error']) === true) {
			return new JSONResponse(['error' => $result['error']], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($result, Http::STATUS_CREATED);
	}              

	   
                                                
   
                               
   
                                          
   
                                                                                                                                        
    
	#[NoAdminRequired]
	public function update(string $id): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		return $this->answer(result: $this->posts->updatePost(postId: $id, payload: $this->collectBody(), uid: $uid));
	}              

	   
                                                                     
   
                               
   
                                                                               
   
                                                                                                                         
    
	#[NoAdminRequired]
	public function submit(string $id): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		return $this->answer(result: $this->posts->submitForApproval(postId: $id));
	}              

	   
                                                                     
   
                               
   
                                          
   
                                                                                                                                        
    
	#[NoAdminRequired]
	public function approve(string $id): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		                                                                      
		return $this->answer(
			result: $this->posts->approve(postId: $id, uid: $uid, note: (string)$this->request->getParam('note', '')),
		);
	}               

	   
                                                                
   
                               
   
                                          
   
                                                                                                                                        
    
	#[NoAdminRequired]
	public function reject(string $id): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		return $this->answer(
			result: $this->posts->reject(postId: $id, uid: $uid, note: (string)$this->request->getParam('note', '')),
		);
	}              

	   
                                                                        
   
                               
   
                                    
   
                                                                                                                                        
    
	#[NoAdminRequired]
	public function publications(string $id): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		return new JSONResponse(['data' => $this->publications->forPost(postId: $id)]);
	}                    

	   
                                                                            
   
                                      
   
                                                                                    
   
                                                                                                                                        
    
	#[NoAdminRequired]
	public function retry(string $id): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->refuse();
		}

		                                                                     
		                                                                       
		return $this->answer(result: $this->posts->retryPublication(publicationId: $id, uid: $uid));
	}             

	   
                                                                         
   
                                                                                 
                                    
   
                                    
   
                                                                                                                                     
    
	#[NoAdminRequired]
	public function performance(string $network = '', int $limit = 50): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		return new JSONResponse(['data' => $this->metrics->ranking(network: $network, limit: $limit)]);
	}                   

	   
                                          
   
                                                             
   
                                      
    
	private function answer(array $result): JSONResponse {
		if (isset($result['error']) === true) {
			return new JSONResponse(['error' => $result['error']], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($result);
	}              

	   
                                   
   
                                             
    
	private function requireUser(): ?string {
		return $this->userSession->getUser()?->getUID();
	}                   

	   
                                                                  
   
                                             
    
	private function requireCrmUser(): ?string {
		$uid = $this->requireUser();
		if ($uid === null || $this->policy->isPrivileged(uid: $uid) === false) {
			return null;
		}

		return $uid;
	}                      

	   
                       
   
                               
    
	private function refuse(): JSONResponse {
		return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
	}              

	   
                                      
   
                                                                          
                                                                          
   
                                                       
    
	private function collectBody(): array {
		$body = [];
		foreach (['title', 'articleId', 'campaignId', 'body', 'link', 'scheduledFor'] as $key) {
			$value = $this->request->getParam($key);
			if ($value !== null) {
				$body[$key] = (string)$value;
			}
		}

		foreach (['accountIds', 'media', 'variants'] as $key) {
			$value = $this->request->getParam($key);
			if (is_array($value) === true) {
				$body[$key] = $value;
			}
		}

		return $body;
	}                   
}           
