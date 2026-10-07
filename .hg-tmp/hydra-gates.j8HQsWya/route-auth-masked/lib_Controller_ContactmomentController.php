<?php

   
                                    
  
                                                                                   
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                             
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ContactmomentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\NotPermittedException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

   
                                               
  
                                                                                  
   
class ContactmomentController extends Controller {
	   
                
   
                                         
                                                                                
                                                      
                                                
                                              
    
	public function __construct(
		IRequest $request,
		private ContactmomentService $contactmomentService,
		private IUserSession $userSession,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                           
   
                                                   
   
                                           
   
                                      
   
                    
   
                                                                                              
    
	public function destroy(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authentication required')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		try {
			$this->contactmomentService->delete(
				$id,
				$user->getUID()
			);
			return new JSONResponse(['success' => true]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(
				['error' => $this->l10n->t('Contactmoment not found')],
				404
			);
		} catch (NotPermittedException $e) {
			return new JSONResponse(
				['error' => $this->l10n->t('You do not have permission to delete this contactmoment')],
				403
			);
		} catch (\Exception $e) {
			$this->logger->error('ContactmomentController::destroy failed', ['exception' => $e]);
			return new JSONResponse(
				['error' => $this->l10n->t('Operation failed')],
				500
			);
		}         
	}               
}           
