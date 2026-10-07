<?php

   
                                 
  
                                                                        
                                                                          
                                                                          
                                                                           
                                                                     
                                                                         
                              
  
                                                                        
                                                                           
                                                                
                                                                   
                                                                           
  
                                                                         
                                                                           
                                                                          
                                                                         
                                       
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                               
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Marketing\PreferenceCentreService;
use OCA\Pipelinq\Service\SubscriptionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                 
  
                                                                              
                                                                             
                                     
  
                                                                                                               
   
class ListPublicController extends Controller {
	   
                                                          
   
               
    
	private const THROTTLE_ACTION = 'pipelinq_mailing_list_token';

	   
                                                                       
                                                                      
                                    
   
               
    
	private const GENERIC_REFUSAL = 'This link is no longer valid';

	   
                
   
                                         
                                                                 
                                                                      
                                                                           
                                                           
                                          
    
	public function __construct(
		IRequest $request,
		private SubscriptionService $subscriptions,
		private PreferenceCentreService $preferences,
		private IThrottler $throttler,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                   
   
                                                                       
                                                                         
                                                                    
                                            
   
                                               
                                                             
                                                                      
                                                                         
                                                                     
   
                                         
   
                                                                                                                  
    
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 300)]
	public function subscribe(string $id, string $email = '', string $website = ''): JSONResponse {
		try {
			$result = $this->subscriptions->subscribe(
				listId: $id,
				email: $email,
				honeypot: $website,
			);
		} catch (Throwable $e) {
			$this->logger->warning('ListPublicController.subscribe: failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => self::GENERIC_REFUSAL], Http::STATUS_NOT_FOUND);
		}

		if ($result['status'] === 'not-found') {
			return new JSONResponse(['error' => self::GENERIC_REFUSAL], Http::STATUS_NOT_FOUND);
		}

		if ($result['status'] === 'invalid') {
			return new JSONResponse(
				['error' => $this->l10n->t('That does not look like an email address')],
				Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(
			['message' => $this->l10n->t('Check your inbox. We sent you a link to confirm.')],
			Http::STATUS_ACCEPTED
		);
	}                 

	   
                                                               
   
                                                       
   
                                                                        
                                                     
   
                                                                                                                                
    
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 300)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function confirm(string $token): DataDisplayResponse {
		$result = ['status' => 'invalid'];
		try {
			$result = $this->subscriptions->confirm(
				token: $token,
				ipAddress: $this->request->getRemoteAddress(),
			);
		} catch (Throwable $e) {
			$this->logger->warning('ListPublicController.confirm: failed', ['exception' => $e->getMessage()]);
		}

		if ($result['status'] !== 'confirmed') {
			$this->registerRejectedToken();
			return $this->gonePage();
		}

		$listName = (string)(($result['list']['name'] ?? ''));
		return $this->page(
			title: $this->l10n->t('You are subscribed'),
			heading: $this->l10n->t('You are subscribed'),
			body: $this->l10n->t('You will receive %s from now on. Every message carries a link to stop.', [$listName]),
			form: '',
		);
	}               

	   
                                                              
   
                                                                       
                                                          
   
                                                      
   
                                                                         
                                       
   
                                                                                                           
    
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 300)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function unsubscribePage(string $token): DataDisplayResponse {
		$peek = null;
		try {
			$peek = $this->subscriptions->peekUnsubscribeToken(token: $token);
		} catch (Throwable $e) {
			$this->logger->warning('ListPublicController.unsubscribePage: failed', ['exception' => $e->getMessage()]);
		}

		if ($peek === null) {
			$this->registerRejectedToken();
			return $this->gonePage();
		}

		$listName = (string)($peek['list']['name'] ?? '');
		$action = $this->escape(value: $this->request->getRequestUri());
		$form = '<form method="post" action="' . $action . '">'
			. '<button type="submit" name="confirmed" value="1">'
			. $this->escape(value: $this->l10n->t('Unsubscribe me'))
			. '</button>'
			. '<label><input type="checkbox" name="global" value="1"> '
			. $this->escape(value: $this->l10n->t('Also stop every other list from this sender'))
			. '</label>'
			. '</form>';

		return $this->page(
			title: $this->l10n->t('Unsubscribe'),
			heading: $this->l10n->t('Unsubscribe from %s', [$listName]),
			body: $this->l10n->t('You are about to stop receiving this list. Nothing has changed yet.'),
			form: $form,
		);
	}                       

	   
                                                                    
   
                                                                          
                                               
   
                                                      
                                                                   
                                                                 
   
                                                       
   
                                                                                                           
    
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 300)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function unsubscribe(string $token, string $global = '', string $reason = ''): JSONResponse {
		$result = ['status' => 'invalid', 'count' => 0];
		try {
			$result = $this->subscriptions->unsubscribeByToken(
				token: $token,
				global: ($global !== '' && $global !== '0'),
				reason: $reason,
			);
		} catch (Throwable $e) {
			$this->logger->warning('ListPublicController.unsubscribe: failed', ['exception' => $e->getMessage()]);
		}

		if ($result['status'] !== 'unsubscribed') {
			$this->registerRejectedToken();
			return new JSONResponse(['error' => self::GENERIC_REFUSAL], Http::STATUS_GONE);
		}

		return new JSONResponse(
			[
				'message' => $this->l10n->t('You are unsubscribed. You will not hear from this list again.'),
				'count' => $result['count'],
			],
			Http::STATUS_OK
		);
	}                   

	   
                                                               
   
                                                      
   
                                                                       
                                                                    
   
                                                                                                                      
    
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 300)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function preferences(string $token): JSONResponse {
		$rows = null;
		try {
			$rows = $this->preferences->preferencesForToken(token: $token);
		} catch (Throwable $e) {
			$this->logger->warning('ListPublicController.preferences: failed', ['exception' => $e->getMessage()]);
		}

		if ($rows === null) {
			$this->registerRejectedToken();
			return new JSONResponse(['error' => self::GENERIC_REFUSAL], Http::STATUS_GONE);
		}

		return new JSONResponse(['lists' => $rows]);
	}                   

	   
                                                                   
   
                                                      
                                                                   
   
                                                       
   
                                                                                                                      
    
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 300)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function savePreferences(string $token, array $lists = []): JSONResponse {
		$result = ['status' => 'invalid', 'confirmed' => 0, 'unsubscribed' => 0];
		try {
			$result = $this->preferences->savePreferences(token: $token, selectedListIds: $lists);
		} catch (Throwable $e) {
			$this->logger->warning('ListPublicController.savePreferences: failed', ['exception' => $e->getMessage()]);
		}

		if ($result['status'] !== 'saved') {
			$this->registerRejectedToken();
			return new JSONResponse(['error' => self::GENERIC_REFUSAL], Http::STATUS_GONE);
		}

		return new JSONResponse(
			[
				'message' => $this->l10n->t('Your choices are saved.'),
				'confirmed' => $result['confirmed'],
				'unsubscribed' => $result['unsubscribed'],
			],
			Http::STATUS_OK
		);
	}                       

	   
                                                          
   
                                                                    
                                                  
   
                
    
	private function registerRejectedToken(): void {
		try {
			$this->throttler->registerAttempt(
				action: self::THROTTLE_ACTION,
				ip: $this->request->getRemoteAddress()
			);
		} catch (Throwable $throttlerFailure) {
			$this->logger->warning(
				'ListPublicController: registerAttempt failed: ' . $throttlerFailure->getMessage()
			);
		}
	}                             

	   
                                   
   
                                           
    
	private function gonePage(): DataDisplayResponse {
		return $this->page(
			title: $this->l10n->t('Link expired'),
			heading: $this->l10n->t('This link no longer works'),
			body: $this->l10n->t('It may have been used already, or it may be too old. Ask for a new one from any message you received.'),
			form: '',
			status: Http::STATUS_GONE,
		);
	}                

	   
                                                   
   
                                                                      
                                                                          
                                                                      
                                                                          
                                       
   
                                            
                                               
                                                    
                                                                      
                                                             
                                   
   
                                                            
   
                                                  
    
	private function page(
		string $title,
		string $heading,
		string $body,
		string $form,
		int $status = Http::STATUS_OK,
	): DataDisplayResponse {
		$html = '<!DOCTYPE html><html lang="' . $this->escape(value: $this->l10n->getLanguageCode()) . '">'
			. '<head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<meta name="robots" content="noindex, nofollow">'
			. '<title>' . $this->escape(value: $title) . '</title>'
			. '<style>body{font-family:system-ui,sans-serif;margin:0;padding:3rem 1.5rem;max-width:34rem}'
			. 'h1{font-size:1.4rem}button{font:inherit;padding:.6rem 1.2rem;margin:1rem 0}'
			. 'label{display:block;font-size:.9rem}</style>'
			. '</head><body>'
			. '<h1>' . $this->escape(value: $heading) . '</h1>'
			. '<p>' . $this->escape(value: $body) . '</p>'
			. $form
			. '</body></html>';

		return new DataDisplayResponse(
			$html,
			$status,
			[
				'Content-Type' => 'text/html; charset=UTF-8',
				'Cache-Control' => 'no-store, no-cache, must-revalidate',
				'X-Robots-Tag' => 'noindex, nofollow',
			]
		);
	}            

	   
                                                       
   
                                       
   
                                     
    
	private function escape(string $value): string {
		return htmlspecialchars($value, (ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5), 'UTF-8');
	}              
}           
