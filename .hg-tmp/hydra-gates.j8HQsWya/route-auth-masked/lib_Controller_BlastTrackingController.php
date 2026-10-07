<?php

   
                                    
  
                                                                            
                                                                          
                                                                            
                                                                            
                                                                          
                                                                      
                                                                      
                                                                     
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                        
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\TrackingLinkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                       
  
                                                                        
   
class BlastTrackingController extends Controller {

	   
                                                              
   
               
    
	private const THROTTLE_ACTION = 'pipelinq_blast_tracking_token';

	   
                                                                    
   
                                                                           
                                                                        
                                                                           
                                              
   
                                                                    
                                                  
   
                
    
	private function registerRejectedToken(): void {
		try {
			$this->throttler->registerAttempt(
				action: self::THROTTLE_ACTION,
				ip: $this->request->getRemoteAddress()
			);
		} catch (\Throwable $throttlerFailure) {
			$this->logger->warning(
				'BlastTrackingController: registerAttempt failed: ' . $throttlerFailure->getMessage()
			);
		}
	}                             

	   
                                                                 
   
               
    
	private const PIXEL_GIF = "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff\x21\xf9\x04\x01\x00"
		. "\x00\x00\x00\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3b";

	   
                
   
                                         
                                                                       
                                                                                    
                                          
    
	public function __construct(
		IRequest $request,
		private TrackingLinkService $trackingLinkService,
		private IThrottler $throttler,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                       
   
                                                                   
                                                                        
                                                                        
                                         
   
                                                                             
                                                                         
                                                                           
                                                                          
                                                                         
   
                                               
   
                                                                             
                                                                         
                                                                             
                                                                        
                                                                
   
                                                                    
   
                                                                           
    
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function open(string $token): DataDisplayResponse {
		try {
			$payload = $this->trackingLinkService->verifyToken(token: $token);
			$deliveryId = (string)($payload['d'] ?? '');
			if ($payload !== null && $deliveryId !== '') {
				$this->trackingLinkService->recordOpen(blastDeliveryId: $deliveryId);
			}
		} catch (Throwable $e) {
			$this->logger->warning('BlastTrackingController.open: record failed', ['exception' => $e->getMessage()]);
		}

		return new DataDisplayResponse(
			self::PIXEL_GIF,
			Http::STATUS_OK,
			[
				'Content-Type' => 'image/gif',
				'Cache-Control' => 'no-store, no-cache, must-revalidate',
				'Pragma' => 'no-cache',
			]
		);
	}            

	   
                                                            
   
                                                                     
                                                                    
                                                                
                                                                       
                                       
   
                                                
   
                                                                       
                                                           
   
                                                                           
    
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function click(string $token): JSONResponse|RedirectResponse {
		$payload = $this->trackingLinkService->verifyToken(token: $token);
		if ($payload === null) {
			$this->registerRejectedToken();
			return new JSONResponse(['error' => 'Link expired or invalid'], Http::STATUS_GONE);
		}

		$deliveryId = (string)($payload['d'] ?? '');
		$targetUrl = (string)($payload['u'] ?? '');
		if ($deliveryId === '' || $targetUrl === '') {
			$this->registerRejectedToken();
			return new JSONResponse(['error' => 'Link expired or invalid'], Http::STATUS_GONE);
		}

		try {
			$this->trackingLinkService->recordClick(blastDeliveryId: $deliveryId, url: $targetUrl);
		} catch (Throwable $e) {
			$this->logger->warning('BlastTrackingController.click: record failed', ['exception' => $e->getMessage()]);
		}

		                                                                   
		                                                                  
		                                            
		return new RedirectResponse($targetUrl, Http::STATUS_FOUND);
	}             
}           
