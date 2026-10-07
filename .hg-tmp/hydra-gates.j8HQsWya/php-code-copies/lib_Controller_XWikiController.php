<?php

   
                            
  
                                                                           
                                                                            
                                                                         
                                                                      
                                
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                            
  
                                                        
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\XWikiService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

   
                               
  
                              
  
                                                        
   
class XWikiController extends Controller {
	   
                
   
                                          
                                                   
                                          
    
	public function __construct(
		IRequest $request,
		private XWikiService $xwiki,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                       
   
                                                                            
   
                        
   
                                                                         
                                                                                                                          
                                                                                            
    
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function search(): JSONResponse {
		try {
			$query = (string)($this->request->getParam('q') ?? '');
			$space = $this->request->getParam('space');
			$tagsR = $this->request->getParam('tags');
			$limit = (int)($this->request->getParam('limit') ?? 10);
			$offset = (int)($this->request->getParam('offset') ?? 0);

			if (is_string($space) === false || $space === '') {
				$space = null;
			}

			$tags = [];
			if (is_string($tagsR) === true && $tagsR !== '') {
				$tags = array_values(array_filter(array_map('trim', explode(',', $tagsR)), static fn ($t) => $t !== ''));
			}

			$result = $this->xwiki->search($query, $space, $tags, $limit, $offset);
			return new JSONResponse($result);
		} catch (Throwable $e) {
			$this->logger->warning('xWiki search proxy failed', ['exception' => $e]);
			return new JSONResponse(['results' => [], 'total' => 0, 'limit' => 10, 'offset' => 0, 'error' => 'unavailable']);
		}         
	}              

	   
                          
   
                                             
   
                        
   
                                                                         
                                                                                                                          
                                                                                            
    
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function pages(): JSONResponse {
		try {
			$space = (string)($this->request->getParam('space') ?? '');
			$limit = (int)($this->request->getParam('limit') ?? 10);
			$offset = (int)($this->request->getParam('offset') ?? 0);

			$result = $this->xwiki->getPages($space, $limit, $offset);
			return new JSONResponse($result);
		} catch (Throwable $e) {
			$this->logger->warning('xWiki pages proxy failed', ['exception' => $e]);
			return new JSONResponse(['results' => [], 'total' => 0, 'limit' => 10, 'offset' => 0, 'error' => 'unavailable']);
		}
	}             

	   
                                        
   
                                          
                                              
   
                        
   
                                                                         
                                                                                                                             
                                                                                                                                        
    
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function page(string $wiki, string $page): JSONResponse {
		try {
			$result = $this->xwiki->getPageContent($wiki, $page);
			                                                                
			                                    
			if (isset($result['content']) === true && is_string($result['content']) === true) {
				$result['content'] = $this->xwiki->sanitiseHtml($result['content']);
			}

			return new JSONResponse($result);
		} catch (Throwable $e) {
			$this->logger->warning('xWiki page proxy failed', ['exception' => $e]);
			return new JSONResponse(
				[
					'title' => '',
					'content' => '',
					'url' => '',
					'modified' => '',
					'space' => '',
					'id' => '',
					'error' => 'unavailable',
				]
			);
		}         
	}            

	   
                                
   
                        
   
                                                                         
                                                                                                                                           
    
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function status(): JSONResponse {
		try {
			return new JSONResponse($this->xwiki->getStatus());
		} catch (Throwable $e) {
			$this->logger->warning('xWiki status proxy failed', ['exception' => $e]);
			return new JSONResponse(['available' => false, 'version' => '', 'baseUrl' => '', 'source' => 'error']);
		}
	}              
}           
