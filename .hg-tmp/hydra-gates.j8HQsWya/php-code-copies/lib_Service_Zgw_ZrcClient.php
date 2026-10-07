<?php

   
                      
  
                                                                            
                                    
  
                                                    
                                           
                                                                   
                                                                          
                                                                
  
                                                                          
                                                                              
                                                                               
                                                                              
                                                                             
                                                                    
  
                    
                                     
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                                                 
                                                                                 
                                                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Zgw;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Throwable;

   
                            
  
                                                                                   
                                                                                    
                                                                                     
                                                                                       
  
                                                         
   
class ZrcClient {
	public const SCOPE_LEZEN = 'zaken.lezen';

	   
                
   
                                                                          
                                                                         
                                                                             
                                                                              
   
                                            
                                                        
                                                
    
	public function __construct(
		private ZgwApiClient $api,
		private ZgwRegisterAccess $registers,
		private LoggerInterface $logger,
	) {
	}                   

	  
                                                                         
   
                                                                        
                                                                          
                                                                                    
                                                                             
                                                               
                                                                       
                                                                      
                                         
   
                                                                          
                                                                           
                                                                   
                              
    

	   
                                           
   
                                                              
                                                                                          
   
                                           
   
                                        
                                                          
    
	public function getZaak(array $endpoint, array $mapping): array {
		$url = (string)($mapping['zgwUrl'] ?? '');
		if ($url === '') {
			throw new ZgwException('ZGW: mapping has no zgwUrl');
		}

		$client = $this->requireClient(endpoint: $endpoint);

		$response = $this->api->callComponent(
			componentUrl: $url,
			method: 'GET',
			path: '',
			client: $client
		);

		$etag = (string)($response['headers']['etag'] ?? '');
		if ($etag !== '' && isset($mapping['@self']['uuid']) === true) {
			$this->saveEtag(mapping: $mapping, etag: $etag);
		} elseif ($etag !== '' && isset($mapping['id']) === true) {
			$this->saveEtag(mapping: $mapping, etag: $etag);
		}

		return $response['body'];
	}               

	   
                              
   
                                                              
                                                                                
                                                                     
   
                                             
                                                                                  
    
	public function addStatus(array $endpoint, array $caseMap, array $statusData): string {
		$client = $this->requireClient(endpoint: $endpoint);
		$zrcUrl = $this->requireComponentUrl(endpoint: $endpoint, key: 'zrc');

		$body = array_merge(
			['zaak' => (string)($caseMap['zgwUrl'] ?? '')],
			$statusData
		);

		$response = $this->api->callComponent(
			componentUrl: $zrcUrl,
			method: 'POST',
			path: '/statussen',
			client: $client,
			body: $body
		);

		return (string)($response['headers']['location'] ?? $response['body']['url'] ?? '');
	}                 

	   
                                                 
   
                                                              
                                        
   
                                             
                                                          
    
	public function getStatus(array $endpoint, string $statusUrl): array {
		$client = $this->requireClient(endpoint: $endpoint);
		$response = $this->api->callComponent(
			componentUrl: $statusUrl,
			method: 'GET',
			path: '',
			client: $client
		);
		return $response['body'];
	}                 

	   
                                                            
   
                                                                               
                                                                         
                                                        
   
                                                              
                                                                                
                                                                  
                                                                           
                                                             
   
                                                       
                                                                                  
    
