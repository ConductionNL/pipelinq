<?php

   
                                
  
                                                                 
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                    
                                                                                        
                                                                                                
  
                                                                    
                                    
   
                                                                    
declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use DateTimeImmutable;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\ReportingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

   
                                                            
  
                                                                             
                                                                              
                                                                          
                                                                              
  
                                                                    
   
class ReportingController extends Controller {
	   
                
   
                                         
                                                                    
                                                      
                                                                                
    
	public function __construct(
		IRequest $request,
		private ReportingService $reportingService,
		private IUserSession $userSession,
		private ObjectOwnerAccessPolicy $accessPolicy,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                          
   
                                                                                  
   
                    
   
                                                                     
    
	public function getKpis(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		                                                                    
		if ($this->accessPolicy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		[$from, $to] = $this->resolvePeriodRange();

		if ($from === '' || $to === '') {
			return new JSONResponse(['message' => 'Missing required parameters: from, to (or period)'], Http::STATUS_BAD_REQUEST);
		}

		                        
		if ($this->isValidDate(date: $from) === false || $this->isValidDate(date: $to) === false) {
			return new JSONResponse(['message' => 'Invalid date format. Use ISO 8601 (YYYY-MM-DD or full datetime)'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$kpis = $this->reportingService->getKpis($from, $to);
			return new JSONResponse($kpis);
		} catch (\Throwable) {
			return new JSONResponse(['message' => 'Operation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}         
	}               

	   
                                            
   
                                                                           
   
                    
   
                                                                     
    
	public function getChannels(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		                                                                    
		if ($this->accessPolicy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		[$from, $to] = $this->resolvePeriodRange();
		$granularity = $this->request->getParam('granularity', 'daily');

		if ($from === '' || $to === '') {
			return new JSONResponse(['message' => 'Missing required parameters: from, to (or period)'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->isValidDate(date: $from) === false || $this->isValidDate(date: $to) === false) {
			return new JSONResponse(['message' => 'Invalid date format. Use ISO 8601 (YYYY-MM-DD or full datetime)'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$distribution = $this->reportingService->getChannelDistribution($from, $to);
			$trend = $this->reportingService->getChannelTrend($from, $to, $granularity);

			return new JSONResponse(
				[
					'distribution' => $distribution,
					'trend' => $trend,
				]
			);
		} catch (\Throwable) {
			return new JSONResponse(['message' => 'Operation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}         
	}                   

	   
                                    
   
                                                                            
   
                    
   
                                                                     
    
	public function getAgents(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		                                                                    
		if ($this->accessPolicy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		[$from, $to] = $this->resolvePeriodRange();

		if ($from === '' || $to === '') {
			return new JSONResponse(['message' => 'Missing required parameters: from, to (or period)'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->isValidDate(date: $from) === false || $this->isValidDate(date: $to) === false) {
			return new JSONResponse(['message' => 'Invalid date format. Use ISO 8601 (YYYY-MM-DD or full datetime)'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$agents = $this->reportingService->getAgentPerformance($from, $to);
			return new JSONResponse(['agents' => $agents]);
		} catch (\Throwable) {
			return new JSONResponse(['message' => 'Operation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}         
	}                 

	   
                          
   
                                         
   
                    
   
                                                                                         
    
	public function getSla(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		                                                                    
		if ($this->accessPolicy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		try {
			$targets = $this->reportingService->getAllSlaTargets();
			return new JSONResponse(['targets' => $targets]);
		} catch (\Throwable) {
			return new JSONResponse(['message' => 'Operation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}         
	}              

	   
                             
   
                                                             
   
                                                 
   
                                                                                         
    
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function updateSla(): JSONResponse {
		try {
			$targets = $this->request->getParam('targets', []);

			if (is_array($targets) === false) {
				return new JSONResponse(['message' => 'Operation failed'], Http::STATUS_BAD_REQUEST);
			}

			$skipped = 0;
			foreach ($targets as $channel => $metrics) {
				if (is_array($metrics) === false) {
					continue;
				}

				foreach ($metrics as $metric => $value) {
					$accepted = $this->reportingService->setSlaTarget(
						channel: $channel,
						metric: $metric,
						value: (string)$value,
					);
					if ($accepted === false) {
						$skipped++;
					}
				}
			}

			if ($skipped > 0) {
				return new JSONResponse(['message' => 'Operation failed'], Http::STATUS_BAD_REQUEST);
			}

			return new JSONResponse(
				[
					'success' => true,
					'targets' => $this->reportingService->getAllSlaTargets(),
				]
			);
		} catch (\Throwable) {
			return new JSONResponse(['message' => 'Operation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}         
	}                 

	   
                                 
   
                                                                        
   
                    
   
                                                                                                 
    
	public function exportCsv(): DataDownloadResponse|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		                                                                    
		if ($this->accessPolicy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		                                                     
		                                                           
		return new JSONResponse(
			['message' => 'Export not yet implemented'],
			Http::STATUS_NOT_IMPLEMENTED,
		);
	}                 

	   
                                                                            
                                                            
   
                                                                         
                                                                    
                                                                        
                                                                          
                                                             
   
                                                                       
    
	private function resolvePeriodRange(): array {
		$from = (string)$this->request->getParam('from', '');
		$to = (string)$this->request->getParam('to', '');
		if ($from !== '' || $to !== '') {
			return [$from, $to];
		}

		$period = (string)$this->request->getParam('period', '');
		if ($period === '') {
			return ['', ''];
		}

		$now = new DateTimeImmutable('now');
		$fmt = 'Y-m-d';
		switch ($period) {
			case 'today':
				$today = $now->format($fmt);
				return [$today, $today];
			case 'week':
				$dayNum = (int)$now->format('N');
				$monday = $now->modify('-' . ($dayNum - 1) . ' days');
				return [$monday->format($fmt), $now->format($fmt)];
			case 'month':
				return [$now->format('Y-m-01'), $now->format($fmt)];
			default:
				return ['', ''];
		}            
	}                          

	   
                                                           
   
                                                    
   
                                                    
    
	private function isValidDate(string $date): bool {
		if ($date === '') {
			return false;
		}

		try {
			new DateTimeImmutable($date);
			return true;
		} catch (\Exception) {
			return false;
		}         
	}                   
}           
