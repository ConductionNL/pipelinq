<?php

   
                                
  
                                                                   
                                                                     
                                                                         
                                                                       
                                                                    
                                                                    
                                                                     
                                                                       
                                                                            
                                                                          
                                       
  
                                                                       
                                                                 
                                                                       
                                                                      
                                                          
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                        
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                        
                         
  
                                                                          
                                                                         
                                                             
                                                                       
  
                                                                        
   
class TrackingLinkService {
	   
                                                            
   
               
    
	private const SECRET_CONFIG_KEY = 'blast.tracking_secret';

	   
                                                              
   
               
    
	private const TTL_CONFIG_KEY = 'blast.tracking_token_ttl_days';

	   
                                                                        
   
            
    
	private const DEFAULT_TTL_DAYS = 90;

	   
                                                            
   
               
    
	private const OPEN_ROUTE_PREFIX = '/api/blast/track/open/';

	   
                                                                
   
               
    
	private const CLICK_ROUTE_PREFIX = '/api/blast/track/click/';

	   
                                                                          
   
               
    
	private const DEFAULT_REGISTER_SLUG = 'pipelinq';

	   
                                      
   
               
    
	private const DEFAULT_BLAST_DELIVERY_SCHEMA_SLUG = 'blastDelivery';

	   
                                                                       
                                                                       
                                                            
   
                           
    
	private const OPENABLE_STATUSES = ['delivered', 'sent'];

	   
                
   
                                                            
                                                              
                                                     
                                                 
                                       
                                                          
                                                
                                                                         
                                                     
                                          
                                                                    
                                                               
   
                                                                         
    
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private ITimeFactory $time,
		private ISecureRandom $secureRandom,
		private AttributionService $attributionService,
		private BlastService $blastService,
		private LoggerInterface $logger,
		private TrafficEventEmitter $trafficEventEmitter,
	) {
	}                   

	   
                                                 
   
                                                              
   
                                                            
   
                                                                           
    
	public function signOpenToken(string $blastDeliveryId): string {
		return $this->signPayload(payload: ['d' => $blastDeliveryId, 'u' => null]);
	}                     

	   
                                                                        
   
                                                                       
                                                                         
                                                                        
                                    
   
                                                              
                                                      
   
                                                            
   
                                                                           
    
	public function signClickToken(string $blastDeliveryId, string $targetUrl): string {
		return $this->signPayload(payload: ['d' => $blastDeliveryId, 'u' => $targetUrl]);
	}                      

	   
                                                                    
   
                                                                      
                                                                    
                                
   
                                             
   
                                                                       
                                                                          
   
                                                                           
    
	public function verifyToken(string $token): ?array {
		$parts = explode('.', $token);
		if (count($parts) !== 2) {
			return null;
		}

		[$encoded, $signature] = $parts;
		if ($encoded === '' || $signature === '') {
			return null;
		}

		$expected = $this->base64UrlEncode(data: hash_hmac('sha256', $encoded, $this->signingKey(), true));
		if (hash_equals($expected, $signature) === false) {
			return null;
		}

		$decoded = json_decode((string)$this->base64UrlDecode(data: $encoded), true);
		if (is_array($decoded) === false || isset($decoded['d']) === false || isset($decoded['exp']) === false) {
			return null;
		}

		if ((int)$decoded['exp'] < $this->time->getTime()) {
			return null;
		}

		return $decoded;
	}                   

	   
                                                                      
                                                
   
                                                                    
                                                                       
                                                                        
                                                                   
                                                                       
                                                                       
   
                                                 
                                                                       
                                                
   
                                                                 
   
                                                                           
    
	public function injectTracking(string $html, string $blastDeliveryId): string {
		if ($html === '' || $blastDeliveryId === '') {
			return $html;
		}

		$rewritten = preg_replace_callback(
			'/<a\b([^>]*?)\shref=(["\'])(.*?)\2([^>]*)>/i',
			function (array $matches) use ($blastDeliveryId): string {
				return $this->rewriteAnchor(matches: $matches, blastDeliveryId: $blastDeliveryId);
			},
			$html
		);
		if (is_string($rewritten) === false) {
			$this->logger->warning(
				'TrackingLinkService.injectTracking: link rewrite failed — appending pixel only',
				['blastDeliveryId' => $blastDeliveryId]
			);
			$rewritten = $html;
		}

		return $this->appendOpenPixel(html: $rewritten, blastDeliveryId: $blastDeliveryId);
	}                      

	   
                                                                    
                                                                        
                                                                     
                                                                
   
                                                              
   
                
   
                                                                           
                                                                                                                                       
    
	public function recordOpen(string $blastDeliveryId): void {
		$delivery = $this->loadDelivery(id: $blastDeliveryId);
		if ($delivery === null) {
			return;
		}

		$payload = $delivery;
		if (empty($payload['openedAt']) === true) {
			$payload['openedAt'] = $this->nowIso();
		}

		$current = (string)($payload['status'] ?? '');
		if (in_array($current, self::OPENABLE_STATUSES, true) === true) {
			$payload['status'] = 'opened';
		}

		$this->saveDelivery(payload: $payload, id: $this->extractId(payload: $delivery));

		$blastId = (string)($delivery['blastId'] ?? '');
		if ($blastId !== '') {
			$this->blastService->updateBlastTotals(blastId: $blastId);
		}

		$this->reportToTraffic(kind: 'open', delivery: $delivery, clickedUrl: null);
	}                  

	   
                                                                        
                                                                      
                                                                        
                                                                     
                                             
   
                                                              
                                                        
   
                
   
                                                                           
                                                                                                                                       
    
	public function recordClick(string $blastDeliveryId, string $url): void {
		$delivery = $this->loadDelivery(id: $blastDeliveryId);
		if ($delivery === null) {
			return;
		}

		$this->attributionService->recordClick(
			blastDeliveryId: $blastDeliveryId,
			clickEvent: ['url' => $url, 'timestamp' => $this->nowIso()],
		);

		$blastId = (string)($delivery['blastId'] ?? '');
		if ($blastId !== '') {
			$this->blastService->updateBlastTotals(blastId: $blastId);
		}

		$this->reportToTraffic(kind: 'click', delivery: $delivery, clickedUrl: $url);
	}                   

	   
                                                                        
   
                                                                         
                                                                      
                                                                    
   
                                          
                                                                       
                                                                     
   
                
    
	private function reportToTraffic(string $kind, array $delivery, ?string $clickedUrl): void {
		try {
			$blastId = (string)($delivery['blastId'] ?? '');
			$blast = null;
			if ($blastId !== '') {
				$blast = $this->blastService->getBlastById(blastId: $blastId);
			}

			$this->trafficEventEmitter->emitEmailEvent(
				kind: $kind,
				delivery: $delivery,
				blast: ($blast ?? []),
				clickedUrl: $clickedUrl,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'TrackingLinkService.reportToTraffic: traffic dual-write failed',
				['kind' => $kind, 'exception' => $e->getMessage()]
			);
		}
	}                       

	   
                                                               
   
                                                                  
                                                           
                                                                  
                                                          
                                                    
   
                                                           
    
	private function rewriteAnchor(array $matches, string $blastDeliveryId): string {
		$before = ($matches[1] ?? '');
		$quote = ($matches[2] ?? '"');
		$href = ($matches[3] ?? '');
		$after = ($matches[4] ?? '');

		if ($href === '' || str_starts_with($href, '#') === true || str_contains($href, '{{') === true) {
			return $matches[0];
		}

		                                                                   
		                                                                  
		                                                               
		$target = html_entity_decode($href, (ENT_QUOTES | ENT_HTML5));
		$token = $this->signClickToken(blastDeliveryId: $blastDeliveryId, targetUrl: $target);
		$newHref = (self::CLICK_ROUTE_PREFIX . $token);
		return ('<a' . $before . ' href=' . $quote . $newHref . $quote . $after . '>');
	}                     

	   
                                                                     
                                                          
   
                                           
                                                    
   
                                                
    
	private function appendOpenPixel(string $html, string $blastDeliveryId): string {
		$token = $this->signOpenToken(blastDeliveryId: $blastDeliveryId);
		$pixel = '<img src="' . self::OPEN_ROUTE_PREFIX . $token
			. '" width="1" height="1" alt="" style="display:none;border:0;width:1px;height:1px;" />';

		if (stripos($html, '</body>') !== false) {
			$withPixel = preg_replace('/<\/body>/i', ($pixel . '</body>'), $html, 1);
			if (is_string($withPixel) === true) {
				return $withPixel;
			}
		}

		return ($html . $pixel);
	}                       

	   
                                                                         
                                    
   
                                                              
   
                                                            
    
	private function signPayload(array $payload): string {
		$issuedAt = $this->time->getTime();
		$expiresAt = ($issuedAt + $this->resolveTtlSeconds());

		$full = array_merge($payload, ['iat' => $issuedAt, 'exp' => $expiresAt]);

		$encoded = $this->base64UrlEncode(data: (string)json_encode($full));
		$signature = $this->base64UrlEncode(data: hash_hmac('sha256', $encoded, $this->signingKey(), true));
		return ($encoded . '.' . $signature);
	}                   

	   
                                                               
   
                                   
    
	private function signingKey(): string {
		$key = $this->appConfig->getValueString(Application::APP_ID, self::SECRET_CONFIG_KEY, '');
		if ($key === '') {
			$key = $this->secureRandom->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
			$this->appConfig->setValueString(Application::APP_ID, self::SECRET_CONFIG_KEY, $key, false, true);
		}

		return $key;
	}                  

	   
                                                            
                                                                         
   
                                         
    
	private function resolveTtlSeconds(): int {
		$raw = $this->appConfig->getValueString(
			Application::APP_ID,
			self::TTL_CONFIG_KEY,
			(string)self::DEFAULT_TTL_DAYS,
		);
		if ($raw === '' || is_numeric($raw) === false) {
			return (self::DEFAULT_TTL_DAYS * 86400);
		}

		$days = (int)$raw;
		if ($days <= 0) {
			return (self::DEFAULT_TTL_DAYS * 86400);
		}

		return ($days * 86400);
	}                         

	   
                                               
   
                                     
   
                                      
    
	private function base64UrlEncode(string $data): string {
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}                       

	   
                     
   
                                           
   
                                                       
    
	private function base64UrlDecode(string $data): string {
		$decoded = base64_decode(strtr($data, '-_', '+/'), true);
		if ($decoded === false) {
			return '';
		}

		return $decoded;
	}                       

	   
                                     
   
                                                 
   
                                                  
    
	private function loadDelivery(string $id): ?array {
		$register = $this->getRegisterSlug();
		$schema = $this->getBlastDeliverySchemaSlug();
		if ($register === '' || $schema === '' || $id === '') {
			return null;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$entity = $objectService->find(id: $id, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->info(
				'TrackingLinkService.loadDelivery: not found',
				['id' => $id, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $this->toArray(value: $entity);
	}                    

	   
                                                                     
   
                                                          
                                  
   
                
    
	private function saveDelivery(array $payload, string $id): void {
		$register = $this->getRegisterSlug();
		$schema = $this->getBlastDeliverySchemaSlug();
		if ($register === '' || $schema === '' || $id === '') {
			return;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return;
		}

		try {
			$objectService->saveObject(object: $payload, register: $register, schema: $schema, uuid: $id);
		} catch (Throwable $e) {
			$this->logger->warning(
				'TrackingLinkService.saveDelivery: save failed',
				['id' => $id, 'exception' => $e->getMessage()]
			);
		}
	}                    

	   
                                                                 
                                          
   
                                                 
   
                               
    
	private function extractId(array $payload): string {
		foreach (['uuid', 'id', 'slug'] as $key) {
			if (isset($payload[$key]) === true && is_scalar($payload[$key]) === true && (string)$payload[$key] !== '') {
				return (string)$payload[$key];
			}
		}

		if (isset($payload['@self']) === true && is_array($payload['@self']) === true) {
			foreach (['uuid', 'id', 'slug'] as $key) {
				$value = ($payload['@self'][$key] ?? null);
				if (is_scalar($value) === true && (string)$value !== '') {
					return (string)$value;
				}
			}
		}

		return '';
	}                 

	   
                                                          
   
                        
    
	private function getBlastDeliverySchemaSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'blastDelivery_schema', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_BLAST_DELIVERY_SCHEMA_SLUG;
	}                                  

	   
                                              
   
                        
    
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_REGISTER_SLUG;
	}                       

	   
                                                  
   
                                                                     
    
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'TrackingLinkService.getObjectService: OpenRegister unavailable',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}                        

	   
                                                               
   
                                               
   
                                               
    
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialised = $value->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($value) === true && method_exists($value, 'getObject') === true) {
			$payload = $value->getObject();
			if (is_array($payload) === true) {
				return $payload;
			}
		}

		return [];
	}               

	   
                                       
   
                             
    
	private function nowIso(): string {
		return gmdate('Y-m-d\TH:i:s\Z');
	}              
}           