	public function linkInitiator(
		array $endpoint,
		array $caseMap,
		array $contact,
		string $roltypeUrl,
		string $roltoelichting = 'Initiator',
	): string {
		$client = $this->requireClient(endpoint: $endpoint);
		$zrcUrl = $this->requireComponentUrl(endpoint: $endpoint, key: 'zrc');
		$caseUrl = (string)($caseMap['zgwUrl'] ?? '');
		[$betrType, $ident] = self::contactIdentification(contact: $contact);

		                                                
		try {
			$existing = $this->api->callComponent(
				componentUrl: $zrcUrl,
				method: 'GET',
				path: '/rollen',
				client: $client,
				query: ['zaak' => $caseUrl, 'betrokkeneType' => $betrType]
			);
			$rows = $existing['body']['results'] ?? $existing['body'];
			if (is_array($rows) === true) {
				foreach ($rows as $row) {
					if (is_array($row) === false) {
						continue;
					}

					$hit = $row['betrokkeneIdentificatie'] ?? [];
					if (is_array($hit) === true && self::identMatches(a: $hit, b: $ident) === true) {
						$url = (string)($row['url'] ?? '');
						if ($url !== '') {
							return $url;
						}
					}
				}
			}
		} catch (Throwable $e) {
			$this->logger->info(
				'ZGW ZRC: linkInitiator GET /rollen failed (will fall through to POST)',
				['err' => $e->getMessage()]
			);
		}         

		$body = [
			'zaak' => $caseUrl,
			'betrokkeneType' => $betrType,
			'betrokkeneIdentificatie' => $ident,
			'roltype' => $roltypeUrl,
			'roltoelichting' => $roltoelichting,
		];

		$response = $this->api->callComponent(
			componentUrl: $zrcUrl,
			method: 'POST',
			path: '/rollen',
			client: $client,
			body: $body
		);

		return (string)($response['headers']['location'] ?? $response['body']['url'] ?? '');
	}                     

	   
                                                                              
   
                                                                  
   
                                                                         
                                                                                  
    
	public static function contactIdentification(array $contact): array {
		$bsn = (string)($contact['bsn'] ?? '');
		if ($bsn !== '') {
			return ['natuurlijk_persoon', ['inpBsn' => $bsn]];
		}

		$rsin = (string)($contact['rsin'] ?? $contact['kvk'] ?? '');
		if ($rsin !== '') {
			return ['niet_natuurlijk_persoon', ['innNnpId' => $rsin]];
		}

		                                                                                   
		return [
			'niet_natuurlijk_persoon',
			['statutaireNaam' => (string)($contact['name'] ?? 'Onbekend')],
		];
	}                             

	   
                                                                     
   
                                                                    
                                         
   
                
    
	private function saveEtag(array $mapping, string $etag): void {
		if ($etag === '') {
			return;
		}

		$uuid = (string)($mapping['@self']['uuid'] ?? $mapping['id'] ?? '');
		if ($uuid === '') {
			return;
		}

		$mapping['etag'] = $etag;
		$mapping['lastSynchronisation'] = self::nowIso();
		$this->registers->save(ZgwRegisterAccess::SCHEMA_MAPPING, $mapping, $uuid);
	}                

	   
                                                                      
   
                                                           
   
                                
   
                                                                         
    
	private function requireClient(array $endpoint): array {
		$client = $this->registers->findClientForEndpoint($endpoint);
		if ($client === null) {
			throw new ZgwException(
				sprintf(
					'ZGW: ZgwEndpoint "%s" references unknown clientId "%s"',
					(string)($endpoint['id'] ?? '?'),
					(string)($endpoint['clientId'] ?? '?')
				)
			);
		}

		return $client;
	}                     

	   
                                                          
   
                                                           
                                                              
   
                  
   
                                                                                   
    
	private function requireComponentUrl(array $endpoint, string $key): string {
		$url = (string)($endpoint['componenten'][$key] ?? '');
		if ($url === '') {
			throw new ZgwException(sprintf('ZGW: endpoint missing "%s" component URL', $key));
		}

		return $url;
	}                           

	   
                                    
   
                                             
                                              
   
                                                                      
    
	private static function identMatches(array $a, array $b): bool {
		foreach ($b as $key => $value) {
			if (isset($a[$key]) === true && (string)$a[$key] === (string)$value) {
				return true;
			}
		}

		return false;
	}                    

	   
                                     
   
                  
    
	private static function nowIso(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
	}              
}           
