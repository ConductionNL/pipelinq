<?php

   
                                       
  
                                                                          
                                                                             
                                                             
                                                                     
                                                     
                                                                             
                                                                          
                                           
  
                    
                                           
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                                                                                  
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                               
  
                                                                                                                                                                                  
   
class DeliverabilityCheckService {
	   
                                                                          
    
	private const DEFAULT_REGISTER_SLUG = 'pipelinq';

	   
                                                                         
                            
    
	private const DEFAULT_MAIL_TRANSPORT_SCHEMA_SLUG = 'mailTransport';

	   
                                                                 
                   
    
	private const CACHE_TTL_SECONDS = 86400;

	   
                
   
                                                      
                                                     
                                              
    
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}                   

	   
                                                                      
                                                            
                                                                     
   
                                                 
                                                                        
   
                                                                                  
                                                                         
   
                                                                                                                                                                                   
   
                                                                                
                                                  
    
	public function checkTransportById(string $id, bool $forceRefresh = false): ?array {
		$transport = $this->loadOne(id: $id);
		if ($transport === null) {
			return null;
		}

		return $this->checkTransport(transport: $transport, forceRefresh: $forceRefresh);
	}                          

	   
                                   
   
                                                 
   
                                     
    
	private function loadOne(string $id): ?array {
		if ($id === '') {
			return null;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$found = $objectService->find(
				id: $id,
				register: $this->getRegisterSlug(),
				schema: $this->getMailTransportSchemaSlug(),
			);
		} catch (Throwable $e) {
			$this->logger->info('DeliverabilityCheckService.loadOne: not found', ['id' => $id, 'exception' => $e->getMessage()]);
			return null;
		}

		if ($found === null) {
			return null;
		}

		if (is_array($found) === true) {
			return $found;
		}

		if (is_object($found) === true && method_exists($found, 'jsonSerialize') === true) {
			return (array)$found->jsonSerialize();
		}

		return null;
	}               

	   
                                                                          
                                                                          
                               
   
                                                                   
                                                                        
   
                                                                                          
   
                                                                                                                                                                                   
   
                                                                                
                                                  
    
	public function checkTransport(array $transport, bool $forceRefresh = false): array {
		$domain = (string)($transport['senderDomain'] ?? '');
		if ($domain === '') {
			return ['dkimVerified' => false, 'dmarcStatus' => 'unknown', 'checkedAt' => ''];
		}

		if ($forceRefresh === false && $this->cacheIsFresh(transport: $transport) === true) {
			return [
				'dkimVerified' => (bool)($transport['dkimVerified'] ?? false),
				'dmarcStatus' => (string)($transport['dmarcStatus'] ?? 'unknown'),
				'checkedAt' => (string)($transport['deliverabilityCheckedAt'] ?? ''),
			];
		}

		$verdict = $this->lookupDomain(domain: $domain);
		$this->persistVerdict(transport: $transport, verdict: $verdict);
		return $verdict;
	}                      

	   
                                                          
                             
   
                                                             
   
                
    
	private function cacheIsFresh(array $transport): bool {
		$checkedAt = (string)($transport['deliverabilityCheckedAt'] ?? '');
		if ($checkedAt === '') {
			return false;
		}

		$checkedAtTimestamp = strtotime($checkedAt);
		if ($checkedAtTimestamp === false) {
			return false;
		}

		return ((time() - $checkedAtTimestamp) < self::CACHE_TTL_SECONDS);
	}                    

	   
                                                                     
                                                                     
                              
   
                                            
   
                                                                             
    
	private function lookupDomain(string $domain): array {
		$dmarcStatus = 'unknown';
		try {
			$dmarcRecords = $this->dnsGetRecord(hostname: ('_dmarc.' . $domain), type: DNS_TXT);
			$dmarcStatus = $this->classifyDmarc(records: $dmarcRecords);
		} catch (Throwable $e) {
			$this->logger->info('DeliverabilityCheckService.lookupDomain: DMARC lookup failed', ['domain' => $domain, 'exception' => $e->getMessage()]);
		}

		$dkimVerified = false;
		try {
			                                                              
			                                                        
			                                                          
			$dkimRecords = $this->dnsGetRecord(hostname: ('default._domainkey.' . $domain), type: DNS_TXT);
			$dkimVerified = ($dkimRecords !== false && $dkimRecords !== []);
		} catch (Throwable $e) {
			$this->logger->info('DeliverabilityCheckService.lookupDomain: DKIM lookup failed', ['domain' => $domain, 'exception' => $e->getMessage()]);
		}

		return ['dkimVerified' => $dkimVerified, 'dmarcStatus' => $dmarcStatus, 'checkedAt' => $this->nowIso()];
	}                    

	   
                                                                           
   
                                                                                       
   
                                                        
    
	private function classifyDmarc(array|false $records): string {
		if ($records === false || $records === []) {
			return 'missing';
		}

		foreach ($records as $record) {
			$txt = (string)($record['txt'] ?? '');
			if (str_starts_with($txt, 'v=DMARC1') === true) {
				return 'found';
			}
		}

		return 'invalid';
	}                     

	   
                                                            
                                                                   
                                                        
   
                                                  
                                                       
   
                                                  
   
                                                                                                                                                                                   
    
	protected function dnsGetRecord(string $hostname, int $type): array|false {
		return dns_get_record($hostname, $type);
	}                    

	   
                                             
   
                                                             
                                                                                                  
   
                
    
	private function persistVerdict(array $transport, array $verdict): void {
		$id = $this->extractId(payload: $transport);
		if ($id === '') {
			return;
		}

		$payload = $transport;
		$payload['dkimVerified'] = $verdict['dkimVerified'];
		$payload['dmarcStatus'] = $verdict['dmarcStatus'];
		$payload['deliverabilityCheckedAt'] = $verdict['checkedAt'];

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return;
		}

		try {
			$objectService->saveObject(
				object: $payload,
				register: $this->getRegisterSlug(),
				schema: $this->getMailTransportSchemaSlug(),
				uuid: $id,
			);
		} catch (Throwable $e) {
			$this->logger->warning('DeliverabilityCheckService.persistVerdict: save failed', ['id' => $id, 'exception' => $e->getMessage()]);
		}
	}                      

	   
                                                                 
   
                       
    
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning('DeliverabilityCheckService.getObjectService: unavailable', ['exception' => $e->getMessage()]);
			return null;
		}
	}                        

	   
                                            
   
                  
    
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_REGISTER_SLUG;
	}                       

	   
                                               
   
                  
    
	private function getMailTransportSchemaSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'mailTransport_schema', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_MAIL_TRANSPORT_SCHEMA_SLUG;
	}                                  

	   
                                                        
   
                                                     
   
                                                      
    
	private function extractId(array $payload): string {
		foreach (['uuid', 'id', 'slug'] as $key) {
			$value = ($payload[$key] ?? null);
			if (is_scalar($value) === true && (string)$value !== '') {
				return (string)$value;
			}
		}

		$self = ($payload['@self'] ?? null);
		if (is_array($self) === true) {
			foreach (['uuid', 'id', 'slug'] as $key) {
				$value = ($self[$key] ?? null);
				if (is_scalar($value) === true && (string)$value !== '') {
					return (string)$value;
				}
			}
		}

		return '';
	}                 

	   
                                  
   
                  
    
	private function nowIso(): string {
		return gmdate('Y-m-d\TH:i:s\Z');
	}              
}           
