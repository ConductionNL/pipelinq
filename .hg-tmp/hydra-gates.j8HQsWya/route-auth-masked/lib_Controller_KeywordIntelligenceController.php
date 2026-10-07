<?php

   
                                          
  
                                                                              
                                   
  
                                                                              
                                                                             
                                                                               
                                                                             
               
  
                                                                              
                                                                       
                                   
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                                                                                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\Search\KeywordAnalysisService;
use OCA\Pipelinq\Service\Search\KeywordTargetService;
use OCA\Pipelinq\Service\Search\SiteContentCrawler;
use OCA\Pipelinq\Service\SearchConsole\SearchQueryReportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use UnexpectedValueException;

   
                                                                        
  
                                                                                                                                                                                   
   
class KeywordIntelligenceController extends Controller {

	   
                                                                         
   
               
    
	public const FLOOR_KEY = 'search.striking_min_impressions';

	   
                
   
                                    
                                     
                                                                                   
                                                                 
                                                                        
                                                               
                                                     
                                             
                                                               
   
                                                                                                                                                                                    
    
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly SearchQueryReportService $report,
		private readonly KeywordAnalysisService $analysis,
		private readonly SiteContentCrawler $crawler,
		private readonly KeywordTargetService $targets,
		private readonly IAppConfig $appConfig,
		private readonly IUserSession $userSession,
		private readonly ObjectOwnerAccessPolicy $policy,
	) {
		parent::__construct(appName: $appName, request: $request);
	}                   

	   
                                                   
   
                                                       
                                                   
                                                          
   
                                                                                                                         
   
                                                                                                                                                           
    
	#[NoAdminRequired]
	public function proposals(?string $from = null, ?string $to = null, ?string $property = null): JSONResponse {
		$refusal = $this->refuseUnprivileged();
		if ($refusal !== null) {
			return $refusal;
		}

		$window = $this->report->rows(from: $from, to: $to, property: $property);
		$rows = $window['rows'];
		$floor = $this->floor();
		$crawl = $this->crawler->crawl(rows: $rows);

		return new JSONResponse(
			[
				'from' => $window['from'],
				'to' => $window['to'],
				'minImpressions' => $floor,
				'buckets' => $this->analysis->positionBuckets(rows: $rows),
				'strikingDistance' => $this->analysis->strikingDistance(rows: $rows, minImpressions: $floor),
				'cannibalisation' => $this->analysis->cannibalisation(rows: $rows, minImpressions: $floor),
				'gaps' => $this->analysis->contentGaps(rows: $rows, pages: $crawl['pages'], minImpressions: $floor),
				'crawl' => [
					'crawled' => $crawl['crawled'],
					'failure' => $crawl['failure'],
					'reason' => $crawl['reason'],
					'pages' => count($crawl['pages']),
				],
				'confirmedTerms' => $this->targets->confirmedTerms(),
			]
		);
	}                 

	   
                                          
   
                                       
   
                                                                                                                                                                                    
    
	#[NoAdminRequired]
	public function targets(): JSONResponse {
		$refusal = $this->refuseUnprivileged();
		if ($refusal !== null) {
			return $refusal;
		}

		return new JSONResponse(['targets' => $this->targets->all()]);
	}               

	   
                                             
   
                                        
                                                                             
                                                             
                                                                  
                                                             
                                                                
                                        
   
                                                           
   
                                                                                                                                                                                    
    
	#[NoAdminRequired]
	public function confirm(
		string $term = '',
		string $status = 'watch',
		string $proposalKind = 'manual',
		string $intent = '',
		string $targetPageRef = '',
		string $property = '',
		string $notes = '',
	): JSONResponse {
		$refusal = $this->refuseUnprivileged();
		if ($refusal !== null) {
			return $refusal;
		}

		$user = $this->userSession->getUser();
		$uid = '';
		if ($user !== null) {
			$uid = $user->getUID();
		}

		try {
			$saved = $this->targets->confirm(
				payload: [
					'term' => $term,
					'status' => $status,
					'proposalKind' => $proposalKind,
					'intent' => $intent,
					'targetPageRef' => $targetPageRef,
					'property' => $property,
					'notes' => $notes,
				],
				uid: $uid
			);
		} catch (UnexpectedValueException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		if ($saved === null) {
			return new JSONResponse(['error' => 'The keyword target could not be stored.'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse($saved, Http::STATUS_CREATED);
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

	   
                                                                    
                                             
   
               
    
	private function floor(): int {
		$configured = (int)$this->appConfig->getValueString(Application::APP_ID, self::FLOOR_KEY, '');
		if ($configured < 1) {
			return KeywordAnalysisService::DEFAULT_MIN_IMPRESSIONS;
		}

		return $configured;
	}             
}           
