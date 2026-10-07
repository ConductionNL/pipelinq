<?php

   
                               
  
                                                              
                                                                
                                                                       
                                                                      
                                                                         
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                               
  
                                                                                                          
  
                                                                                                                                  
   
class AttributionService {
	   
                                                                          
    
	private const DEFAULT_REGISTER_SLUG = 'pipelinq';

	   
                                      
    
	private const DEFAULT_BLAST_DELIVERY_SCHEMA_SLUG = 'blastDelivery';

	   
                                        
    
	private const DEFAULT_ATTRIBUTION_LINK_SCHEMA_SLUG = 'attributionLink';

	   
                                                                    
                                   
    
	private const DEFAULT_LEAD_SCHEMA_SLUG = 'lead';

	   
                
   
                                                      
                                            
                                          
   
                                                                                                           
    
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                
   
                                                                    
                                                                      
                                                               
                                                                     
                                                                       
                                           
   
                                                              
                                                                
                                                               
                                                                   
   
                
   
                                                                                                           
    
	public function recordClick(string $blastDeliveryId, array $clickEvent): void {
		$delivery = $this->loadDelivery(id: $blastDeliveryId);
		if ($delivery === null) {
			return;
		}

		$timestamp = $this->extractClickTimestamp(event: $clickEvent);
		$url = $this->extractClickUrl(event: $clickEvent);

		$payload = $delivery;
		if (empty($payload['firstClickAt']) === true) {
			$payload['firstClickAt'] = $timestamp;
		}

		$clickedUrls = ($payload['clickedUrls'] ?? []);
		if (is_array($clickedUrls) === false) {
			$clickedUrls = [];
		}

		if ($url !== '' && in_array($url, $clickedUrls, true) === false) {
			$clickedUrls[] = $url;
		}

		$payload['clickedUrls'] = $clickedUrls;

		$current = (string)($payload['status'] ?? '');
		if (in_array($current, ['delivered', 'opened', 'sent'], true) === true) {
			$payload['status'] = 'clicked';
		}

		$this->saveObject(
			payload: $payload,
			schemaSlug: $this->getBlastDeliverySchemaSlug(),
			id: $this->extractId(payload: $delivery),
		);
	}                   

	   
                                                                     
   
                                                                    
                                                             
                                                                      
                                                                          
                                                      
                                                                  
                         
   
                                                              
                                                         
   
                
   
                                                                                                           
                                                                           
    
	public function linkBlastToDeal(string $blastDeliveryId, string $dealId): void {
		$delivery = $this->loadDelivery(id: $blastDeliveryId);
		if ($delivery === null) {
			$this->logger->info(
				'AttributionService.linkBlastToDeal: delivery not found',
				['blastDeliveryId' => $blastDeliveryId]
			);
			return;
		}

		$blastId = (string)($delivery['blastId'] ?? '');
		$contactId = (string)($delivery['contactId'] ?? '');
		if ($blastId === '' || $contactId === '') {
			return;
		}

		if ($this->attributionExists(blastId: $blastId, contactId: $contactId, dealId: $dealId) === true) {
			return;
		}

		$deal = $this->loadDeal(id: $dealId);
		$closedWonAt = $this->extractClosedWonAt(deal: $deal);
		$value = $this->extractDealValue(deal: $deal);

		$payload = [
			'blastId' => $blastId,
			'contactId' => $contactId,
			'dealId' => $dealId,
			'firstClickAt' => (string)($delivery['firstClickAt'] ?? ''),
			'closedWonAt' => $closedWonAt,
			'attributedValue' => $value,
			'currency' => $this->currencyOf(deal: $deal),
			'createdAt' => $this->nowIso(),
		];

		$this->saveObject(
			payload: $payload,
			schemaSlug: $this->getAttributionLinkSchemaSlug(),
		);
	}                       

	   
                                                                    
                      
   
                                              
   
                             
   
                                                                                                           
    
