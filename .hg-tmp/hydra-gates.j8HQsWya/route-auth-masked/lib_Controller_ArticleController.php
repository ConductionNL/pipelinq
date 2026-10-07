<?php

   
                              
  
                                                                           
                                                                             
                                                                             
                                                                          
                                                                        
                                       
  
                                                                         
                                                                              
                                                                              
                                                                               
                                                                             
                              
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                                                       
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\ArticleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

   
                                
  
                                                                                                                                                       
   
class ArticleController extends Controller {
	   
                
   
                                         
                                                    
                                                          
                                                                          
    
	public function __construct(
		IRequest $request,
		private readonly ArticleService $articles,
		private readonly IUserSession $userSession,
		private readonly ObjectOwnerAccessPolicy $policy,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                     
   
                                         
                                
   
                                                
   
                                                                                                                                                        
    
	#[NoAdminRequired]
	public function index(int $page = 1, int $limit = 20): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		return new JSONResponse($this->articles->listArticles(page: $page, limit: $limit));
	}             

	   
                                         
   
                                           
   
                                             
   
                                                                                                                                                        
    
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		$article = $this->articles->getArticleById(articleId: $id);
		if ($article === null) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['article' => $article]);
	}            

	   
                                                      
   
                                                                           
   
                                                                                                                                                        
    
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		$result = $this->articles->createArticle(payload: $this->collectBody(), authorUid: $uid);
		if (isset($result['error']) === true) {
			return new JSONResponse(['error' => $result['error']], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['article' => ($result['article'] ?? null)], Http::STATUS_CREATED);
	}              

	   
                                                                   
   
                                           
   
                                                          
   
                                                                                                                                                        
    
	#[NoAdminRequired]
	public function update(string $id): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		return $this->render(result: $this->articles->updateArticle(articleId: $id, patch: $this->collectBody()));
	}              

	   
                                                                        
   
                                           
   
                                                          
   
                                                                                                                                           
    
	#[NoAdminRequired]
	public function publish(string $id): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		return $this->render(result: $this->articles->publishArticle(articleId: $id));
	}               

	   
                                                                  
   
                                           
   
                                                          
   
                                                                                                                                           
    
	#[NoAdminRequired]
	public function archive(string $id): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		return $this->render(result: $this->articles->archiveArticle(articleId: $id));
	}               

	   
                                                                       
   
                                                                       
                                                                         
                                                                       
   
                                           
   
                                                          
   
                                                                                                                                           
    
	#[NoAdminRequired]
	public function transition(string $id): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		$transition = (string)$this->request->getParam('transition', '');

		return $this->render(
			result: $this->articles->applyTransition(articleId: $id, transition: $transition),
		);
	}                  

	   
                                                                    
   
                                           
   
                                            
   
                                                                                                                                       
    
	#[NoAdminRequired]
	public function usages(string $id): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		return new JSONResponse($this->articles->listUsages(articleId: $id));
	}              

	   
                                                             
   
                                                                                        
   
                                      
    
	private function render(array $result): JSONResponse {
		if (($result['error'] ?? '') === 'Not found') {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		if (isset($result['error']) === true) {
			return new JSONResponse(['error' => $result['error']], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['article' => ($result['article'] ?? null)]);
	}              

	   
                                                              
   
                                                                      
                                                                          
                                   
   
                                                                         
    
	private function requireCrmUser(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		$uid = $user->getUID();
		if ($this->policy->isPrivileged(uid: $uid) === false) {
			return null;
		}

		return $uid;
	}                      

	   
                                                                        
                                 
   
                               
    
	private function refuse(): JSONResponse {
		return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
	}              

	   
                                     
   
                                                          
                                                                         
                                                                    
   
                                                   
    
	private function collectBody(): array {
		$body = [];
		foreach (['title', 'slug', 'summary', 'body', 'heroImage', 'language', 'portalPageRef'] as $key) {
			$value = $this->request->getParam($key);
			if ($value !== null) {
				$body[$key] = (string)$value;
			}
		}

		foreach (['links', 'tags'] as $key) {
			$value = $this->request->getParam($key);
			if (is_array($value) === true) {
				$body[$key] = $value;
			}
		}

		return $body;
	}                   
}           
