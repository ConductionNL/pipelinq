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

   
                        
  
                                                                           
                                                                               
                                         
  
                                                                 
   
class AsteriskAdapter implements CtiAdapterInterface {
	   
                
   
                                                
                                                             
                                              
    
	public function __construct(
		private IAppConfig $appConfig,
		private IClientService $clientService,
		private LoggerInterface $logger,
	) {
	}                   

	   
                 
   
                                       
                                                                                                                
    
	public function getPlatform(): string {
		return 'asterisk';
	}                   

	   
                 
   
                                              
                                                                      
                                                         
                                                        
                                
   
                                                                 
   
                                              
   
                                                                                                                        
                                                                                                                        
   
                                                                  
    
	public function handleInboundWebhook(array $payload): CtiWebhookResult {
		$event = strtolower((string)($payload['Event'] ?? ($payload['event'] ?? 'unknown')));
		$eventType = match ($event) {
			'newchannel', 'dialbegin' => 'ringing',
			'bridgeenter', 'answer' => 'answered',
			'hangup', 'dialend' => 'ended',
			'channelhangup' => 'ended',
			'devicestatechange' => 'presence',
			default => $event,
		};

		$extension = null;
		$channel = (string)($payload['Channel'] ?? ($payload['channel'] ?? ''));
		if ($channel !== '' && preg_match('#/(\d+)#', $channel, $matches) === 1) {
			$extension = $matches[1];
		}

		$duration = null;
		$raw = ($payload['Duration'] ?? ($payload['duration'] ?? null));
		if (is_string($raw) === true && preg_match('/(\d+):(\d+):(\d+)/', $raw, $matches) === 1) {
			$duration = (((int)$matches[1]) * 3600) + (((int)$matches[2]) * 60) + ((int)$matches[3]);
		} elseif (is_numeric($raw) === true) {
			$duration = (int)$raw;
		}

		$presenceMap = [
			'NOT_INUSE' => 'available',
			'INUSE' => 'on-call',
			'BUSY' => 'on-call',
			'RINGING' => 'on-call',
			'ONHOLD' => 'wrap-up',
			'UNAVAILABLE' => 'offline',
		];
		$presenceRaw = (string)($payload['State'] ?? ($payload['DeviceState'] ?? ''));
		$presence = ($presenceMap[$presenceRaw] ?? null);

		$direction = null;
		if (isset($payload['direction']) === true) {
			$direction = (string)$payload['direction'];
		}

		$fromNumber = null;
		if (isset($payload['CallerIDNum']) === true) {
			$fromNumber = (string)$payload['CallerIDNum'];
		}

		$toNumber = null;
		if (isset($payload['ConnectedLineNum']) === true) {
			$toNumber = (string)$payload['ConnectedLineNum'];
		}

		$userId = null;
		if (isset($payload['userId']) === true) {
			$userId = (string)$payload['userId'];
		}

		$recordingUrl = null;
		if (isset($payload['RecordingURL']) === true) {
			$recordingUrl = (string)$payload['RecordingURL'];
		}

		$recordingExpiresAt = null;
		if (isset($payload['RecordingExpiresAt']) === true) {
			$recordingExpiresAt = (string)$payload['RecordingExpiresAt'];
		}

		$queueName = null;
		if (isset($payload['Queue']) === true) {
			$queueName = (string)$payload['Queue'];
		}

		return new CtiWebhookResult(
			eventType: $eventType,
			externalCallId: (string)($payload['Uniqueid'] ?? ($payload['uniqueid'] ?? '')),
			direction: $direction,
			fromNumber: $fromNumber,
			toNumber: $toNumber,
			extension: $extension,
			userId: $userId,
			durationSeconds: $duration,
			recordingUrl: $recordingUrl,
			recordingExpiresAt: $recordingExpiresAt,
			presenceState: $presence,
			queueName: $queueName,
			raw: $payload,
		);
	}                            

	   
                 
   
                                                                        
   
                                                                              
                                                                           
                                                                      
   
                                                                     
                                                                                                                
    
	public function originateCall(string $extension, string $targetNumber, string $callerId): CtiCallResult {
		$baseUrl = $this->appConfig->getValueString(Application::APP_ID, 'cti_asterisk_api_base_url', '');
		$user = $this->appConfig->getValueString(Application::APP_ID, 'cti_asterisk_ari_user', '');
		$pass = $this->appConfig->getValueString(Application::APP_ID, 'cti_asterisk_ari_pass', '');
		$context = $this->appConfig->getValueString(Application::APP_ID, 'cti_asterisk_context', 'from-internal');
		if ($baseUrl === '') {
			return new CtiCallResult(
				success: false,
				error: 'Asterisk API base URL not configured.',
				platform: $this->getPlatform(),
			);
		}

		$auth = null;
		if ($user !== '') {
			$auth = [$user, $pass];
		}

		try {
			$client = $this->clientService->newClient();
			$response = $client->post(
				rtrim($baseUrl, '/') . '/ari/channels',
				[
					'auth' => $auth,
					'headers' => ['Content-Type' => 'application/json'],
					'body' => json_encode(
						[
							'endpoint' => 'SIP/' . $extension,
							'extension' => $targetNumber,
							'context' => $context,
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
				$callId = ($body['id'] ?? ($body['uniqueid'] ?? null));
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
				'Asterisk originate failed',
				['exception' => $e->getMessage()]
			);
			return new CtiCallResult(
				success: false,
				error: 'Asterisk originate failed: ' . $e->getMessage(),
				platform: $this->getPlatform(),
			);
		}         
	}                     

	   
                 
   
                                                                            
   
                                                        
                                                         
   
                                                  
                                                                                                                
    
	public function verifyWebhookSignature(string $payload, string $signature): bool {
		$expected = $this->appConfig->getValueString(Application::APP_ID, 'cti_asterisk_webhook_secret', '');
		if ($expected === '' || $signature === '') {
			return false;
		}

		return hash_equals($expected, $signature);
	}                              
}           
