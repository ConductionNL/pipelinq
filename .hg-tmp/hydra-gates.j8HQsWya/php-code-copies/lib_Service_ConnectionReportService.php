<?php

   
                                    
  
                                                                            
                                                                            
                                                                             
                                                                            
                                                                           
                                                  
  
                                                                              
                                                                          
                                                                 
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                                                                                    
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Social\SocialBrokerGateway;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                               
  
                                                                                                                                                    
   
class ConnectionReportService {

	   
                                                                        
                                                                           
   
               
    
	public const STATUS_EVENT = 'OCA\Integriq\Event\ConnectionStatusReportedEvent';

	   
                                                                           
                                                                           
                                                                             
   
                           
    
	public const KEYS = [
		'cti',
		'social-mastodon',
		'social-bluesky',
		'social-linkedin',
		'social-x',
		'social-facebook',
		'social-instagram',
		'social-threads',
		'berichtenbox',
		'mail-provider',
	];

	   
                                                                           
                                                                         
   
                           
    
	public const STATUSES = [
		'configured',
		'limited',
		'unconfigured',
		'simulated',
		'unavailable',
		'error',
	];

	   
                                                               
   
                                                                   
                                                                           
                                                                        
                                 
   
                              
    
	public const READINESS_STATUS = [
		SocialBrokerGateway::READY => 'configured',
		SocialBrokerGateway::PREVIEW => 'limited',
		SocialBrokerGateway::NOT_CONFIGURED => 'unconfigured',
	];

	   
                                                                        
                                                                       
   
               
    
	public const PREVIEW_MESSAGE = 'You can connect an account, and every post is attempted. '
		. 'The network may still refuse a post: the credential broker supports it as a preview only.';

	   
                
   
                                                                                
                                                                           
    
	public function __construct(
		private readonly IEventDispatcher $eventDispatcher,
		private readonly LoggerInterface $logger,
	) {
	}                   

	   
                                               
   
                                                                       
                                                                            
                                                                      
                                                          
   
                                                
                                                       
                                                   
   
                                                     
   
                                                                                                                                                     
    
	public function report(string $key, string $status, string $message = ''): bool {
		if (in_array($key, self::KEYS, true) === false) {
			$this->logger->warning(
				'Pipelinq: refusing to report an undeclared connection key',
				['key' => $key]
			);
			return false;
		}

		if (in_array($status, self::STATUSES, true) === false) {
			$this->logger->warning(
				'Pipelinq: refusing to report an unknown connection status',
				['key' => $key, 'status' => $status]
			);
			return false;
		}

		$eventClass = $this->resolveEventClass(eventClass: self::STATUS_EVENT);
		if ($eventClass === null) {
			return false;
		}

		try {
			$event = new $eventClass(
				app: Application::APP_ID,
				key: $key,
				status: $status,
				message: $message,
			);
			if ($event instanceof Event === false) {
				return false;
			}

			$this->eventDispatcher->dispatchTyped($event);
			return true;
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: could not send a connection report to integriq',
				['key' => $key, 'exception' => $e->getMessage()]
			);
			return false;
		}         
	}              

	   
                                                                    
   
                                                                             
                                                               
   
                                                                                                     
   
                                                     
   
                                                                                                                                                     
    
	public function reportCtiCheck(array $outcome): bool {
		$platform = trim((string)($outcome['platform'] ?? ''));

		if (($outcome['ok'] ?? false) === true) {
			return $this->report(
				key: 'cti',
				status: 'configured',
				message: 'The ' . $platform . ' adapter loads. This check makes no call to the platform.'
			);
		}

		if ($platform === '') {
			return $this->report(key: 'cti', status: 'unconfigured', message: 'No telephony platform is chosen.');
		}

		$reason = trim((string)($outcome['message'] ?? ''));
		return $this->report(
			key: 'cti',
			status: 'error',
			message: trim('The ' . $platform . ' adapter does not load. ' . $reason)
		);
	}                      

	   
                                                                          
   
                                                                                                                 
   
                                                                               
   
                                                                                                                                                     
    
	public function reportSocialReadiness(array $readiness): array {
		$sent = [];
		foreach ($readiness as $network => $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$key    = 'social-' . (string)$network;
			$status = (self::READINESS_STATUS[(string)($entry['state'] ?? '')] ?? null);
			if ($status === null || in_array($key, self::KEYS, true) === false) {
				continue;
			}

			$message = trim((string)($entry['reason'] ?? ''));
			if ($status === 'limited') {
				$message = self::PREVIEW_MESSAGE;
			}

			if ($message === '' && $status === 'configured') {
				$message = 'A developer application is filed for this network in the credential broker.';
			}

			if ($this->report(key: $key, status: $status, message: $message) === true) {
				$sent[] = $key;
			}
		}

		return $sent;
	}                             

	   
                                                                           
   
                                                                                          
   
                                                                           
   
                                                                                                                                                     
    
	protected function resolveEventClass(string $eventClass): ?string {
		$qualified = '\\' . $eventClass;
		if (class_exists($qualified) === false) {
			return null;
		}

		return $qualified;
	}                         
}           
