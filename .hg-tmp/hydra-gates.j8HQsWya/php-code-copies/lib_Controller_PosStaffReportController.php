<?php

   
                                     
  
                                                          
                                                                          
                            
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCA\Pipelinq\Service\PosStaffReportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

   
                                             
  
                                                                    
                                                                           
                                                                    
  
                                                                 
   
class PosStaffReportController extends Controller {
	   
                
   
                                         
                                                             
                                                                             
                                                      
                                                
                                              
    
	public function __construct(
		IRequest $request,
		private PosStaffReportService $service,
		private PosAccessPolicy $policy,
		private IUserSession $userSession,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                       
   
                                                                         
                                                                 
                                                             
   
                                            
   
                                                                  
    
	#[NoAdminRequired]
	public function staffSales(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authentication required')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		if ($this->policy->isManager(userId: $user->getUID()) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('Manager privileges required')],
				Http::STATUS_FORBIDDEN
			);
		}

		try {
			$report = $this->service->staffSalesReport();
		} catch (\Throwable $e) {
			$this->logger->error('PosStaffReportController::staffSales failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(
				['error' => $this->l10n->t('An unexpected error occurred')],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new JSONResponse(['report' => $report]);
	}                  
}           
