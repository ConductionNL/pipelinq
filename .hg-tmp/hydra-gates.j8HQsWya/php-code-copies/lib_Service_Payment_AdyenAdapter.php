<?php

   
                         
  
                                                                    
                                                                            
                                                                      
                                                           
  
                    
                                         
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                                                                             
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Payment;

use Throwable;

   
                         
  
                                                                                                             
   
class AdyenAdapter extends AbstractPaymentAdapter {
	   
                                   
   
               
    
	private const LIVE_URL = 'https://checkout-live.adyen.com/v71';

	   
                                      
   
               
    
	private const SANDBOX_URL = 'https://checkout-test.adyen.com/v71';

	   
                                
   
                  
   
                                                                                                              
    
	public function getName(): string {
		return 'adyen';
	}               

	   
                              
   
                                                                      
                                           
                                                   
   
                                                                                              
   
                                                                                                              
    
	public function initiate(array $transactionData, float $amount, string $paymentMethod): array {
		$apiKey = $this->credential(key: 'apiKey');
		$merchantAccount = $this->configValue(key: 'merchantAccount', default: '');

		if ($apiKey === '' || $merchantAccount === '') {
			return $this->failedInitiate(message: 'Adyen API key or merchantAccount not configured');
		}

		$cents = $this->toCents(amount: $amount);
		if ($cents <= 0) {
			return $this->failedInitiate(message: 'Invalid amount');
		}

		$reference = (string)($transactionData['reference'] ?? ('PIPELINQ-' . uniqid()));

		$payload = [
			'amount' => [
				'value' => $cents,
				'currency' => 'EUR',
			],
			'reference' => $reference,
			'merchantAccount' => $merchantAccount,
			'paymentMethod' => ['type' => $this->mapMethod(method: $paymentMethod)],
			'metadata' => [
				'transactionId' => (string)($transactionData['id'] ?? ''),
				'app' => 'pipelinq',
			],
		];

		$rawBody = (string)json_encode($payload);

		try {
			$response = $this->transport()->request(
				method: 'POST',
				url: $this->baseUrl() . '/payments',
				headers: [
					'X-API-Key' => $apiKey,
					'Content-Type' => 'application/json',
				],
				body: $rawBody
			);
		} catch (Throwable $e) {
			$this->logProviderError(context: 'initiate failed', e: $e);
			return $this->failedInitiate(message: 'Verbinding met betalingsprovider verbroken');
		}         

		if ($response['status'] < 200 || $response['status'] >= 300) {
			return $this->failedInitiate(message: 'Adyen returned HTTP ' . $response['status']);
		}

		$body = $response['body'];
		$sessionId = (string)($body['pspReference'] ?? '');

		return [
			'sessionId' => $sessionId,
			'redirectUrl' => null,
			'status' => 'pending',
		];
	}                

	   
                                                                 
   
                                            
   
                                                                         
   
                                                                                                              
    
	public function capture(string $sessionId): array {
		$apiKey = $this->credential(key: 'apiKey');
		$merchantAccount = $this->configValue(key: 'merchantAccount', default: '');
		if ($apiKey === '' || $sessionId === '' || $merchantAccount === '') {
			return $this->failedCapture(sessionId: $sessionId, message: 'Adyen API key or session missing');
		}

		$payload = [
			'merchantAccount' => $merchantAccount,
		];
		$rawBody = (string)json_encode($payload);

		try {
			$response = $this->transport()->request(
				method: 'POST',
				url: $this->baseUrl() . '/payments/' . urlencode($sessionId) . '/captures',
				headers: [
					'X-API-Key' => $apiKey,
					'Content-Type' => 'application/json',
				],
				body: $rawBody
			);
		} catch (Throwable $e) {
			$this->logProviderError(context: 'capture failed', e: $e);
			return $this->failedCapture(sessionId: $sessionId, message: 'Verbinding met betalingsprovider verbroken');
		}         

		if ($response['status'] < 200 || $response['status'] >= 300) {
			return $this->failedCapture(sessionId: $sessionId, message: 'Adyen returned HTTP ' . $response['status']);
		}

		return [
			'sessionId' => $sessionId,
			'status' => 'captured',
			'error' => null,
		];
	}               

	   
                            
   
                                                           
                                     
   
                                                                                           
   
                                                                                                              
    
