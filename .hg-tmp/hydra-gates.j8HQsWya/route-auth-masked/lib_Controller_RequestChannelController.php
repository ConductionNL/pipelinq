<?php

   
                                     
  
                                                                     
  
                       
                                    
  
                                                              
                                  
                                                                                    
  
                         
  
                            
  
                                              
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\SystemTagService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

   
                                             
  
                                                                                                     
   
class RequestChannelController extends Controller {
	private const OBJECT_TYPE = 'pipelinq_request_channel';

	   
                
   
                                         
                                                                     
                                                      
                                              
    
	public function __construct(
		IRequest $request,
		private SystemTagService $systemTagService,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                  
   
                                                      
   
                                               
    
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(
			[
				'success' => true,
				'tags' => $this->systemTagService->getTags(self::OBJECT_TYPE),
			]
		);
	}             

	   
                                     
   
                                                                 
   
                                               
    
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function create(): JSONResponse {
		$name = $this->request->getParam('name', '');

		try {
			$tag = $this->systemTagService->addTag(
				objectType: self::OBJECT_TYPE,
				name: $name
			);
			return new JSONResponse(['success' => true, 'tag' => $tag]);
		} catch (\InvalidArgumentException|\RuntimeException $e) {
			return new JSONResponse(['success' => false, 'message' => $e->getMessage()], 400);
		}
	}              

	   
                                 
   
                                 
   
                                                                 
   
                                               
    
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function update(string $id): JSONResponse {
		$name = $this->request->getParam('name', '');

		try {
			$tag = $this->systemTagService->renameTag(
				objectType: self::OBJECT_TYPE,
				tagId: (int)$id,
				newName: $name
			);
			return new JSONResponse(['success' => true, 'tag' => $tag]);
		} catch (\InvalidArgumentException|\RuntimeException $e) {
			return new JSONResponse(['success' => false, 'message' => $e->getMessage()], 400);
		}
	}              

	   
                                 
   
                                 
   
                                      
   
                                               
    
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function destroy(string $id): JSONResponse {
		try {
			$objectType = self::OBJECT_TYPE;
			$tagId = (int)$id;
			$this->systemTagService->removeTag(
				objectType: $objectType,
				tagId: $tagId
			);
			return new JSONResponse(['success' => true]);
		} catch (\Exception $e) {
			$this->logger->error('RequestChannelController::destroy failed', ['exception' => $e]);
			return new JSONResponse(['success' => false, 'message' => 'An unexpected error occurred'], 500);
		}
	}               
}           
