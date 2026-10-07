<?php

   
                            
  
                                                                     
                                                             
                                                                     
                                                   
  
                                                                   
                                                                
                                                               
               
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                                                                  
                                                                                                      
                                                                                                      
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DOMDocument;
use OCA\Pipelinq\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use RuntimeException;

   
                                              
  
                                                                                     
                                                
   
class LogiusConnector {
	   
                                            
   
            
    
	public const MAX_ATTACHMENT_BYTES = (25 * 1024 * 1024);

	   
                                            
   
                           
    
	public const ALLOWED_MIME = [
		'application/pdf',
		'image/png',
		'image/jpeg',
	];

	   
                                     
   
            
    
	public const MAX_SUBJECT_CHARS = 200;

	   
                                                         
   
               
    
	public const DEFAULT_BASE_URL = 'https://api.logius.nl/berichtenbox/v1.7';

	   
                                                
   
               
    
	public const CONFIG_TOKEN_URL = 'logius_token_url';

	   
                                                 
   
               
    
	public const CONFIG_BASE_URL = 'logius_base_url';

	   
                                           
   
               
    
	public const CONFIG_CLIENT_ID = 'logius_client_id';

	   
                                               
   
               
    
	public const CONFIG_CLIENT_SECRET = 'logius_client_secret';

	   
                                                  
   
               
    
	public const CONFIG_WEBHOOK_SECRET = 'logius_webhook_secret';

	   
                                                     
   
                                      
    
	private ?array $tokenCache = null;

	   
                
   
                                                             
                                                    
                                          
    
	public function __construct(
		private readonly IClientService $clientService,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}                   

	   
                                                           
   
                                                
   
                                
   
                                                     
                                                                                      
                                                 
    
	public function authenticate(): string {
		$now = time();
		if ($this->tokenCache !== null && $this->tokenCache[1] > ($now + 30)) {
			return $this->tokenCache[0];
		}

		$tokenUrl = $this->appConfig->getValueString(
			Application::APP_ID,
			self::CONFIG_TOKEN_URL,
			self::DEFAULT_BASE_URL . '/oauth/token'
		);
		$clientId = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_CLIENT_ID, '');
		$clientSecret = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_CLIENT_SECRET, '');

		if ($clientId === '' || $clientSecret === '') {
			throw new RuntimeException('Logius client credentials are not configured.');
		}

		$client = $this->clientService->newClient();
		try {
			$response = $client->post(
				$tokenUrl,
				[
					'auth' => [$clientId, $clientSecret],
					'body' => ['grant_type' => 'client_credentials'],
					'timeout' => 30,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Logius OAuth token request failed.',
				['exception' => $e->getMessage()]
			);
			throw new RuntimeException('Logius authentication failed: ' . $e->getMessage(), 0, $e);
		}

		$payload = $this->decodeJson(response: $response);
		if (isset($payload['access_token']) === false || is_string($payload['access_token']) === false) {
			throw new RuntimeException('Logius OAuth response missing access_token.');
		}

		$expiresIn = (int)($payload['expires_in'] ?? 3600);
		$this->tokenCache = [(string)$payload['access_token'], ($now + $expiresIn)];
		return $this->tokenCache[0];
	}                    

	   
                                                              
   
                                                                     
                                                              
   
                                                          
                                                            
                                                                      
                                                        
   
                                                                           
   
                                                                
                                                                                      
                                                 
    
	public function sendMessage(array $message, string $tenantPkiCert, string $tenantPkiKey): array {
		$this->validateOutboundPayload(message: $message);

		$body = [
			'messageId' => (string)($message['uuid'] ?? $message['id'] ?? $this->newUuidV4()),
			'subject' => (string)$message['subject'],
			'body' => (string)$message['body'],
			'attachments' => array_map(
				static function (array $att): array {
					return [
						'filename' => (string)($att['filename'] ?? 'attachment'),
						'mimeType' => (string)($att['mime'] ?? 'application/pdf'),
						'sizeBytes' => (int)($att['sizeBytes'] ?? 0),
						'content' => (string)($att['contentBase64'] ?? ''),
					];
				},
				($message['attachments'] ?? [])
			),
			'recipient' => ['bsn' => (string)($message['bsnPlain'] ?? '')],
		];

		$baseUrl = $this->appConfig->getValueString(
			Application::APP_ID,
			self::CONFIG_BASE_URL,
			self::DEFAULT_BASE_URL
		);
		$endpoint = rtrim($baseUrl, '/') . '/messages';

		$signature = $this->signRequest(body: $body, pkiCertPem: $tenantPkiCert, pkiKeyPem: $tenantPkiKey);
		$headers = [
			'Authorization' => 'Bearer ' . $this->authenticate(),
			'Content-Type' => 'application/json',
			'X-Message-ID' => $body['messageId'],
			'X-Timestamp' => gmdate('c'),
			'X-Signature-Algorithm' => 'RSA-SHA256',
			'X-Signature' => $signature,
		];

		$client = $this->clientService->newClient();
		try {
			$response = $client->post(
				$endpoint,
				[
					'headers' => $headers,
					'body' => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
					'timeout' => 60,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Logius sendMessage transport failure.',
				['exception' => $e->getMessage(), 'messageId' => $body['messageId']]
			);
			throw new RuntimeException('Logius sendMessage failed: ' . $e->getMessage(), 0, $e);
		}

		$raw = $this->decodeJson(response: $response);
		$logiusMessageId = (string)($raw['logiusMessageId'] ?? $raw['messageId'] ?? '');
		if ($logiusMessageId === '') {
			throw new RuntimeException('Logius response missing logiusMessageId.');
		}

		return [
			'logiusMessageId' => $logiusMessageId,
			'raw' => $raw,
		];
	}                   

	   
                                                               
   
                                                
   
                
   
                                                                     
                                                                                                   
    
	public function validateOutboundPayload(array $message): void {
		$subject = (string)($message['subject'] ?? '');
		if ($subject === '' || mb_strlen($subject) > self::MAX_SUBJECT_CHARS) {
			throw new RuntimeException('subject must be 1.. ' . self::MAX_SUBJECT_CHARS . ' chars.');
		}

		$body = (string)($message['body'] ?? '');
		if ($body === '') {
			throw new RuntimeException('body must be non-empty XHTML strict.');
		}

		$this->assertWellFormedXhtml(body: $body);
		$this->validateAttachments(attachments: ($message['attachments'] ?? []));
	}                               

	   
                                                      
   
                                         
   
                
   
                                                                             
    
	private function assertWellFormedXhtml(string $body): void {
		                                                          
		$previous = libxml_use_internal_errors(true);
		try {
			$doc = new DOMDocument();
			$wrapped = '<?xml version="1.0" encoding="UTF-8"?><root>' . $body . '</root>';
			if ($doc->loadXML($wrapped) === false) {
				throw new RuntimeException('body is not valid XHTML strict.');
			}
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}
	}                             

	   
                                                                                
   
                                                              
   
                
   
                                                                     
    
	private function validateAttachments(mixed $attachments): void {
		if (is_array($attachments) === false) {
			throw new RuntimeException('attachments must be an array.');
		}

		$totalSize = 0;
		foreach ($attachments as $att) {
			if (is_array($att) === false) {
				throw new RuntimeException('attachment entries must be arrays.');
			}

			$mime = (string)($att['mime'] ?? '');
			if (in_array($mime, self::ALLOWED_MIME, true) === false) {
				throw new RuntimeException('attachment mime must be PDF/PNG/JPG.');
			}

			$totalSize += (int)($att['sizeBytes'] ?? 0);
		}

		if ($totalSize > self::MAX_ATTACHMENT_BYTES) {
			throw new RuntimeException('attachments total size exceeds 25 MB.');
		}
	}                           

	   
                                                                   
   
                                                                  
                                                                 
                      
   
                                                  
                                                                         
                                                           
   
                                            
   
                                                                          
   
                                                                             
                                                                                  
                                                     
   
                                                                                      
                                                 
    
	public function signRequest(array $body, string $pkiCertPem, string $pkiKeyPem): string {
		if ($pkiKeyPem === '') {
			                                                                 
			                                                              
			                                                              
			                         
			return base64_encode(hash('sha256', json_encode($body), true));
		}

		$key = openssl_pkey_get_private($pkiKeyPem);
		if ($key === false) {
			throw new RuntimeException('Invalid tenant PKI-overheid private key.');
		}

		$signature = '';
		$payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$signed = openssl_sign($payload, $signature, $key, OPENSSL_ALGO_SHA256);
		if ($signed === false) {
			throw new RuntimeException('openssl_sign failed for Logius request.');
		}

		return base64_encode($signature);
	}                   

	   
                                                          
   
                                     
   
                                                                          
   
                                                          
                                                                                      
                                                 
    
	public function checkMailboxExists(string $bsn): bool {
		$baseUrl = $this->appConfig->getValueString(
			Application::APP_ID,
			self::CONFIG_BASE_URL,
			self::DEFAULT_BASE_URL
		);
		$endpoint = rtrim($baseUrl, '/') . '/mailbox/check';

		$client = $this->clientService->newClient();
		try {
			$response = $client->post(
				$endpoint,
				[
					'headers' => [
						'Authorization' => 'Bearer ' . $this->authenticate(),
						'Content-Type' => 'application/json',
					],
					'body' => json_encode(['bsn' => $bsn]),
					'timeout' => 30,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Logius mailbox-check failed.',
				['exception' => $e->getMessage()]
			);
			throw new RuntimeException('Logius mailbox-check failed: ' . $e->getMessage(), 0, $e);
		}

		$payload = $this->decodeJson(response: $response);
		return (bool)($payload['available'] ?? false);
	}                          

	   
                                                                     
                                                                      
                                   
   
                                                            
                                                                 
                                                                
   
                                                 
                                                                                                   
    
	public function handleWebhookSignature(IRequest $request, string $rawBody): bool {
		$secret = $this->appConfig->getValueString(
			Application::APP_ID,
			self::CONFIG_WEBHOOK_SECRET,
			''
		);
		if ($secret === '') {
			$this->logger->warning(
				'Logius webhook secret is not configured; refusing webhook.'
			);
			return false;
		}

		$headerName = 'X-Logius-Signature';
		$provided = (string)$request->getHeader($headerName);
		if ($provided === '') {
			return false;
		}

		$expected = hash_hmac('sha256', $rawBody, $secret);
		return hash_equals($expected, $provided);
	}                              

	   
                                
   
                                   
                                                                                      
                                                 
    
	public function newUuidV4(): string {
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3F) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}                 

	   
                                                                    
   
                                                 
   
                                  
   
                                             
    
	private function decodeJson(IResponse $response): array {
		$body = (string)$response->getBody();
		if ($body === '') {
			return [];
		}

		$decoded = json_decode($body, true);
		if (json_last_error() !== JSON_ERROR_NONE || is_array($decoded) === false) {
			throw new RuntimeException(
				'Logius response is not valid JSON: ' . json_last_error_msg()
			);
		}

		return $decoded;
	}                  
}           