	public function getBlastAttributedValue(string $blastId): float {
		$rows = $this->loadAttributionLinks(filters: ['blastId' => $blastId]);
		$total = 0.0;
		foreach ($rows as $row) {
			$raw = ($row['attributedValue'] ?? null);
			if (is_numeric($raw) === true) {
				$total += (float)$raw;
			}
		}

		return $total;
	}                               

	   
                                                                       
                                                                      
                                             
                                                   
   
                                              
   
                                                                                            
   
                                                                                                                                        
                                                                           
    
	public function getBlastAttributionSummary(string $blastId): array {
		$rows = $this->loadAttributionLinks(filters: ['blastId' => $blastId]);
		$total = 0.0;
		$deals = [];
		foreach ($rows as $row) {
			$raw = ($row['attributedValue'] ?? null);
			if (is_numeric($raw) === true) {
				$total += (float)$raw;
			}

			$dealId = (string)($row['dealId'] ?? '');
			if ($dealId !== '') {
				$deals[$dealId] = true;
			}
		}

		return [
			'blastId' => $blastId,
			'dealCount' => count($deals),
			'attributedValue' => $total,
			'currency' => $this->reportingCurrency(),
		];
	}                                  

	   
                                                   
   
                                                   
   
                                      
    
	private function extractClickTimestamp(array $event): string {
		foreach (['timestamp', 'occurredAt', 'time'] as $key) {
			if (isset($event[$key]) === true) {
				$value = $event[$key];
				if (is_int($value) === true) {
					return gmdate('Y-m-d\TH:i:s\Z', $value);
				}

				if (is_string($value) === true && $value !== '') {
					return $value;
				}
			}
		}

		return $this->nowIso();
	}                             

	   
                                             
   
                                                   
   
                                
    
	private function extractClickUrl(array $event): string {
		foreach (['url', 'href', 'link'] as $key) {
			if (isset($event[$key]) === true && is_string($event[$key]) === true && $event[$key] !== '') {
				return (string)$event[$key];
			}
		}

		return '';
	}                       

	   
                                                                   
   
                                    
                                        
                                  
   
                                                   
    
	private function attributionExists(string $blastId, string $contactId, string $dealId): bool {
		$rows = $this->loadAttributionLinks(
			filters: ['blastId' => $blastId, 'contactId' => $contactId, 'dealId' => $dealId],
		);
		return ($rows !== []);
	}                         

	   
                                              
   
                                                        
   
                                     
    
	private function extractClosedWonAt(?array $deal): string {
		if ($deal === null) {
			return $this->nowIso();
		}

		foreach (['closedWonAt', 'closedAt', 'wonAt'] as $key) {
			if (isset($deal[$key]) === true && is_string($deal[$key]) === true && $deal[$key] !== '') {
				return (string)$deal[$key];
			}
		}

		return $this->nowIso();
	}                          

	   
                                                                   
   
                                                                   
   
                                                                           
    
	private function reportingCurrency(): string {
		$code = strtoupper(trim($this->appConfig->getValueString(Application::APP_ID, 'currency', 'EUR')));
		if (preg_match('/^[A-Z]{3}$/', $code) === 1) {
			return $code;
		}

		return 'EUR';
	}                         

	   
                                                                                    
   
                                                        
   
                                                   
   
                                                                           
    
	private function currencyOf(?array $deal): string {
		$code = strtoupper(trim((string)($deal['currency'] ?? '')));
		if (preg_match('/^[A-Z]{3}$/', $code) === 1) {
			return $code;
		}

		return $this->reportingCurrency();
	}                  

	   
                                                       
   
                                                        
   
                                                    
    
	private function extractDealValue(?array $deal): float {
		if ($deal === null) {
			return 0.0;
		}

		foreach (['value', 'dealValue', 'amount', 'attributedValue'] as $key) {
			if (isset($deal[$key]) === true && is_numeric($deal[$key]) === true) {
				return (float)$deal[$key];
			}
		}

		return 0.0;
	}                        

	   
                                     
   
                                                 
   
                                                  
    
	private function loadDelivery(string $id): ?array {
		return $this->loadOne(id: $id, schemaSlug: $this->getBlastDeliverySchemaSlug());
	}                    

	   
                                   
   
                                        
   
                                                  
    
	private function loadDeal(string $id): ?array {
		if ($id === '') {
			return null;
		}

		return $this->loadOne(id: $id, schemaSlug: $this->getLeadSchemaSlug());
	}                

	   
                                          
   
                                          
                                          
   
                                                      
    
	private function loadOne(string $id, string $schemaSlug): ?array {
		$register = $this->getRegisterSlug();
		if ($register === '' || $schemaSlug === '') {
			return null;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$entity = $objectService->find(
				id: $id,
				register: $register,
				schema: $schemaSlug,
			);
		} catch (Throwable $e) {
			$this->logger->info(
				'AttributionService.loadOne: not found',
				['id' => $id, 'schema' => $schemaSlug, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $this->toArray(value: $entity);
	}               

	   
                                                        
   
                                                    
   
                                                  
    
	private function loadAttributionLinks(array $filters): array {
		$register = $this->getRegisterSlug();
		$schema = $this->getAttributionLinkSchemaSlug();
		if ($register === '' || $schema === '') {
			return [];
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		try {
			$rows = $objectService->findAll(
				config: [
					'filters' => array_merge(
						$filters,
						[
							'register' => $register,
							'schema' => $schema,
						]
					),
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'AttributionService.loadAttributionLinks: findAll failed',
				['filters' => $filters, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$out = [];
		foreach (($rows ?? []) as $row) {
			$out[] = $this->toArray(value: $row);
		}

		return $out;
	}                            

	   
                                                       
   
                                                                     
                                                                       
                                 
   
                                                 
                                          
                                                      
   
                                                                   
   
                                     
    
	private function saveObject(array $payload, string $schemaSlug, ?string $id = null): ?array {
		$register = $this->getRegisterSlug();
		if ($register === '' || $schemaSlug === '') {
			return null;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$saved = $objectService->saveObject(
				object: $payload,
				register: $register,
				schema: $schemaSlug,
				uuid: $id,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'AttributionService.saveObject: save failed',
				['schema' => $schemaSlug, 'exception' => $e->getMessage()]
			);
			return null;
		}

		return $this->toArray(value: $saved);
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

	   
                                                            
   
                        
    
	private function getAttributionLinkSchemaSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'attributionLink_schema', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_ATTRIBUTION_LINK_SCHEMA_SLUG;
	}                                    

	   
                                                          
   
                        
    
	private function getBlastDeliverySchemaSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'blastDelivery_schema', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_BLAST_DELIVERY_SCHEMA_SLUG;
	}                                  

	   
                                                        
   
                        
    
	private function getLeadSchemaSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'lead_schema', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_LEAD_SCHEMA_SLUG;
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
				'AttributionService.getObjectService: OpenRegister unavailable',
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
