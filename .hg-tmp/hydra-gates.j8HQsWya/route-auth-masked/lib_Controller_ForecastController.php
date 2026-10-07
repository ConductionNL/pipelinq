<?php

   
                               
  
                                                                               
                                                                         
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                           
  
                                                  
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ForecastAccessPolicy;
use OCA\Pipelinq\Service\ForecastExportService;
use OCA\Pipelinq\Service\ForecastOverrideService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

   
                                
  
                                                                        
                                                                             
                                                                              
                                                              
  
                                                                                        
                                                     
   
class ForecastController extends Controller {
	   
                                                      
   
                           
    
	private const READ_LEVELS = ['rep', 'team', 'division', 'company'];

	   
                
   
                                         
                                                      
                                                                         
                                                                            
                                                                                    
                                              
    
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private ForecastAccessPolicy $accessPolicy,
		private ForecastExportService $exportService,
		private ForecastOverrideService $overrideService,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                                   
   
                                                 
                                             
                                                      
                                                       
                                              
                                       
   
                                         
   
                                                                                                  
    
	#[NoAdminRequired]
	public function snapshots(
		string $periodId = '',
		string $level = '',
		?string $ownerId = null,
		string $format = 'json',
		int $limit = 50,
		int $offset = 0,
	): Response {
		$uid = $this->currentUserId();
		if ($uid === '') {
			return new JSONResponse(['error' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
		}

		if ($periodId === '' || in_array($level, self::READ_LEVELS, true) === false) {
			return new JSONResponse(['error' => 'A valid period and level are required.'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->accessPolicy->canRead(userId: $uid, level: $level, ownerId: $ownerId) === false) {
			$this->logger->warning('Pipelinq: forecast read denied', ['uid' => $uid, 'level' => $level, 'ownerId' => $ownerId]);
			return new JSONResponse(['error' => 'You may not read forecasts at this scope.'], Http::STATUS_FORBIDDEN);
		}

		$limit = max(1, min($limit, 200));
		$offset = max(0, $offset);

		$page = $this->exportService->exportSnapshots(
			periodId: $periodId,
			level: $level,
			ownerId: $ownerId,
			limit: $limit,
			offset: $offset
		);

		if (strtolower($format) === 'csv') {
			$csv = $this->exportService->toCsv($page['snapshots']);
			return new DataDownloadResponse(
				data: $csv,
				filename: sprintf('forecast-%s-%s.csv', $periodId, $level),
				contentType: 'text/csv; charset=UTF-8'
			);
		}

		return new JSONResponse($page);
	}                 

	   
                               
   
                                                          
   
                                                                                                  
    
	#[NoAdminRequired]
	public function createOverride(): JSONResponse {
		$uid = $this->currentUserId();
		if ($uid === '') {
			return new JSONResponse(['error' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
		}

		$level = (string)$this->request->getParam('level', '');
		if ($this->accessPolicy->canOverride(userId: $uid, level: $level) === false) {
			$this->logger->warning('Pipelinq: forecast override denied', ['uid' => $uid, 'level' => $level]);
			return new JSONResponse(['error' => 'You may not override forecasts at this scope.'], Http::STATUS_FORBIDDEN);
		}

		$payload = [
			'period_id' => (string)$this->request->getParam('period_id', ''),
			'override_owner_id' => (string)$this->request->getParam('override_owner_id', ''),
			'level' => $level,
			'category' => (string)$this->request->getParam('category', 'commit'),
			'override_amount' => $this->request->getParam('override_amount'),
			'reason' => (string)$this->request->getParam('reason', ''),
		];

		$error = $this->overrideService->validatePayload($payload);
		if ($error !== null) {
			return new JSONResponse(['error' => $error], Http::STATUS_BAD_REQUEST);
		}

		try {
			$override = $this->overrideService->createOverride(payload: $payload, managerId: $uid);
		} catch (\Throwable $e) {
			$this->logger->error('Pipelinq: failed to create forecast override', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'Could not create the override.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($override, Http::STATUS_CREATED);
	}                      

	   
                                                                              
   
                                        
   
                                             
   
                                                                                  
    
	#[NoAdminRequired]
	public function deleteOverride(string $id): JSONResponse {
		$uid = $this->currentUserId();
		if ($uid === '') {
			return new JSONResponse(['error' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
		}

		$existing = $this->overrideService->getOverride($id);
		if ($existing === null) {
			return new JSONResponse(['error' => 'Override not found.'], Http::STATUS_NOT_FOUND);
		}

		$level = (string)($existing['level'] ?? '');
		if ($this->accessPolicy->canOverride(userId: $uid, level: $level) === false) {
			$this->logger->warning('Pipelinq: forecast override delete denied', ['uid' => $uid, 'id' => $id]);
			return new JSONResponse(['error' => 'You may not delete this override.'], Http::STATUS_FORBIDDEN);
		}

		$deleted = $this->overrideService->deleteOverride($id);
		if ($deleted === false) {
			return new JSONResponse(['error' => 'Could not delete the override.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['deleted' => true]);
	}                      

	   
                                              
   
                                                                      
    
	private function currentUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();
	}                     
}           
