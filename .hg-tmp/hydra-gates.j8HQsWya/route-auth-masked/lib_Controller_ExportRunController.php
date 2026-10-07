<?php

   
                                
  
                                                                                
                                                                        
                                                                             
                                                                                
                                                                                 
                                                                                
                                                                               
                                                                         
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                                    
                                               
  
                         
  
                                                 
  
                                                                                
                                                                                
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Export\ExportAccessPolicy;
use OCA\Pipelinq\Service\Export\ExportJobService;
use OCA\Pipelinq\Service\Export\ExportRunService;
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

   
                                                       
  
                                                                          
                                                                          
  
                                                                                
   
class ExportRunController extends Controller {
	   
                
   
                                         
                                                  
                                                          
                                                               
                                                      
                                                
                                              
    
	public function __construct(
		IRequest $request,
		private ExportRunService $runs,
		private ExportJobService $jobs,
		private ExportAccessPolicy $policy,
		private IUserSession $userSession,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                
   
                                                            
   
                                           
   
                                                                                    
    
	#[NoAdminRequired]
	public function listRuns(): JSONResponse {
		$filters = [
			'job_id' => (string)$this->request->getParam('job_id', ''),
			'status' => (string)$this->request->getParam('status', ''),
			'date_from' => (string)$this->request->getParam('date_from', ''),
			'date_to' => (string)$this->request->getParam('date_to', ''),
		];

		return $this->requireExportAdmin(
			action: fn (): array => ['runs' => $this->runs->listRuns(filters: $filters)],
			label: 'listRuns'
		);
	}                

	   
                                                                    
   
                                   
   
                                        
   
                                                                                    
    
	#[NoAdminRequired]
	public function showRun(string $id): JSONResponse {
		return $this->requireExportAdmin(
			action: function () use ($id): array {
				$run = $this->runs->getRun(runId: $id);
				return [
					'run' => $run,
					'snapshots' => $this->runs->listSnapshots(runId: $id),
				];
			},
			label: 'showRun'
		);
	}               

	   
                                                                     
   
                                                                            
                                                                           
                                                                           
   
                                            
   
                                                       
   
                                                                                    
    
	#[NoAdminRequired]
	public function retryRun(string $id): JSONResponse {
		return $this->requireExportAdmin(
			action: function () use ($id): array {
				$run = $this->runs->getRun(runId: $id);
				$status = (string)($run['status'] ?? '');
				if (in_array($status, ['failed', 'partial', 'skipped_overlap'], true) === false) {
					throw new OCSBadRequestException(
						'Only failed, partial or skipped runs can be retried.'
					);
				}

				$job = $this->jobs->getJob(id: (string)($run['jobId'] ?? ''));

				$watermarkFrom = null;
				if ((string)($job['mode'] ?? 'full') === 'incremental') {
					$previous = $this->runs->lastSucceededRun(jobId: (string)($job['id'] ?? $job['uuid'] ?? ''));
					$watermarkRaw = ($previous['watermarkTo'] ?? null);
					if ($watermarkRaw !== null) {
						$watermarkFrom = (string)$watermarkRaw;
					}
				}

				return [
					'run' => $this->runs->createPendingRun(
						job: $job,
						watermarkFrom: $watermarkFrom
					),
				];
			},
			label: 'retryRun'
		);
	}                

	   
                                                
   
                           
    
	private function actingUser(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();
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
			$this->logger->error('ExportRunController::' . $label . ' failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(
				['error' => $this->l10n->t('An unexpected error occurred')],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}         
	}                          
}           