	public function refund(string $sessionId, string $reason): array {
		$apiKey = $this->credential(key: 'apiKey');
		$merchantAccount = $this->configValue(key: 'merchantAccount', default: '');
		if ($apiKey === '' || $sessionId === '' || $merchantAccount === '') {
			return $this->failedRefund(sessionId: $sessionId, message: 'Adyen API key or session missing');
		}

		$refundReason = 'Refund via Pipelinq POS';
		if ($reason !== '') {
			$refundReason = $reason;
		}

		$payload = [
			'merchantAccount' => $merchantAccount,
			'merchantRefundReason' => $refundReason,
		];
		$rawBody = (string)json_encode($payload);

		try {
			$response = $this->transport()->request(
				method: 'POST',
				url: $this->baseUrl() . '/payments/' . urlencode($sessionId) . '/refunds',
				headers: [
					'X-API-Key' => $apiKey,
					'Content-Type' => 'application/json',
				],
				body: $rawBody
			);
		} catch (Throwable $e) {
			$this->logProviderError(context: 'refund failed', e: $e);
			return $this->failedRefund(sessionId: $sessionId, message: 'Verbinding met betalingsprovider verbroken');
		}         

		if ($response['status'] < 200 || $response['status'] >= 300) {
			return $this->failedRefund(sessionId: $sessionId, message: 'Adyen returned HTTP ' . $response['status']);
		}

		$refundId = (string)($response['body']['pspReference'] ?? '');

		return [
			'sessionId' => $sessionId,
			'refundId' => $refundId,
			'status' => 'refunded',
			'error' => null,
		];
	}              

	   
                                                                       
   
                                           
                                                                                           
   
                
   
                                                                                                              
    
	public function validateWebhook(string $rawPayload, string $signature): bool {
		if ($rawPayload === '' || $signature === '') {
			return false;
		}

		$secret = $this->credential(key: 'webhookSecret');
		if ($secret === '') {
			return false;
		}

		                                                                    
		                                                                      
		                                                                    
		                                                                  
		                       
		$looksHex = (strlen($secret) % 2 === 0 && ctype_xdigit($secret) === true);
		$rawKey = false;
		if ($looksHex === true) {
			$rawKey = hex2bin($secret);
		}

		if ($rawKey === false || $rawKey === '') {
			$rawKey = $secret;
		}

		$expected = base64_encode(hash_hmac('sha256', $rawPayload, $rawKey, true));
		return $this->safeEquals(expected: $expected, provided: $signature);
	}                       

	   
                           
   
                                                             
   
                                                                                         
   
                                                                                                              
    
	public function parseWebhook(array $payload): array {
		$item = $payload;
		if (isset($payload['notificationItems'][0]['NotificationRequestItem']) === true) {
			$item = $payload['notificationItems'][0]['NotificationRequestItem'];
		}

		$sessionId = (string)($item['pspReference'] ?? '');
		$eventCode = (string)($item['eventCode'] ?? '');
		$success = ((string)($item['success'] ?? 'false') === 'true');
		$eventId = (string)($item['eventId'] ?? ($item['pspReference'] ?? ''));

		return [
			'sessionId' => $sessionId,
			'status' => $this->mapEvent(eventCode: $eventCode, success: $success),
			'eventId' => $eventId,
			'error' => null,
		];
	}                    

	   
                                                     
   
                                                  
   
                                                                                                              
    
	public function testConnection(): array {
		$apiKey = $this->credential(key: 'apiKey');
		$merchantAccount = $this->configValue(key: 'merchantAccount', default: '');

		if ($apiKey === '' || $merchantAccount === '') {
			return [
				'status' => 'error',
				'message' => 'API key of merchantAccount niet ingesteld',
			];
		}

		$payload = [
			'merchantAccount' => $merchantAccount,
			'countryCode' => 'NL',
			'amount' => [
				'value' => 100,
				'currency' => 'EUR',
			],
		];
		$rawBody = (string)json_encode($payload);

		try {
			$response = $this->transport()->request(
				method: 'POST',
				url: $this->baseUrl() . '/paymentMethods',
				headers: [
					'X-API-Key' => $apiKey,
					'Content-Type' => 'application/json',
				],
				body: $rawBody
			);
		} catch (Throwable $e) {
			$this->logProviderError(context: 'testConnection failed', e: $e);
			return [
				'status' => 'error',
				'message' => 'Verbinding met Adyen mislukt',
			];
		}         

		if ($response['status'] >= 200 && $response['status'] < 300) {
			return [
				'status' => 'ok',
				'message' => 'Verbinding met Adyen succesvol',
			];
		}

		return [
			'status' => 'error',
			'message' => 'Adyen gaf HTTP ' . $response['status'] . ' terug',
		];
	}                      

	   
                                              
   
                  
    
	private function baseUrl(): string {
		if ($this->isSandbox() === true) {
			return self::SANDBOX_URL;
		}

		return self::LIVE_URL;
	}               

	   
                                                                
   
                                     
   
                  
    
	private function mapMethod(string $method): string {
		$lower = strtolower($method);
		$map = [
			'card' => 'scheme',
			'creditcard' => 'scheme',
			'ideal' => 'ideal',
			'bancontact' => 'bcmc',
		];

		return ($map[$lower] ?? 'scheme');
	}                 

	   
                                                                    
   
                                                                                 
                                          
   
                  
    
	private function mapEvent(string $eventCode, bool $success): string {
		if ($success === false) {
			return 'failed';
		}

		$map = [
			'AUTHORISATION' => 'captured',
			'CAPTURE' => 'settled',
			'REFUND' => 'refunded',
			'CANCELLATION' => 'failed',
			'CANCEL_OR_REFUND' => 'refunded',
		];

		return ($map[strtoupper($eventCode)] ?? 'pending');
	}                
}           
