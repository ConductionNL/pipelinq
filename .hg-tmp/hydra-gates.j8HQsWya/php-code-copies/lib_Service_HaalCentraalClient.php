<?php

   
                               
  
                                                                                          
                                                                                           
                                                                                       
                                                    
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                  
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                       
  
                                                                          
  
                                                  
                                                                                                                                            
   
class HaalCentraalClient {
	   
                                                       
    
	private const DEFAULT_BASE_URL = 'https://api.haalcentraal.nl/brp/v2.0';

	   
                                  
    
	private const DEFAULT_OAUTH_ENDPOINT = 'https://oauth.haalcentraal.nl/token';

	   
                                                              
    
	private const TOKEN_TTL_SECONDS = 3000;

	   
                                           
    
	private const LOOKUP_TIMEOUT_SECONDS = 5;

	   
                                                                                 
                                                                                   
                                                                                
                                                          
   
               
    
	private const OR_EMPTY_SENTINEL_KEY = '__or_brp_empty__';

	   
                                                                    
   
                                                                
    
	private ?array $tokenCache = null;

	   
                
   
                                                                
                                            
                                                                
                                                                   
                                                                                         
    
	public function __construct(
		private IClientService $clientService,
		private IAppConfig $appConfig,
		private ICrypto $crypto,
		private LoggerInterface $logger,
		private IURLGenerator $urlGenerator,
	) {
	}                   

	   
                                                                                     
   
                                                                                  
                                          
   
                                                                                
                                                                               
                                                                              
                                                                                   
                                                                      
                                                                      
                                                                             
                                                                              
                                                                             
                                                                         
   
                                       
                                                                                       
   
                                    
   
                                 
   
                                           
                                                                              
                                                                              
   
                                                                                                                               
                                                                                                         
                                                                                                         
    
