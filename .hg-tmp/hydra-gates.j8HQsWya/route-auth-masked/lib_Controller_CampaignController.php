<?php

   
                               
  
                                                                         
                                                                        
                                                                      
                                                     
  
                                                                         
                                                                        
             
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                    
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\CampaignReportService;
use OCA\Pipelinq\Service\CampaignService;
use OCA\Pipelinq\Service\LandingPageProvisioningService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

   
                                                                       
  
                                                                                                                    
   
class CampaignController extends Controller {

	   
                                                                  
   
                                                                 
                                                                       
                                                                      
                                                                     
                                                  
   
                           
    
	private const ERROR_STATUS = [
		'not_found' => Http::STATUS_NOT_FOUND,
		'unknown_portal' => Http::STATUS_NOT_FOUND,
		'duplicate_route' => Http::STATUS_CONFLICT,
		'invalid_article' => Http::STATUS_UNPROCESSABLE_ENTITY,
		'invalid_form' => Http::STATUS_UNPROCESSABLE_ENTITY,
		'write_failed' => Http::STATUS_BAD_GATEWAY,
		'portaliq_missing' => Http::STATUS_NOT_IMPLEMENTED,
	];

	   
                                                                         
                                                                      
                                                        
   
                           
    
	private const WRITE_STATUS = [
		'not_found' => Http::STATUS_NOT_FOUND,
		'name_required' => Http::STATUS_BAD_REQUEST,
		'unknown_utm_source' => Http::STATUS_UNPROCESSABLE_ENTITY,
		'unknown_utm_medium' => Http::STATUS_UNPROCESSABLE_ENTITY,
		'write_failed' => Http::STATUS_INTERNAL_SERVER_ERROR,
	];

	   
                
   
                                    
                                     
                                                              
                                                                                         
                                                                              
                                             
                                                               
   
                                                                                                                     
    
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CampaignReportService $report,
		private readonly CampaignService $campaigns,
		private readonly LandingPageProvisioningService $landingPages,
		private readonly IUserSession $userSession,
		private readonly ObjectOwnerAccessPolicy $policy,
	) {
		parent::__construct(appName: $appName, request: $request);
	}                   

	   
                                                                
   
                                                                       
                                                                         
                                                         
   
                                                  
   
                                                                                                                                                          
    
	#[NoAdminRequired]
	public function vocabularies(): JSONResponse {
		$denied = $this->guard();
		if ($denied !== null) {
			return $denied;
		}

		return new JSONResponse($this->campaigns->vocabularies());
	}                    

	   
                      
   
                                                                     
                                                                  
                                                                     
                                                                   
                                                                    
                               
   
                                                                              
   
                                                                                                                                                          
    
	#[NoAdminRequired]
	public function create(): JSONResponse {
		return $this->write(id: null);
	}              

	   
                                                               
   
                                              
   
                                                                            
   
                                                                                                                                                          
    
	#[NoAdminRequired]
	public function update(string $id): JSONResponse {
		return $this->write(id: $id);
	}              

	   
                                                       
   
                                              
                                                       
                                                   
   
                                                                                                     
   
                                                                                                                     
    
	#[NoAdminRequired]
	public function report(string $id, ?string $from = null, ?string $to = null): JSONResponse {
		$denied = $this->guard();
		if ($denied !== null) {
			return $denied;
		}

		$record = $this->report->forCampaign(campaignId: $id, from: $from, to: $to);
		if ($record === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($record);
	}              

	   
                                                             
   
                                                                     
                                                                      
                                     
   
                                              
                                                                         
                                                                                        
   
                                                                             
   
                                                                                                                                            
    
	#[NoAdminRequired]
	public function createLandingPage(string $id, string $portal = '', string $route = ''): JSONResponse {
		$denied = $this->guard();
		if ($denied !== null) {
			return $denied;
		}

		$result = $this->landingPages->createFor(campaignId: $id, portal: $portal, route: $route);
		if ($result['error'] === '') {
			return new JSONResponse($result);
		}

		return new JSONResponse($result, (self::ERROR_STATUS[$result['error']] ?? Http::STATUS_BAD_GATEWAY));
	}                         

	   
                                                                      
                               
   
                                                                       
                                              
   
                                                                     
   
                                                            
    
	private function write(?string $id): JSONResponse {
		$denied = $this->guard();
		if ($denied !== null) {
			return $denied;
		}

		$body = $this->request->getParams();
		unset($body['id'], $body['_route'], $body['createdBy'], $body['utmCampaign']);

		$user = $this->userSession->getUser();
		$uid = '';
		if ($user !== null) {
			$uid = $user->getUID();
		}

		$result = $this->campaigns->save(payload: $body, id: $id, uid: $uid);

		if ($result['error'] === '') {
			return new JSONResponse($result['campaign']);
		}

		return new JSONResponse(
			['error' => $result['error'], 'value' => $result['value'], 'allowed' => $result['allowed']],
			(self::WRITE_STATUS[$result['error']] ?? Http::STATUS_BAD_REQUEST)
		);
	}             

	   
                                                           
   
                                                                               
    
	private function guard(): ?JSONResponse {
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
