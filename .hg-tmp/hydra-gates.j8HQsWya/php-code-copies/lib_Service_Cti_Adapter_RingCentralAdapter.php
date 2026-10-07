<?php

   
                               
  
                                            
  
                    
                                             
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Cti\Adapter;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Cti\CtiAdapterInterface;
use OCA\Pipelinq\Service\Cti\PresenceSubscribingInterface;
use OCA\Pipelinq\Service\Cti\Result\CtiCallResult;
use OCA\Pipelinq\Service\Cti\Result\CtiWebhookResult;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

   
                           
  
                                                                           
                                                                             
                                                           
  
                                                                 
   
class RingCentralAdapter implements CtiAdapterInterface, PresenceSubscribingInterface {
	   
                
   
                                                
                                                             
                                              
    
	public function __construct(
		private IAppConfig $appConfig,
		private IClientService $clientService,
		private LoggerInterface $logger,
	) {
	}                   

	   
                 
   
                                           
                                                                                                                
    
	public function getPlatform(): string {
		return 'ringcentral';
	}                   

	   
                 
   
                                        
                                                                  
                                                            
                                              
                                              
                                              
                                                 
   
                                                  
   
                                                           
   
                                                                                                                        
                                                                                                                        
   
                                                                  
    
	public function handleInboundWebhook(array $payload): CtiWebhookResult {
		$body = (array)($payload['body'] ?? $payload);
		$party = ((array)($body['parties'] ?? []))[0] ?? [];
		$party = (array)$party;

		$statusCode = strtolower((string)(($party['status']['code'] ?? '')));
		$defaultEventType = 'unknown';
		if ($statusCode !== '') {
			$defaultEventType = $statusCode;
		}

		$eventType = match ($statusCode) {
			'setup', 'proceeding' => 'ringing',
			'answered' => 'answered',
			'disconnected' => 'ended',
			'voicemail' => 'abandoned',
			'parked' => 'transferred',
			default => $defaultEventType,
		};

		$directionRaw = strtolower((string)($party['direction'] ?? ''));
		$direction = match ($directionRaw) {
			'inbound' => 'inbound',
			'outbound' => 'outbound',
			default => null,
		};

		$fromNumber = null;
		if (isset($party['from']['phoneNumber']) === true) {
			$fromNumber = (string)$party['from']['phoneNumber'];
		}

		$toNumber = null;
		if (isset($party['to']['phoneNumber']) === true) {
			$toNumber = (string)$party['to']['phoneNumber'];
		}

		$extension = null;
		if (isset($party['extensionId']) === true) {
			$extension = (string)$party['extensionId'];
		}

		$userId = null;
		if (isset($party['accountId']) === true) {
			$userId = (string)$party['accountId'];
		}

		$durationSeconds = null;
		if (isset($body['duration']) === true) {
			$durationSeconds = (int)$body['duration'];
		}

		$recordingUrl = null;
		if (isset($body['recording']['contentUri']) === true) {
			$recordingUrl = (string)$body['recording']['contentUri'];
		}

		$recordingExpiresAt = null;
		if (isset($body['recording']['expirationTime']) === true) {
			$recordingExpiresAt = (string)$body['recording']['expirationTime'];
		}

		$presenceState = null;
		if (isset($body['presenceStatus']) === true) {
			$presenceState = (string)$body['presenceStatus'];
		}

		$queueName = null;
		if (isset($body['queue']) === true) {
			$queueName = (string)$body['queue'];
		}

		return new CtiWebhookResult(
			eventType: $eventType,
			externalCallId: (string)($body['telephonySessionId'] ?? ($body['sessionId'] ?? '')),
			direction: $direction,
			fromNumber: $fromNumber,
			toNumber: $toNumber,
			extension: $extension,
			userId: $userId,
			durationSeconds: $durationSeconds,
			recordingUrl: $recordingUrl,
			recordingExpiresAt: $recordingExpiresAt,
			presenceState: $presenceState,
			queueName: $queueName,
			raw: $payload,
		);
	}                            

	   
                 
   
                                                                             
   
                                                       
                                                   
                                                     
   
                                                      
   
                                                                  
    
	public function originateCall(string $extension, string $targetNumber, string $callerId): CtiCallResult {
		$baseUrl = $this->appConfig->getValueString(Application::APP_ID, 'cti_ringcentral_api_base_url', '');
		$authToken = $this->appConfig->getValueString(Application::APP_ID, 'cti_ringcentral_access_token', '');
		if ($baseUrl === '') {
			return new CtiCallResult(
				success: false,
				error: 'RingCentral API base URL not configured.',
				platform: $this->getPlatform(),
			);
		}

		try {
			$client = $this->clientService->newClient();
			$response = $client->post(
				rtrim($baseUrl, '/') . '/restapi/v1.0/account/~/extension/~/ring-out',
				[
					'headers' => [
						'Authorization' => 'Bearer ' . $authToken,
						'Content-Type' => 'application/json',
					],
					'body' => json_encode(
						[
							'from' => ['phoneNumber' => $callerId],
							'to' => ['phoneNumber' => $targetNumber],
							'caller' => ['phoneNumber' => $callerId],
							'playPrompt' => false,
						]
					),
					'timeout' => 10,
				]
			);

			$bodyContents = (string)$response->getBody();
			$body = json_decode($bodyContents, true);
			$callId = null;
			if (is_array($body) === true) {
				$callId = ($body['id'] ?? null);
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
				'RingCentral originate failed',
				['exception' => $e->getMessage()]
			);
			return new CtiCallResult(
				success: false,
				error: 'RingCentral originate failed: ' . $e->getMessage(),
				platform: $this->getPlatform(),
			);
		}         
	}                     

	   
                 
   
                                                                               
                                                
   
                                              
                                                        
   
                
                                                                                                                
    
	public function subscribeToPresence(string $userId, string $extension): void {
		$baseUrl = $this->appConfig->getValueString(Application::APP_ID, 'cti_ringcentral_api_base_url', '');
		$authToken = $this->appConfig->getValueString(Application::APP_ID, 'cti_ringcentral_access_token', '');
		if ($baseUrl === '' || $authToken === '') {
			return;
		}

		try {
			$client = $this->clientService->newClient();
			$client->post(
				rtrim($baseUrl, '/') . '/restapi/v1.0/subscription',
				[
					'headers' => [
						'Authorization' => 'Bearer ' . $authToken,
						'Content-Type' => 'application/json',
					],
					'body' => json_encode(
						[
							'eventFilters' => [
								'/restapi/v1.0/account/~/extension/' . $extension . '/presence',
							],
							'deliveryMode' => ['transportType' => 'WebHook'],
						]
					),
					'timeout' => 10,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'RingCentral presence subscribe failed',
				[
					'exception' => $e->getMessage(),
					'userId' => $userId,
				]
			);
		}         
	}                           

	   
                 
   
                                                                            
                                                              
   
                                                
                                                                      
   
                                                  
                                                                                                                
    
	public function verifyWebhookSignature(string $payload, string $signature): bool {
		$expected = $this->appConfig->getValueString(Application::APP_ID, 'cti_ringcentral_webhook_token', '');
		if ($expected === '' || $signature === '') {
			return false;
		}

		return hash_equals($expected, $signature);
	}                              
}           
