<?php

   
                                
  
                                                                            
                                                                               
                                                                                
                                                                          
                                                                              
                                                                               
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                                    
                                               
  
                         
  
                                                 
  
                                                                                
                                                                                
                                                                                
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Export\ExportAccessPolicy;
use OCA\Pipelinq\Service\Export\ExportDestinationService;
use OCA\Pipelinq\Service\Export\ExportJobService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

   
                                                                
  
                                                  
                                                                                 
                                                                             
                                                 
  
                                                                                
   
class ExportJobController extends Controller {
	   
                
   
                                         
                                                  
                                                                          
                                                               
                                                      
                                                
                                              
    
	public function __construct(
		IRequest $request,
		private ExportJobService $jobs,
		private ExportDestinationService $destinations,
		private ExportAccessPolicy $policy,
		private IUserSession $userSession,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                             
   
                                          
   
                                                                                 
    
	#[NoAdminRequired]
	public function listDestinations(): JSONResponse {
		return $this->requireExportAdmin(
			action: fn (): array => ['destinations' => $this->destinations->listDestinations()],
			label: 'listDestinations'
		);
	}                        

	   
                                 
   
                                                 
   
                                                                                    
    
	#[NoAdminRequired]
	public function createDestination(): JSONResponse {
		$data = $this->bodyParams();
		return $this->requireExportAdmin(
			action: fn (): array => ['destination' => $this->destinations->createDestination(data: $data)],
			label: 'createDestination'
		);
	}                         

	   
                                 
   
                                           
   
                                                 
                                                                                                                                   
    
	#[NoAdminRequired]
	public function updateDestination(string $id): JSONResponse {
		$data = $this->bodyParams();
		return $this->requireExportAdmin(
			action: fn (): array => ['destination' => $this->destinations->updateDestination(id: $id, data: $data)],
			label: 'updateDestination'
		);
	}                         

	   
                                 
   
                                           
   
                                             
                                                                                                                                   
    
	#[NoAdminRequired]
	public function deleteDestination(string $id): JSONResponse {
		return $this->requireExportAdmin(
			action: function () use ($id): array {
				$this->destinations->deleteDestination(id: $id);
				return ['deleted' => true];
			},
			label: 'deleteDestination'
		);
	}                         

	   
                                       
   
                                           
   
                                         
   
                                                                                    
    
	#[NoAdminRequired]
	public function testDestination(string $id): JSONResponse {
		return $this->requireExportAdmin(
			action: fn (): array => ['valid' => $this->destinations->testConnection(id: $id)],
			label: 'testDestination'
		);
	}                       

	   
                     
   
                                  
   
                                                                                 
    
	#[NoAdminRequired]
	public function listJobs(): JSONResponse {
		return $this->requireExportAdmin(action: fn (): array => ['jobs' => $this->jobs->listJobs()], label: 'listJobs');
	}                

	   
                       
   
                                   
   
                                 
                                                                                                                                   
    
	#[NoAdminRequired]
	public function showJob(string $id): JSONResponse {
		return $this->requireExportAdmin(action: fn (): array => ['job' => $this->jobs->getJob(id: $id)], label: 'showJob');
	}               

	   
                                                           
   
                                         
   
                                                                                    
    
	#[NoAdminRequired]
	public function createJob(): JSONResponse {
		$data = $this->bodyParams();
		$uid = $this->actingUser();

		return $this->requireExportAdmin(
			action: fn (): array => ['job' => $this->jobs->createJob(data: $data, userId: $uid)],
			label: 'createJob'
		);
	}                 

	   
                         
   
                                   
   
                                         
                                                                                                                                   
    
	#[NoAdminRequired]
	public function updateJob(string $id): JSONResponse {
		$data = $this->bodyParams();
		return $this->requireExportAdmin(
			action: fn (): array => ['job' => $this->jobs->updateJob(id: $id, data: $data)],
			label: 'updateJob'
		);
	}                 

	   
                         
   
                                   
   
                                             
                                                                                                                                   
    
	#[NoAdminRequired]
	public function deleteJob(string $id): JSONResponse {
		return $this->requireExportAdmin(
			action: function () use ($id): array {
				$this->jobs->deleteJob(id: $id);
				return ['deleted' => true];
			},
			label: 'deleteJob'
		);
	}                 

	   
                                        
   
                                   
   
                                                          
   
                                                                                    
    
	#[NoAdminRequired]
	public function testRun(string $id): JSONResponse {
		return $this->requireExportAdmin(
			action: function () use ($id): array {
				$job = $this->jobs->getJob(id: $id);
				return ['result' => $this->jobs->testRun(job: $job)];
			},
			label: 'testRun'
		);
	}               

	   
                                         
   
                                   
   
                                         
   
                                                                                 
    
	#[NoAdminRequired]
	public function enableJob(string $id): JSONResponse {
		return $this->requireExportAdmin(action: fn (): array => ['job' => $this->jobs->enableJob(id: $id)], label: 'enableJob');
	}                 

	   
                  
   
                                   
   
                                          
                                                                                                                                   
    
	#[NoAdminRequired]
	public function disableJob(string $id): JSONResponse {
		return $this->requireExportAdmin(action: fn (): array => ['job' => $this->jobs->disableJob(id: $id)], label: 'disableJob');
	}                  

	   
                                                
   
                           
    
	private function actingUser(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();
	}                  

	   
                                                      
   
                                                     
    
	private function bodyParams(): array {
		$params = $this->request->getParams();
		unset($params['id'], $params['_route']);

		return $params;
	}                  

	   
                                                                          
                          
   
                                                                           
                                                               
   
                                       
                                   
   
                                      
    
	private function requireExportAdmin(callable $action, string $label): JSONResponse {
		$uid = $this->actingUser();
		if ($uid === '') {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->policy->isExportAdmin(userId: $uid) === false) {
			return new JSONResponse(['error' => $this->l10n->t('Insufficient permissions')], Http::STATUS_FORBIDDEN);
		}

		try {
			return new JSONResponse($action());
		} catch (OCSNotFoundException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (\Throwable $e) {
			$this->logger->error('ExportJobController::' . $label . ' failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(
				['error' => $this->l10n->t('An unexpected error occurred')],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}         
	}                          
}           
