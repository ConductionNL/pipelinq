<?php

   
                                   
  
                                                              
  
                                                                            
                                                                           
                                                                               
                                                                          
                                                                             
                                                     
                                                                            
  
                                                                            
                                                                          
                                                                           
                                                                                 
  
                    
                                     
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Zgw;

use Throwable;

   
                                    
  
                                                         
   
class NrcSubscriptionService {
	   
                
   
                                                                    
                                                                      
                                                                           
                                                                             
                                               
   
                                            
                                                        
    
	public function __construct(
		private ZgwApiClient $api,
		private ZgwRegisterAccess $registers,
	) {
	}                   

	   
                                                                       
   
                                                              
                                                                                             
                                                  
   
                                                            
   
                                                           
                                                                                  
    
	public function registerAbonnement(array $endpoint, array $kanalen, string $callbackUrl): array {
		$client = $this->registers->findClientForEndpoint($endpoint);
		if ($client === null) {
			throw new NrcSubscriptionFailedException(
				sprintf(
					'ZGW NRC: ZgwEndpoint "%s" has no resolvable client',
					(string)($endpoint['id'] ?? '?')
				)
			);
		}

		$nrcUrl = (string)($endpoint['componenten']['nrc'] ?? '');
		if ($nrcUrl === '') {
			throw new NrcSubscriptionFailedException('ZGW NRC: endpoint missing nrc URL');
		}

		$bearer = $this->generateCallbackBearer();
		$body = [
			'callbackUrl' => $callbackUrl,
			'auth' => 'Bearer ' . $bearer,
			'kanalen' => $this->normaliseKanalen(kanalen: $kanalen),
		];

		try {
			$response = $this->api->callComponent(
				componentUrl: $nrcUrl,
				method: 'POST',
				path: '/abonnement',
				client: $client,
				body: $body
			);
		} catch (Throwable $e) {
			throw new NrcSubscriptionFailedException(
				'ZGW NRC: POST /abonnement failed: ' . $e->getMessage(),
				0,
				$e
			);
		}

		$abonnementUrl = (string)($response['headers']['location'] ?? $response['body']['url'] ?? '');
		if ($abonnementUrl === '') {
			throw new NrcSubscriptionFailedException('ZGW NRC: registration returned no abonnement URL');
		}

		$endpointId = (string)($endpoint['id'] ?? '');
		$record = [
			'endpointId' => $endpointId,
			'abonnementUrl' => $abonnementUrl,
			'callbackUrl' => $callbackUrl,
			'callbackAuth' => $bearer,
			'kanalen' => $this->normaliseKanalen(kanalen: $kanalen),
			'active' => true,
		];

		$saved = $this->registers->save(ZgwRegisterAccess::SCHEMA_ABONN, $record);
		return $saved ?? $record;
	}                          

	  
                             
   
                                                                          
                                                                             
                                                                        
                                                                           
                                                                            
                                     
    

	   
                                                                   
   
                                                              
                                                                         
   
                
   
                                                           
   
                                                                                  
    
	public function unregisterAbonnement(array $endpoint, array $abonnement): void {
		$client = $this->registers->findClientForEndpoint($endpoint);
		if ($client === null) {
			throw new NrcSubscriptionFailedException('ZGW NRC: endpoint has no resolvable client');
		}

		$abonnementUrl = (string)($abonnement['abonnementUrl'] ?? '');
		if ($abonnementUrl !== '') {
			try {
				$this->api->callComponent(
					componentUrl: $abonnementUrl,
					method: 'DELETE',
					path: '',
					client: $client
				);
			} catch (Throwable $e) {
				throw new NrcSubscriptionFailedException(
					'ZGW NRC: DELETE failed: ' . $e->getMessage(),
					0,
					$e
				);
			}
		}

		$abonnement['active'] = false;
		$uuid = (string)($abonnement['@self']['uuid'] ?? $abonnement['id'] ?? '');
		$saveUuid = null;
		if ($uuid !== '') {
			$saveUuid = $uuid;
		}

		$this->registers->save(ZgwRegisterAccess::SCHEMA_ABONN, $abonnement, $saveUuid);
	}                            

	   
                                                   
   
                                            
                                                          
    
	public function generateCallbackBearer(): string {
		return bin2hex(random_bytes(32));
	}                              

	   
                                                                              
   
                                                              
   
                                                                      
    
	private function normaliseKanalen(array $kanalen): array {
		$out = [];
		foreach ($kanalen as $entry) {
			$name = (string)($entry['name'] ?? '');
			if ($name === '') {
				continue;
			}

			$filters = $entry['filters'] ?? [];
			if (is_array($filters) === false) {
				$filters = [];
			}

			ksort($filters);
			$out[] = ['name' => $name, 'filters' => $filters];
		}

		usort($out, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
		return $out;
	}                        
}           
