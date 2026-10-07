<?php

   
                                         
  
                                                                              
                                    
  
                                                                               
                                                                            
                                         
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                                                                                                                         
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\Matomo\MatomoReportService;
use OCA\Pipelinq\Service\Social\ConnectionAuditService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUserSession;

   
                                              
  
                                                                                                                                                                                         
   
class MarketingConnectorController extends Controller {

	   
                                               
   
            
    
	public const DEFAULT_WINDOW_DAYS = 28;

	   
                
   
                                    
                                     
                                                         
                                                              
                                                                  
                                             
                                                               
   
                                                                                                                                                                                          
    
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly MatomoReportService $matomo,
		private readonly ConnectionAuditService $audit,
		private readonly ITimeFactory $time,
		private readonly IUserSession $userSession,
		private readonly ObjectOwnerAccessPolicy $policy,
	) {
		parent::__construct(appName: $appName, request: $request);
	}                   

	   
                                                                
   
                                                       
                                                   
   
                                                                                              
   
                                                                                                                                                                               
    
	#[NoAdminRequired]
	public function matomo(?string $from = null, ?string $to = null): JSONResponse {
		$refusal = $this->refuseUnprivileged();
		if ($refusal !== null) {
			return $refusal;
		}

		[$start, $end] = $this->window(from: $from, to: $to);

		return new JSONResponse($this->matomo->report(from: $start, to: $end));
	}              

	   
                                                                  
   
                                                                       
   
                                             
   
                                                                                                                                                                                        
    
	#[NoAdminRequired]
	public function connectionAudit(string $refresh = 'false'): JSONResponse {
		$refusal = $this->refuseUnprivileged();
		if ($refusal !== null) {
			return $refusal;
		}

		$summary = null;
		if ($refresh === 'true') {
			$summary = $this->audit->run();
		}

		return new JSONResponse(['rows' => $this->audit->rows(), 'summary' => $summary]);
	}                       

	   
                                                                
   
                                             
                                         
   
                                                 
    
	private function window(?string $from, ?string $to): array {
		$today = (new DateTimeImmutable('@' . $this->time->getTime()))->setTimezone(new DateTimeZone('UTC'));
		$end = ($this->day(value: $to) ?? $today);
		$start = ($this->day(value: $from) ?? $end->modify('-' . self::DEFAULT_WINDOW_DAYS . ' days'));
		if ($start > $end) {
			$start = $end;
		}

		return [$start->format('Y-m-d'), $end->format('Y-m-d')];
	}              

	   
                                         
   
                                         
   
                                                         
    
	private function day(?string $value): ?DateTimeImmutable {
		if ($value === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $matches) !== 1) {
			return null;
		}

		if (checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1]) === false) {
			return null;
		}

		return new DateTimeImmutable((trim($value) . 'T00:00:00'), new DateTimeZone('UTC'));
	}           

	   
                                              
   
                                                                               
    
	private function refuseUnprivileged(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return null;
	}                          
}           
