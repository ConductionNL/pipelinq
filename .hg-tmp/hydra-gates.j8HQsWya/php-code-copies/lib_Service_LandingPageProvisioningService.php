<?php

   
                                           
  
                                                                         
                                                                    
                                                                       
  
                                                                       
                                                                   
                                                                    
                                                                   
                                                                       
                                             
  
                                                                  
                                                                     
                                                                       
          
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                                           
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                           
  
                                                                                                                                           
   
class LandingPageProvisioningService {

	   
                                                                   
                                                            
   
               
    
	public const REQUEST_EVENT_CLASS = 'OCA\\Portaliq\\Event\\LandingPageRequestedEvent';

	   
                                                                     
                                                                   
   
               
    
	public const SOURCE_APP = 'pipelinq';

	   
                                                                   
                                                                      
                                                                       
   
               
    
	public const PORTAL_CONFIG_KEY = 'marketing.landing_portal';

	   
                                                                  
                                                                       
   
               
    
	public const ERROR_PORTALIQ_MISSING = 'portaliq_missing';

	   
                
   
                                                                 
                                                                     
                                                     
                                                                    
                                          
   
                                                                                                                                            
    
	public function __construct(
		private IEventDispatcher $dispatcher,
		private CampaignService $campaigns,
		private IAppConfig $appConfig,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                           
   
                                           
                                                                         
                                                                                        
   
                                                                                                                  
                                                                                             
   
                                                                                                                                            
    
	public function createFor(string $campaignId, string $portal = '', string $route = ''): array {
		$campaign = $this->campaigns->find(id: $campaignId);
		if ($campaign === null) {
			return $this->failure(error: 'not_found');
		}

		$targetPortal = trim($portal);
		if ($targetPortal === '') {
			$targetPortal = $this->configuredPortal();
		}

		if ($targetPortal === '') {
			return $this->failure(error: 'unknown_portal');
		}

		$targetRoute = $this->routeFor(campaign: $campaign, requested: $route);

		if ($this->isPortaliqInstalled() === false) {
			return $this->failure(error: self::ERROR_PORTALIQ_MISSING);
		}

		return $this->dispatchAndRecord(
			campaignId: $campaignId,
			campaign: $campaign,
			portal: $targetPortal,
			route: $targetRoute
		);
	}                 

	   
                                                                    
                                             
   
                                               
   
                                                                                                                                            
    
	protected function isPortaliqInstalled(): bool {
		return class_exists(self::REQUEST_EVENT_CLASS);
	}                           

	   
                                                       
   
                                                                       
                                                                    
                                                    
   
                                                       
   
                                                                            
   
                                                                                                                                            
    
	public function articleFor(array $campaign): array {
		return [
			'summary' => trim((string)($campaign['articleSummary'] ?? '')),
			'body' => trim((string)($campaign['articleBody'] ?? '')),
			'heroImageRef' => null,
			'links' => [],
		];
	}                  

	   
                                                     
   
                                                                   
                                                                       
                                
   
                                                                        
   
                                                                                                                                            
    
	public function formDefinition(): array {
		return [
			'fields' => [
				['id' => 'name', 'label' => $this->l10n->t('Name'), 'type' => 'text', 'required' => true],
				['id' => 'email', 'label' => $this->l10n->t('Email'), 'type' => 'email', 'required' => true],
				['id' => 'organisation', 'label' => $this->l10n->t('Organisation'), 'type' => 'text', 'required' => false],
			],
			'submitLabel' => $this->l10n->t('Sign up'),
			'consentText' => $this->l10n->t('We use your details to answer your request. You can ask us to delete them at any time.'),
		];
	}                      

	   
                                                                
   
                                                       
                                                                     
   
                                                
   
                                                                                                                                            
    
	public function routeFor(array $campaign, string $requested = ''): string {
		$route = trim($requested);
		if ($route === '') {
			$route = ('/campagne/' . trim((string)($campaign['utmCampaign'] ?? '')));
		}

		if (str_starts_with($route, '/') === false) {
			$route = ('/' . $route);
		}

		return rtrim($route, '/');
	}                

	   
                                                           
   
                                              
                                                           
                                          
                                             
   
                                                                                                                  
    
	private function dispatchAndRecord(string $campaignId, array $campaign, string $portal, string $route): array {
		$eventClass = self::REQUEST_EVENT_CLASS;

		try {
			$event = new $eventClass(
				self::SOURCE_APP,
				$portal,
				$route,
				trim((string)($campaign['name'] ?? '')),
				$this->l10n->getLanguageCode(),
				$this->articleFor(campaign: $campaign),
				$this->formDefinition(),
				[
					'campaign' => (string)($campaign['utmCampaign'] ?? ''),
					'source' => (string)($campaign['utmSource'] ?? ''),
					'medium' => (string)($campaign['utmMedium'] ?? ''),
				],
				('pipelinq:campaign:' . $campaignId),
				('pipelinq-campaign-' . $campaignId)
			);
			$this->dispatcher->dispatchTyped($event);
		} catch (Throwable $e) {
			$this->logger->warning(
				'LandingPageProvisioningService: dispatching the landing page request failed',
				['campaign' => $campaignId, 'portal' => $portal, 'exception' => $e->getMessage()]
			);
			return $this->failure(error: 'write_failed');
		}

		$error = (string)($event->getError() ?? '');
		if ($error !== '' || $event->isHandled() === false) {
			if ($error === '') {
				$error = self::ERROR_PORTALIQ_MISSING;
			}

			return $this->failure(error: $error, portal: $portal, route: $route);
		}

		$landingPage = [
			'portal' => $portal,
			'route' => $this->firstNonEmpty(preferred: $event->getRoute(), fallback: $route),
			'pageId' => (string)($event->getPageId() ?? ''),
			'publicUrl' => (string)($event->getPublicUrl() ?? ''),
			'createdAt' => gmdate('Y-m-d\TH:i:sP'),
		];
		$formId = (string)($event->getFormId() ?? '');

		$this->campaigns->recordLandingPage(id: $campaignId, landingPage: $landingPage, formId: $formId);

		return [
			'error' => '',
			'portal' => $portal,
			'route' => $landingPage['route'],
			'pageId' => $landingPage['pageId'],
			'publicUrl' => $landingPage['publicUrl'],
			'formId' => $formId,
		];
	}                         

	   
                                              
   
                                                       
                                               
   
                             
    
	private function firstNonEmpty(string $preferred, string $fallback): string {
		if (trim($preferred) !== '') {
			return $preferred;
		}

		return $fallback;
	}                     

	   
                                             
   
                                                                        
    
	private function configuredPortal(): string {
		$portal = trim($this->appConfig->getValueString(Application::APP_ID, self::PORTAL_CONFIG_KEY, ''));
		if ($portal !== '') {
			return $portal;
		}

		return trim($this->appConfig->getValueString(Application::APP_ID, TrafficEventEmitter::PORTAL_CONFIG_KEY, ''));
	}                        

	   
                                                                      
   
                                                   
                                                    
                                                  
   
                                                                                                                  
    
	private function failure(string $error, string $portal = '', string $route = ''): array {
		return [
			'error' => $error,
			'portal' => $portal,
			'route' => $route,
			'pageId' => '',
			'publicUrl' => '',
			'formId' => '',
		];
	}               
}           
