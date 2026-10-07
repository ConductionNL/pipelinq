<?php

   
                         
  
                                                                         
                                                                          
                                          
  
                                                                             
                                                      
                                                                             
                                                                        
                                
                                                                        
                                                                             
                                                                            
                                                                         
  
                                                                    
                                                                           
                                                                           
                                                                           
                               
  
                    
                                     
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                                                 
                                                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Zgw;

use OCA\Pipelinq\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                              
  
                                                                                 
   
class ZgwApiClient {
	   
                                                       
                                               
    
	private const DEFAULT_TIMEOUT = 30;

	   
                
   
                                                                       
                                                                          
                                                
    
	public function __construct(
		private IClientService $clientService,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                    
   
            
                                                      
                                                      
                                            
                                                        
                                                                      
                                              
   
                                                                         
   
                                                                       
                                                               
   
                               
   
                                                                     
                                                                                  
    
	public function mintJwt(array $client, int $expiresIn = 3600): string {
		$secret = $this->resolveClientSecret(reference: (string)($client['secretVaultRef'] ?? ''));
		if ($secret === '') {
			throw new ZgwException(
				sprintf(
					'ZGW: unable to resolve client secret for "%s"',
					(string)($client['clientIdentifier'] ?? '?')
				)
			);
		}

		$now = time();
		$header = ['typ' => 'JWT', 'alg' => 'HS256', 'client_identifier' => (string)$client['clientIdentifier']];

		$payload = [
			'iss' => (string)$client['clientIdentifier'],
			'client_id' => (string)$client['clientIdentifier'],
			'user_id' => (string)($client['userId'] ?? ''),
			'user_representation' => (string)($client['userRepresentation'] ?? ''),
			'iat' => $now,
			'exp' => ($now + max(60, $expiresIn)),
		];

		return self::encodeJwt(header: $header, payload: $payload, secret: $secret);
	}               

	   
                                           
   
                                                            
                                                                               
                                                                                         
                                                                         
   
                                                                          
                                                                       
                              
   
                                                                       
                                                                  
                                                                           
                                                         
                                                                        
                                                                             
                                                                      
   
                                                                                     
   
                              
                                        
                        
   
                                                                                  
    
	public function callComponent(
		string $componentUrl,
		string $method,
		string $path,
		array $client,
		?array $body = null,
		array $extraHeaders = [],
		array $query = [],
	): array {
		$expiresIn = (int)($client['tokenLifespanSeconds'] ?? 3600);
		$jwt = $this->mintJwt(client: $client, expiresIn: $expiresIn);

		$url = rtrim($componentUrl, '/') . '/' . ltrim($path, '/');
		if ($query !== []) {
			$separator = '?';
			if (str_contains($url, '?') === true) {
				$separator = '&';
			}

			$url .= $separator . http_build_query($query);
		}

		$headers = array_merge(
			[
				'Authorization' => 'Bearer ' . $jwt,
				'Accept' => 'application/json',
				'Accept-Crs' => 'EPSG:4326',
				'Content-Crs' => 'EPSG:4326',
			],
			$extraHeaders
		);

		$options = [
			'headers' => $headers,
			'timeout' => $this->getTimeout(),
		];

		if ($body !== null) {
			$options['headers']['Content-Type'] = 'application/json';
			$options['body'] = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		}

		$nextcloudClient = $this->clientService->newClient();

		try {
			$response = match (strtoupper($method)) {
				'GET' => $nextcloudClient->get($url, $options),
				'POST' => $nextcloudClient->post($url, $options),
				'PUT' => $nextcloudClient->put($url, $options),
				'PATCH' => $nextcloudClient->request('PATCH', $url, $options),
				'DELETE' => $nextcloudClient->delete($url, $options),
				default => throw new ZgwException('ZGW: unsupported HTTP method "' . $method . '"'),
			};
		} catch (Throwable $e) {
			$this->translateTransportError(error: $e, url: $url, method: $method);
			                                                                                              
			throw new ZgwException('ZGW: transport error', 0, $e);
		}

		$status = (int)$response->getStatusCode();
		$rawBody = (string)$response->getBody();
		$decoded = [];
		if ($rawBody !== '') {
			$decoded = json_decode($rawBody, true);
		}

		if (is_array($decoded) === false) {
			$decoded = ['raw' => $rawBody];
		}

		$headerMap = self::normaliseHeaders(headers: $response->getHeaders());

		return ['status' => $status, 'body' => $decoded, 'headers' => $headerMap];
	}                     

	   
                                                                     
   
                                                                          
                                                                            
   
                                                        
   
                                                                        
                                                          
    
	public function resolveClientSecret(string $reference): string {
		if ($reference === '') {
			return '';
		}

		if (str_starts_with($reference, 'vault://') === false) {
			return $reference;
		}

		                                                                      
		                                                                        
		                                                                     
		                                                                   
		                            
		$path = substr($reference, strlen('vault://'));
		$key = 'zgw.vault.' . $path;

		return $this->appConfig->getValueString(Application::APP_ID, $key, '');
	}                           

	   
                                                              
   
                                                     
                                                          
                                                              
   
                
   
                              
                                        
                                   
                        
    
	private function translateTransportError(Throwable $error, string $url, string $method): void {
		[$status, $body] = $this->extractErrorContext(error: $error);
		$message = $error->getMessage();

		$this->logger->info(
			'ZGW: HTTP transport error',
			['method' => $method, 'url' => $url, 'status' => $status, 'msg' => $message]
		);

		if ($status === 401 || $status === 403) {
			if (self::looksLikeClockSkew(text: $body) === true || self::looksLikeClockSkew(text: $message) === true) {
				throw new ClockSkewException(
					sprintf('ZGW: JWT clock-skew rejection from %s (status %d)', $url, $status),
					observedTime: time()
				);
			}
		}

		if ($status === 404) {
			throw new ZgwResourceNotFoundException($url);
		}

		if ($status === 412) {
			                                                                     
			                                                              
			throw new OptimisticLockException(
				sprintf('ZGW: 412 Precondition Failed on %s %s', $method, $url),
				staleRepresentation: [],
				freshRepresentation: []
			);
		}

		throw new ZgwException(
			sprintf('ZGW: %s %s failed (status %d): %s', $method, $url, $status, $message),
			$status,
			$error
		);
	}                               

	   
                                                                         
   
                                                                  
                                                                           
                                  
   
                                                     
   
                                                           
    
	private function extractErrorContext(Throwable $error): array {
		                                                                     
		                                      
		$status = (int)$error->getCode();

		$body = '';
		if (method_exists($error, 'getResponse') === true) {
			$resp = $error->getResponse();
			if ($resp !== null && method_exists($resp, 'getStatusCode') === true) {
				$status = (int)$resp->getStatusCode();
			}

			if ($resp !== null && method_exists($resp, 'getBody') === true) {
				$body = (string)$resp->getBody();
			}
		}

		return [$status, $body];
	}                           

	   
                                                                      
   
                                             
   
                                                                               
                                                          
    
	public static function looksLikeClockSkew(string $text): bool {
		if ($text === '') {
			return false;
		}

		$needles = ['JWT verlopen', 'JWT nog niet geldig', 'jwt verlopen', 'jwt nog niet geldig'];
		foreach ($needles as $needle) {
			if (stripos($text, $needle) !== false) {
				return true;
			}
		}

		return false;
	}                          

	   
                                                      
   
                                                                            
   
                                 
    
	private static function normaliseHeaders(array $headers): array {
		$out = [];
		foreach ($headers as $key => $value) {
			if (is_array($value) === true) {
				$out[strtolower((string)$key)] = (string)($value[0] ?? '');
				continue;
			}

			$out[strtolower((string)$key)] = (string)$value;
		}

		return $out;
	}                        

	   
                                                                        
                                                            
   
                                                   
                                                     
                                       
   
                               
                                                          
    
	public static function encodeJwt(array $header, array $payload, string $secret): string {
		$segments = [];
		$segments[] = self::base64UrlEncode(input: (string)json_encode($header, JSON_UNESCAPED_SLASHES));
		$segments[] = self::base64UrlEncode(input: (string)json_encode($payload, JSON_UNESCAPED_SLASHES));
		$signing = implode('.', $segments);
		$signature = hash_hmac('sha256', $signing, $secret, true);
		$segments[] = self::base64UrlEncode(input: $signature);
		return implode('.', $segments);
	}                 

	   
                                                  
   
                                                      
   
                                   
   
                                                                                   
                                                          
    
	public static function inspectJwt(string $jwt): ?array {
		$parts = explode('.', $jwt);
		if (count($parts) !== 3) {
			return null;
		}

		$header = json_decode((string)self::base64UrlDecode(input: $parts[0]), true);
		$payload = json_decode((string)self::base64UrlDecode(input: $parts[1]), true);
		if (is_array($header) === false || is_array($payload) === false) {
			return null;
		}

		return ['header' => $header, 'payload' => $payload];
	}                  

	   
                                   
   
                                   
                                        
   
                                                 
                                                          
    
	public static function verifyJwt(string $jwt, string $secret): bool {
		$parts = explode('.', $jwt);
		if (count($parts) !== 3) {
			return false;
		}

		$signing = $parts[0] . '.' . $parts[1];
		$expected = hash_hmac('sha256', $signing, $secret, true);
		$actual = self::base64UrlDecode(input: $parts[2]);
		return ($actual !== null && hash_equals($expected, $actual) === true);
	}                 

	   
                      
   
                                   
   
                                                
    
	private static function base64UrlEncode(string $input): string {
		return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
	}                       

	   
                      
   
                                         
   
                                                         
    
	private static function base64UrlDecode(string $input): ?string {
		$padded = $input . str_repeat('=', (4 - (strlen($input) % 4)) % 4);
		$decoded = base64_decode(strtr($padded, '-_', '+/'), true);
		if ($decoded === false) {
			return null;
		}

		return $decoded;
	}                       

	   
                                     
   
               
    
	private function getTimeout(): int {
		$value = $this->appConfig->getValueInt(Application::APP_ID, 'zgw.http_timeout', self::DEFAULT_TIMEOUT);
		if ($value > 0) {
			return $value;
		}

		return self::DEFAULT_TIMEOUT;
	}                  
}           