	public function lookupPersoon(string $bsn, ?string $requestIdContext = null): ?array {
		$maskedBsn = BsnValidationService::mask($bsn);

		                                                                         
		                                                                        
		                                                                          
		$viaOr = $this->lookupViaOpenRegister(bsn: $bsn, maskedBsn: $maskedBsn);
		if ($viaOr !== null) {
			                                                                  
			                                                                  
			                                                                      
			if (isset($viaOr[self::OR_EMPTY_SENTINEL_KEY]) === true) {
				return null;
			}

			return $viaOr;
		}

		$token = $this->getAccessToken();

		$client = $this->buildHttpClient();
		$url = $this->getBaseUrl() . '/personen';

		try {
			$start = microtime(true);
			$response = $client->post(
				$url,
				[
					'headers' => [
						'Authorization' => 'Bearer ' . $token,
						'Accept' => 'application/hal+json',
						'Content-Type' => 'application/json',
						'User-Agent' => 'Pipelinq/' . Application::APP_ID . ' (Nextcloud)',
					],
					'body' => json_encode(
						[
							'type' => 'RaadpleegMetBurgerservicenummer',
							'burgerservicenummer' => [$bsn],
							'fields' => $this->getDefaultFields(),
						],
						JSON_THROW_ON_ERROR
					),
					'timeout' => self::LOOKUP_TIMEOUT_SECONDS,
					'connect_timeout' => 2,
				]
			);
			$duration = (int)((microtime(true) - $start) * 1000);

			$status = (int)$response->getStatusCode();
			$correlationId = self::firstHeader(response: $response, name: 'X-Correlation-ID');
			if ($correlationId === null) {
				$correlationId = self::firstHeader(response: $response, name: 'x-correlation-id');
			}

			if ($status === 404) {
				$this->logger->info(
					'HaalCentraal lookup not-found',
					['bsn' => $maskedBsn, 'correlationId' => $correlationId, 'duration_ms' => $duration]
				);
				return null;
			}

			if ($status < 200 || $status >= 300) {
				$this->logger->warning(
					'HaalCentraal lookup error',
					['bsn' => $maskedBsn, 'status' => $status, 'correlationId' => $correlationId]
				);
				throw new HaalCentraalException(
					'BRP momenteel niet bereikbaar — probeer over enkele minuten opnieuw.',
					$status,
					$correlationId,
				);
			}

			$payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
			$person = $this->parseFirstPerson(payload: $payload);
			if ($person === null) {
				return null;
			}

			$person['_correlationId'] = $correlationId;
			$person['_responseDurationMs'] = $duration;
			$person['_responseStatus'] = $status;
			return $person;
		} catch (HaalCentraalException $e) {
			throw $e;
		} catch (Throwable $e) {
			$this->logger->error(
				'HaalCentraal transport error',
				['bsn' => $maskedBsn, 'error' => $e->getMessage(), 'requestId' => $requestIdContext]
			);
			throw new HaalCentraalException(
				'BRP momenteel niet bereikbaar — probeer over enkele minuten opnieuw.',
				0,
				null,
				$e,
			);
		}         
	}                     

	   
                                                                          
   
                                                                        
                                                                           
                                                                            
                                                
                                                                            
                                         
                                                                              
                                                                              
                  
                                                                          
                                                                    
                                                                    
                                                                    
                                                       
   
            
                                                            
                                                                          
                                                                          
                                                                           
                                                                        
                                                                             
                                                                              
                                                                   
   
                                                                         
                
   
                                                                                
                                                                 
   
                                                                                                          
   
                                           
   
                                                                                                                     
                                                                                                                     
    
	private function lookupViaOpenRegister(string $bsn, string $maskedBsn): ?array {
		$params = http_build_query(['bsn' => $bsn]);
		$url = $this->urlGenerator->getAbsoluteURL('/apps/openregister/api/integrations/brp/person?' . $params);

		try {
			$client = $this->clientService->newClient();
			$response = $client->get(
				$url,
				[
					'timeout' => self::LOOKUP_TIMEOUT_SECONDS,
					'connect_timeout' => 2,
					'headers' => ['OCS-APIREQUEST' => 'true', 'Accept' => 'application/json'],
					'nextcloud' => ['allow_local_address' => true],
				]
			);
		} catch (Throwable $e) {
			                                                                  
			                                                                      
			                                                  
			$this->logger->debug(
				'BRP OR leaf unavailable, falling back to legacy HaalCentraal path',
				['bsn' => $maskedBsn, 'error' => $e->getMessage()]
			);
			return null;
		}         

		$status = (int)$response->getStatusCode();
		if ($status !== 200) {
			return null;
		}

		$payload = json_decode((string)$response->getBody(), true);
		if (is_array($payload) === false || isset($payload['results']) === false || is_array($payload['results']) === false) {
			return null;
		}

		$results = $payload['results'];
		if (empty($results) === true) {
			                                                                   
			                                                                          
			return [self::OR_EMPTY_SENTINEL_KEY => true];
		}

		$first = $results[0];
		if (is_array($first) === false) {
			return null;
		}

		$person = $this->normalisePerson(raw: $first);

		$meta = [];
		if (is_array($payload['meta'] ?? null) === true) {
			$meta = $payload['meta'];
		}

		$correlationId = ($meta['correlationId'] ?? null);
		if ($correlationId !== null) {
			$correlationId = (string)$correlationId;
		}

		$person['_correlationId'] = $correlationId;
		$person['_responseDurationMs'] = (int)($meta['durationMs'] ?? 0);
		$person['_responseStatus'] = (int)($meta['status'] ?? 200);

		$this->logger->info(
			'BRP OR leaf lookup succeeded',
			[
				'bsn' => $maskedBsn,
				'correlationId' => $correlationId,
				'duration_ms' => $person['_responseDurationMs'],
				'source' => 'openregister-leaf',
			]
		);

		return $person;
	}                             

	   
                                                                            
   
                                                                                 
   
                                  
   
                                                                              
   
                                                                                                                                         
    
	public function getCertificateExpiry(): ?DateTimeImmutable {
		$certPath = $this->appConfig->getValueString(Application::APP_ID, 'brp.cert_path', '');
		if ($certPath === '' || file_exists($certPath) === false) {
			return null;
		}

		$contents = @file_get_contents($certPath);
		if ($contents === false) {
			return null;
		}

		$info = @openssl_x509_parse($contents);
		if (is_array($info) === false || isset($info['validTo_time_t']) === false) {
			return null;
		}

		try {
			return (new DateTimeImmutable('@' . $info['validTo_time_t']))->setTimezone(new DateTimeZone('UTC'));
		} catch (Throwable $e) {
			return null;
		}
	}                            

	   
                                                                                  
   
                
   
                                                                           
    
	public function isConfigured(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, 'brp.client_id', '') !== ''
			&& $this->appConfig->getValueString(Application::APP_ID, 'brp.client_secret_encrypted', '') !== ''
			&& $this->appConfig->getValueString(Application::APP_ID, 'brp.cert_path', '') !== ''
			&& $this->appConfig->getValueString(Application::APP_ID, 'brp.key_path', '') !== '';
	}                    

	   
                                                                    
   
                                
   
                                                                
   
                                                                                                                              
                                                                                                                              
    
	private function getAccessToken(): string {
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		if ($this->tokenCache !== null && $this->tokenCache['expiresAt'] > $now) {
			return $this->tokenCache['token'];
		}

		$clientId = $this->appConfig->getValueString(Application::APP_ID, 'brp.client_id', '');
		$clientSecretEnc = $this->appConfig->getValueString(Application::APP_ID, 'brp.client_secret_encrypted', '');
		$oauthEndpoint = $this->appConfig->getValueString(Application::APP_ID, 'brp.oauth_endpoint', self::DEFAULT_OAUTH_ENDPOINT);

		if ($clientId === '' || $clientSecretEnc === '') {
			throw new HaalCentraalException(
				'BRP-integratie is niet geconfigureerd (OAuth2 client_id/secret ontbreken).',
				503,
			);
		}

		try {
			$clientSecret = $this->crypto->decrypt($clientSecretEnc);
		} catch (Throwable $e) {
			throw new HaalCentraalException(
				'BRP-integratie configuratie is onleesbaar — controleer admin instellingen.',
				503,
				null,
				$e,
			);
		}

		$client = $this->buildHttpClient(forToken: true);
		try {
			$response = $client->post(
				$oauthEndpoint,
				[
					'headers' => [
						'Accept' => 'application/json',
						'Content-Type' => 'application/x-www-form-urlencoded',
					],
					'body' => http_build_query(
						[
							'grant_type' => 'client_credentials',
							'client_id' => $clientId,
							'client_secret' => $clientSecret,
							'scope' => 'haalcentraal.brp.personen',
						]
					),
					'timeout' => self::LOOKUP_TIMEOUT_SECONDS,
					'connect_timeout' => 2,
				]
			);
		} catch (Throwable $e) {
			throw new HaalCentraalException(
				'BRP OAuth2-endpoint is niet bereikbaar.',
				0,
				null,
				$e,
			);
		}         

		$status = (int)$response->getStatusCode();
		$payload = json_decode((string)$response->getBody(), true);
		$token = '';
		if (is_array($payload) === true) {
			$token = (string)($payload['access_token'] ?? '');
		}

		if ($status < 200 || $status >= 300 || $token === '') {
			throw new HaalCentraalException(
				'BRP OAuth2-token aanvraag is mislukt.',
				$status,
			);
		}

		$expiresAt = $now->modify('+' . self::TOKEN_TTL_SECONDS . ' seconds');
		$this->tokenCache = ['token' => $token, 'expiresAt' => $expiresAt];
		return $token;
	}                      

	   
                                                                              
   
                                                                                         
   
                   
   
                                                                                                                
    
	private function buildHttpClient(bool $forToken = false): IClient {
		$client = $this->clientService->newClient();
		                                                                              
		                                                                                 
		                                                                                
		                                                                               
		                                                    
		if ($forToken === false) {
			$certPath = $this->appConfig->getValueString(Application::APP_ID, 'brp.cert_path', '');
			$keyPath = $this->appConfig->getValueString(Application::APP_ID, 'brp.key_path', '');
			$caBundle = $this->appConfig->getValueString(Application::APP_ID, 'brp.ca_bundle', '');
			if ($certPath !== '' && $keyPath !== '') {
				                                                                         
				                                                                           
				if (method_exists($client, 'setDefaultOptions') === true) {
					$verify = true;
					if ($caBundle !== '') {
						$verify = $caBundle;
					}

					$client->setDefaultOptions(
						[
							'cert' => $certPath,
							'ssl_key' => $keyPath,
							'verify' => $verify,
						]
					);
				}
			}
		}        

		return $client;
	}                       

	   
                                                           
   
                  
    
	private function getBaseUrl(): string {
		return rtrim(
			$this->appConfig->getValueString(Application::APP_ID, 'brp.base_url', self::DEFAULT_BASE_URL),
			'/'
		);
	}                  

	   
                                                  
   
                             
    
	private function getDefaultFields(): array {
		return [
			'burgerservicenummer',
			'naam.voornamen',
			'naam.voorletters',
			'naam.voorvoegsel',
			'naam.geslachtsnaam',
			'naam.adellijkeTitelPredicaat',
			'geboorte.datum',
			'geboorte.plaats',
			'geboorte.land',
			'gender',
			'residence',
			'indicationSecret',
		];
	}                        

	   
                                                                  
   
                                       
   
                                    
    
	private function parseFirstPerson(mixed $payload): ?array {
		if (is_array($payload) === false) {
			return null;
		}

		$list = $payload['personen'] ?? $payload['_embedded']['personen'] ?? [];
		if (is_array($list) === false || empty($list) === true) {
			return null;
		}

		$first = $list[0];
		if (is_array($first) === false) {
			return null;
		}

		return $this->normalisePerson(raw: $first);
	}                         

	   
                                                                             
   
                                                                        
   
                               
    
	private function normalisePerson(array $raw): array {
		$name = [];
		if (is_array($raw['name'] ?? null) === true) {
			$name = $raw['name'];
		}

		$birth = [];
		if (is_array($raw['geboorte'] ?? null) === true) {
			$birth = $raw['geboorte'];
		}

		$residence = [];
		if (is_array($raw['residence'] ?? null) === true) {
			$residence = $raw['residence'];
		}

		$gender = $raw['gender'] ?? '';
		if (is_array($gender) === true) {
			$gender = ($gender['code'] ?? '');
		}

		$gender = (string)$gender;

		$birthPlace = $birth['plaats'] ?? '';
		if (is_array($birthPlace) === true) {
			$birthPlace = ($birthPlace['omschrijving'] ?? '');
		}

		$birthPlace = (string)$birthPlace;

		$birthCountry = $birth['land'] ?? '';
		if (is_array($birthCountry) === true) {
			$birthCountry = ($birthCountry['code'] ?? '');
		}

		$birthCountry = (string)$birthCountry;

		return [
			'givenNames' => (string)($name['givenNames'] ?? ''),
			'initials' => (string)($name['initials'] ?? ''),
			'namePrefix' => (string)($name['namePrefix'] ?? ''),
			'surname' => (string)($name['surname'] ?? ''),
			'nobiliaryTitle' => (string)($name['adellijkeTitelPredicaat'] ?? ''),
			'dateOfBirth' => (string)($birth['datum']['datum'] ?? $birth['datum'] ?? ''),
			'birthPlace' => $birthPlace,
			'birthCountry' => $birthCountry,
			'gender' => self::mapGeslacht(code: $gender),
			'residence' => self::mapResidence(residence: $residence),
			'indicationSecret' => (string)($raw['indicationSecret'] ?? '0'),
			'sourceSystem' => 'HaalCentraal-BRP-v2.0',
		];
	}                       

	   
                                                          
   
                                                       
   
                  
    
	private static function mapGeslacht(string $code): string {
		$code = strtoupper($code);
		if ($code === 'M' || $code === 'MAN') {
			return 'male';
		}

		if ($code === 'V' || $code === 'VROUW' || $code === 'F') {
			return 'female';
		}

		return 'unknown';
	}                   

	   
                                                                  
   
                                                                                  
   
                               
    
	private static function mapResidence(array $residence): array {
		$adres = $residence;
		if (is_array($residence['verblijfadres'] ?? null) === true) {
			$adres = $residence['verblijfadres'];
		}

		$land = $residence['land'] ?? ($adres['land'] ?? null);

		$houseNumber = null;
		if (isset($adres['huisnummer']) === true) {
			$houseNumber = (int)$adres['huisnummer'];
		}

		if (is_array($land) === true) {
			$land = ($land['omschrijving'] ?? $land['code'] ?? '');
		}

		$landValue = (string)($land ?? '');

		return [
			'straat' => (string)($adres['officieleStraatnaam'] ?? $adres['straat'] ?? ''),
			'huisnummer' => $houseNumber,
			'huisletter' => (string)($adres['huisletter'] ?? ''),
			'huisnummertoevoeging' => (string)($adres['huisnummertoevoeging'] ?? ''),
			'postcode' => (string)($adres['postcode'] ?? ''),
			'woonplaats' => (string)($adres['woonplaats'] ?? $adres['woonplaatsnaam'] ?? ''),
			'land' => $landValue,
		];
	}                         

	   
                                                                                
   
                                                     
                                                
   
                       
    
	private static function firstHeader(object $response, string $name): ?string {
		if (method_exists($response, 'getHeader') === true) {
			$value = $response->getHeader($name);
			if (is_string($value) === true && $value !== '') {
				return $value;
			}

			if (is_array($value) === true && isset($value[0]) === true) {
				return (string)$value[0];
			}
		}

		return null;
	}                   
}           
