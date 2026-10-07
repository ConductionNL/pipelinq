<?php

   
                            
  
                                                                   
  
                    
                                             
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Cti\Adapter;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Cti\CtiAdapterInterface;
use OCA\Pipelinq\Service\Cti\Result\CtiCallResult;
use OCA\Pipelinq\Service\Cti\Result\CtiWebhookResult;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

   
                        
  
                                                                             
                                                 
                                                                                   
  
                                                                 
   
class CallVoipAdapter implements CtiAdapterInterface {

	   
                                                                                          
   
                                                                          
                                                                             
                      
   
                         
    
	private array $callTimestamps = [];

	   
                                                               
   
            
    
	private const RATE_LIMIT_PER_SEC = 100;

	   
                
   
                                                
                                                             
                                              
    
	public function __construct(
		private IAppConfig $appConfig,
		private IClientService $clientService,
		private LoggerInterface $logger,
	) {
	}                   

	   
                 
   
                                           
                                                                                                                
    
	public function getPlatform(): string {
		return 'callvoip';
	}                   

	   
                 
   
                                     
                                                                  
                                                     
                                                             
                                                           
   
                                                      
   
                                                       
   
                                                                                                                        
                                                                                                                        
   
                                                                  
    
	public function handleInboundWebhook(array $payload): CtiWebhookResult {
		$eventType = (string)($payload['event'] ?? 'unknown');
		$callId = (string)($payload['callId'] ?? '');

		$direction = null;
		if (isset($payload['direction']) === true) {
			$direction = (string)$payload['direction'];
		} elseif ($eventType === 'answered' && isset($payload['extension']) === true) {
			                                                    
			$direction = 'inbound';
		}

		$recording = (array)($payload['recording'] ?? []);
		$recordingUrl = ($recording['url'] ?? null);
		$recordingExpiresAt = ($recording['expiresAt'] ?? null);

		$fromNumber = null;
		if (isset($payload['from']) === true) {
			$fromNumber = (string)$payload['from'];
		}

		$toNumber = null;
		if (isset($payload['to']) === true) {
			$toNumber = (string)$payload['to'];
		}

		$extension = null;
		if (isset($payload['extension']) === true) {
			$extension = (string)$payload['extension'];
		}

		$userId = null;
		if (isset($payload['userId']) === true) {
			$userId = (string)$payload['userId'];
		}

		$durationSeconds = null;
		if (isset($payload['duration']) === true) {
			$durationSeconds = (int)$payload['duration'];
		}

		$recordingUrlString = null;
		if ($recordingUrl !== null) {
			$recordingUrlString = (string)$recordingUrl;
		}

		$recordingExpiresStr = null;
		if ($recordingExpiresAt !== null) {
			$recordingExpiresStr = (string)$recordingExpiresAt;
		}

		$presenceState = null;
		if (isset($payload['presence']) === true) {
			$presenceState = (string)$payload['presence'];
		}

		$queueName = null;
		if (isset($payload['queue']) === true) {
			$queueName = (string)$payload['queue'];
		}

		$agentSkill = null;
		if (isset($payload['skill']) === true) {
			$agentSkill = (string)$payload['skill'];
		}

		return new CtiWebhookResult(
			eventType: $eventType,
			externalCallId: $callId,
			direction: $direction,
			fromNumber: $fromNumber,
			toNumber: $toNumber,
			extension: $extension,
			userId: $userId,
			durationSeconds: $durationSeconds,
			recordingUrl: $recordingUrlString,
			recordingExpiresAt: $recordingExpiresStr,
			presenceState: $presenceState,
			queueName: $queueName,
			agentSkill: $agentSkill,
			raw: $payload,
		);
	}                            

	   
                 
   
                                                       
                                                   
                                                     
   
                                                              
                                                                                                                
    
	public function originateCall(string $extension, string $targetNumber, string $callerId): CtiCallResult {
		if ($this->rateLimit() === false) {
			return new CtiCallResult(
				success: false,
				error: 'CallVoip rate limit exceeded (100/sec).',
				platform: $this->getPlatform(),
			);
		}

		$baseUrl = $this->appConfig->getValueString(Application::APP_ID, 'cti_callvoip_api_base_url', '');
		$apiKey = $this->appConfig->getValueString(Application::APP_ID, 'cti_callvoip_api_key', '');
		if ($baseUrl === '') {
			return new CtiCallResult(
				success: false,
				error: 'CallVoip API base URL not configured.',
				platform: $this->getPlatform(),
			);
		}

		try {
			$client = $this->clientService->newClient();
			$response = $client->post(
				rtrim($baseUrl, '/') . '/calls',
				[
					'headers' => [
						'Authorization' => 'Bearer ' . $apiKey,
						'Content-Type' => 'application/json',
					],
					'body' => json_encode(
						[
							'extension' => $extension,
							'target' => $targetNumber,
							'callerId' => $callerId,
						]
					),
					'timeout' => 10,
				]
			);

			$bodyContents = (string)$response->getBody();
			$body = json_decode($bodyContents, true);
			$callId = null;
			if (is_array($body) === true) {
				$callId = ($body['callId'] ?? null);
			}

			$externalCallId = null;
			if ($callId !== null) {
				$externalCallId = (string)$callId;
			}

			return new CtiCallResult(
				success: true,
				externalCallId: $externalCallId,
				platform: $this->getPlatform(),
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CallVoip originate failed',
				['exception' => $e->getMessage()]
			);
			return new CtiCallResult(
				success: false,
				error: 'CallVoip originate failed: ' . $e->getMessage(),
				platform: $this->getPlatform(),
			);
		}         
	}                     

	   
                 
   
                                                                                  
   
                                                
                                                     
   
                                                  
                                                                                                                
    
	public function verifyWebhookSignature(string $payload, string $signature): bool {
		$secret = $this->appConfig->getValueString(Application::APP_ID, 'cti_callvoip_webhook_secret', '');
		if ($secret === '' || $signature === '') {
			return false;
		}

		$expected = hash_hmac('sha256', $payload, $secret);
		return hash_equals($expected, $signature);
	}                              

	   
                                                                         
                                                                              
   
                                                                
    
	private function rateLimit(): bool {
		$now = microtime(true);
		$cutoff = ($now - 1.0);
		$this->callTimestamps = array_values(
			array_filter(
				$this->callTimestamps,
				static fn (float $timestamp): bool => ($timestamp >= $cutoff)
			)
		);

		if (count($this->callTimestamps) >= self::RATE_LIMIT_PER_SEC) {
			return false;
		}

		$this->callTimestamps[] = $now;
		return true;
	}                 
}           
