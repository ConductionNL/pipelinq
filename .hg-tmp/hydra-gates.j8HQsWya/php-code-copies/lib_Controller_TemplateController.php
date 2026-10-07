<?php

   
                               
  
                                                                    
                                                                       
                                                                        
                                                                   
                                                                
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                            
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\ArticleService;
use OCA\Pipelinq\Service\ComplianceService;
use OCA\Pipelinq\Service\Marketing\PhysicalAddressRenderer;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

   
                                                 
  
                                                                                                                            
   
class TemplateController extends Controller {
	   
                
   
                                         
                                                                           
                                                                                     
                                                          
                                                                          
                                                                                                          
    
	public function __construct(
		IRequest $request,
		private readonly ComplianceService $complianceService,
		private readonly ArticleService $articleService,
		private readonly IUserSession $userSession,
		private readonly ObjectOwnerAccessPolicy $policy,
		private readonly PhysicalAddressRenderer $addressRenderer,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                        
   
                                         
                                
   
                                                
   
                                              
    
	#[NoAdminRequired]
	public function index(int $page = 1, int $limit = 20): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->unauthorized();
		}

		                                                                      
		                                                  
		if ($this->policy->isPrivileged(uid: $uid) === false) {
			return $this->forbidden();
		}

		$envelope = $this->complianceService->listTemplates(page: $page, limit: $limit);
		return new JSONResponse($envelope);
	}             

	   
                                                             
   
                                                                    
   
                                              
    
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$user = $this->requireUser();
		if ($user === null) {
			return $this->unauthorized();
		}

		$payload = $this->collectTemplateBody();
		$result = $this->complianceService->createTemplate(payload: $payload, createdByUid: $user);
		return $this->renderResult(result: $result, successStatus: Http::STATUS_CREATED);
	}              

	   
                                                        
   
                                            
   
                                                              
   
                                              
    
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->unauthorized();
		}

		                                                                      
		                                                  
		if ($this->policy->isPrivileged(uid: $uid) === false) {
			return $this->forbidden();
		}

		$template = $this->complianceService->getTemplateById(templateId: $id);
		if ($template === null) {
			return $this->notFound();
		}

		return new JSONResponse($template);
	}            

	   
                                                                    
   
                                            
   
                                                                    
   
                                              
    
	#[NoAdminRequired]
	public function update(string $id): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->unauthorized();
		}

		                                                                      
		                                                  
		if ($this->policy->isPrivileged(uid: $uid) === false) {
			return $this->forbidden();
		}

		$patch = $this->collectTemplateBody();
		$result = $this->complianceService->patchTemplate(templateId: $id, patch: $patch);
		return $this->renderResult(result: $result, successStatus: Http::STATUS_OK);
	}              

	   
                                                                     
   
                                                                           
                                                                         
                                                                                                   
                                                                      
                                            
   
                                            
   
                                                                               
   
                                                                                                                                        
    
	#[NoAdminRequired]
	public function preview(string $id): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->unauthorized();
		}

		if ($this->policy->isPrivileged(uid: $uid) === false) {
			return $this->forbidden();
		}

		$template = $this->complianceService->getTemplateById(templateId: $id);
		if ($template === null) {
			return $this->notFound();
		}

		$ids = ($template['articleIds'] ?? []);
		if (is_array($ids) === false) {
			$ids = [];
		}

		$articles = $this->articleService->loadArticlesByIds(articleIds: $ids);
		$footer = (string)($template['footerOverride'] ?? '');

		return new JSONResponse([
			'subject' => (string)($template['subject'] ?? ''),
			'bodyHtml' => $this->addressRenderer->render(
				body: $this->articleService->expandArticlesMarker(
					body: (string)($template['bodyHtml'] ?? ''),
					articles: $articles,
					format: ArticleService::FORMAT_HTML,
				),
				footerOverride: $footer,
				format: ArticleService::FORMAT_HTML,
			),
			'bodyText' => $this->addressRenderer->render(
				body: $this->articleService->expandArticlesMarker(
					body: (string)($template['bodyText'] ?? ''),
					articles: $articles,
					format: ArticleService::FORMAT_TEXT,
				),
				footerOverride: $footer,
				format: ArticleService::FORMAT_TEXT,
			),
			'articles' => array_map(
				static fn (array $article): array => [
					'title' => (string)($article['title'] ?? ''),
					'summary' => (string)($article['summary'] ?? ''),
				],
				$articles,
			),
		]);
	}               

	   
                                                                           
                                                                      
                                                             
   
                                                                           
                                                                 
   
                                            
   
                                                  
   
                                                                                           
    
	#[NoAdminRequired]
	public function validate(string $id): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->unauthorized();
		}

		if ($this->policy->isPrivileged(uid: $uid) === false) {
			return $this->forbidden();
		}

		$template = $this->complianceService->getTemplateById(templateId: $id);
		if ($template === null) {
			return $this->notFound();
		}

		$channel = (string)$this->request->getParam('channel', (string)($template['channel'] ?? 'email'));
		$error = $this->complianceService->validateTemplate(templateData: $template, channel: $channel);

		return new JSONResponse(['valid' => ($error === null), 'error' => $error]);
	}                

	   
                                   
   
                                    
    
	private function requireUser(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		return $user->getUID();
	}                   

	   
                                                                          
                                                                        
                                                                           
                                                                      
                                     
   
                                                   
    
	private function collectTemplateBody(): array {
		$fields = [
			'name',
			'channel',
			'subject',
			'bodyHtml',
			'bodyText',
			'senderName',
			'senderEmail',
			'replyTo',
			'footerOverride',
		];

		$body = [];
		foreach ($fields as $field) {
			$value = $this->request->getParam($field);
			if ($value !== null) {
				$body[$field] = (string)$value;
			}
		}

		$articleIds = $this->request->getParam('articleIds');
		if ($articleIds !== null) {
			$body['articleIds'] = $articleIds;
		}

		return $body;
	}                           

	   
                                                                 
   
                                                                                         
                                                     
   
                        
    
	private function renderResult(array $result, int $successStatus): JSONResponse {
		if (isset($result['error']) === true) {
			$status = Http::STATUS_BAD_REQUEST;
			if ($result['error'] === 'Template not found') {
				$status = Http::STATUS_NOT_FOUND;
			}

			return new JSONResponse(['error' => $result['error']], $status);
		}

		return new JSONResponse($result['template'] ?? null, $successStatus);
	}                    

	   
                         
   
                        
    
	private function unauthorized(): JSONResponse {
		return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
	}                    

	   
                                                          
   
                                          
    
	private function forbidden(): JSONResponse {
		return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
	}                 

	   
                         
   
                        
    
	private function notFound(): JSONResponse {
		return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
	}                
}           
