<?php

   
                                
  
                                                                  
                                                                    
                                                                      
                                                                          
                                                                          
                               
  
                                                                         
                                                                         
                                                                         
                                                                         
                                         
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                                      
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                           
  
                                                                                                                                      
   
class TrafficEventEmitter {

	   
                                                                  
                                                                           
   
               
    
	public const PORTAL_CONFIG_KEY = 'blast.traffic_portal';

	   
                                                                       
                                                                         
   
               
    
	public const INGEST_SERVICE_CLASS = 'OCA\\Portaliq\\Service\\TrafficIngestService';

	   
                                                             
   
                                                          
    
	private const KINDS = [
		'open' => ['name' => 'email_open', 'sequence' => 0],
		'click' => ['name' => 'email_click', 'sequence' => 1],
	];

	   
                
   
                                                                   
                                                                  
                                                     
                                                                   
                                          
   
                                                                                                                                       
    
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                                    
   
                                                                        
                                                                          
                                                                       
                                                                       
                               
   
                                          
                                                                  
                                                               
                                                                  
                                                                       
                                              
                                                                    
                                               
   
                
   
                                                                                                                                       
    
	public function emitEmailEvent(string $kind, array $delivery, array $blast, ?string $clickedUrl = null): void {
		if (isset(self::KINDS[$kind]) === false) {
			$this->logger->warning('TrafficEventEmitter: unknown event kind', ['kind' => $kind]);
			return;
		}

		$portalSlug = trim($this->appConfig->getValueString(Application::APP_ID, self::PORTAL_CONFIG_KEY, ''));
		if ($portalSlug === '') {
			$this->logger->debug('TrafficEventEmitter: no traffic portal configured, skipping');
			return;
		}

		if ($this->isIngestServiceAvailable() === false) {
			$this->logger->debug('TrafficEventEmitter: Portaliq ingest service not installed, skipping');
			return;
		}

		try {
			$ingest = $this->container->get(self::INGEST_SERVICE_CLASS);
			if (is_object($ingest) === false || method_exists($ingest, 'ingest') === false) {
				$this->logger->warning('TrafficEventEmitter: ingest service has no ingest() method');
				return;
			}

			$event = $this->buildEvent(kind: $kind, delivery: $delivery, blast: $blast, clickedUrl: $clickedUrl);
			$result = $ingest->ingest($portalSlug, [$event], ['serverSide' => true, 'consent' => true]);
			$this->logAccepted(kind: $kind, portalSlug: $portalSlug, result: $result);
		} catch (Throwable $e) {
			$this->logger->warning(
				'TrafficEventEmitter: reporting to Portaliq failed',
				['kind' => $kind, 'portal' => $portalSlug, 'exception' => $e->getMessage()]
			);
		}
	}                      

	   
                                                                         
   
                                                                          
   
                
    
	protected function isIngestServiceAvailable(): bool {
		return class_exists(self::INGEST_SERVICE_CLASS);
	}                                

	   
                                                                       
   
                                                                        
                                                                          
                                                                     
                                                                          
                                   
   
                                          
                                                                
                                                                           
                                                                     
   
                                           
    
	private function buildEvent(string $kind, array $delivery, array $blast, ?string $clickedUrl): array {
		$blastId = (string)($delivery['blastId'] ?? '');
		$contactId = (string)($delivery['contactId'] ?? '');
		$pageLocation = ('mailto:blast/' . $blastId);
		if ($clickedUrl !== null && $clickedUrl !== '') {
			$pageLocation = $clickedUrl;
		}

		$campaign = (string)($blast['name'] ?? '');
		if ($campaign === '') {
			$campaign = (string)($blast['templateId'] ?? '');
		}

		if ($campaign === '') {
			$campaign = $blastId;
		}

		return [
			'name' => self::KINDS[$kind]['name'],
			'timestamp' => gmdate('Y-m-d\TH:i:s\Z', $this->time->getTime()),
			'sequence' => self::KINDS[$kind]['sequence'],
			'pageLocation' => $pageLocation,
			'params' => [],
			'campaign' => $campaign,
			'source' => 'email',
			'medium' => 'email',
			'blastRef' => $blastId,
			'contactRef' => $contactId,
		];
	}                  

	   
                                                                   
   
                                          
                                                           
                                                               
                                                                     
   
                
    
	private function logAccepted(string $kind, string $portalSlug, mixed $result): void {
		$accepted = 0;
		$refused = [];
		if (is_array($result) === true) {
			$accepted = (int)($result['accepted'] ?? 0);
			if (is_array($result['refused'] ?? null) === true) {
				$refused = $result['refused'];
			}
		}

		if ($accepted < 1 || $refused !== []) {
			$this->logger->info(
				'TrafficEventEmitter: Portaliq did not accept the event',
				['kind' => $kind, 'portal' => $portalSlug, 'accepted' => $accepted, 'refused' => $refused]
			);
			return;
		}

		$this->logger->debug('TrafficEventEmitter: event reported', ['kind' => $kind, 'portal' => $portalSlug]);
	}                   
}           
