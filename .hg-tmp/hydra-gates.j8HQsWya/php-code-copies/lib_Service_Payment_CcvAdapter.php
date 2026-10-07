<?php

   
                       
  
                                                                             
                                                                           
                                                                             
                                                                 
  
                    
                                         
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                                                                             
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Payment;

use Throwable;

   
                            
  
                                                                                                             
                                                                                                             
   
class CcvAdapter extends AbstractPaymentAdapter {
	   
                                 
   
               
    
	private const SANDBOX_URL = 'https://api.psp.sandbox.ccv.eu/api/v1';

	   
                              
   
               
    
	private const LIVE_URL = 'https://api.psp.ccv.eu/api/v1';

	   
                                
   
                  
   
                                                                                                              
    
	public function getName(): string {
		return 'ccv';
	}               

	   
                                        
   
                                                                      
                                           
                                                   
   
                                                                                              
   
                                                                                                              
    
	public function initiate(array $transactionData, float $amount, string $paymentMethod): array {
		$apiKey = $this->credential(key: 'apiKey');
		$terminalId = $this->configValue(key: 'terminalId');

		if ($apiKey === '' || $terminalId === '') {
			return $this->failedInitiate(message: 'CCV API key or terminal id not configured');
		}

		$cents = $this->toCents(amount: $amount);
		if ($cents <= 0) {
			return $this->failedInitiate(message: 'Invalid amount');
		}

		$reference = (string)($transactionData['reference'] ?? '');
		$label = 'transactie';
		if ($reference !== '') {
			$label = $reference;
		}

		$payload = [
			'amount' => $cents,
			'currency' => 'EUR',
			'method' => 'card',
			'terminalId' => $terminalId,
			'reference' => $reference,
			'description' => sprintf('Pipelinq POS %s', $label),
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
					'Authorization' => 'Bearer ' . $apiKey,
					'Content-Type' => 'application/json',
				],
				body: $rawBody
			);
		} catch (Throwable $e) {
			$this->logProviderError(context: 'initiate failed', e: $e);
			return $this->failedInitiate(message: 'Verbinding met betalingsprovider verbroken');
		}         

		if ($response['status'] < 200 || $response['status'] >= 300) {
			return $this->failedInitiate(message: 'CCV returned HTTP ' . $response['status']);
		}

		$sessionId = (string)($response['body']['reference'] ?? ($response['body']['id'] ?? ''));

		return [
			'sessionId' => $sessionId,
			'redirectUrl' => null,
			'status' => 'pending',
		];
	}                

	   
                                                             
   
                                            
   
                                                                         
   
                                                                                                              
    
	public function capture(string $sessionId): array {
		$apiKey = $this->credential(key: 'apiKey');
		if ($apiKey === '' || $sessionId === '') {
			return $this->failedCapture(sessionId: $sessionId, message: 'CCV API key or session missing');
		}

		try {
			$response = $this->transport()->request(
				method: 'GET',
				url: $this->baseUrl() . '/payments/' . urlencode($sessionId),
				headers: ['Authorization' => 'Bearer ' . $apiKey],
				body: null
			);
		} catch (Throwable $e) {
			$this->logProviderError(context: 'capture failed', e: $e);
			return $this->failedCapture(sessionId: $sessionId, message: 'Verbinding met betalingsprovider verbroken');
		}         

		if ($response['status'] < 200 || $response['status'] >= 300) {
			return $this->failedCapture(sessionId: $sessionId, message: 'CCV returned HTTP ' . $response['status']);
		}

		$status = (string)($response['body']['status'] ?? 'pending');

		return [
			'sessionId' => $sessionId,
			'status' => $this->mapStatus(providerStatus: $status),
			'error' => null,
		];
	}               

	   
                         
   
                                            
                                     
   
                                                                                           
   
                                                                                                              
    
	public function refund(string $sessionId, string $reason): array {
		$apiKey = $this->credential(key: 'apiKey');
		if ($apiKey === '' || $sessionId === '') {
			return $this->failedRefund(sessionId: $sessionId, message: 'CCV API key or session missing');
		}

		$refundReason = 'Refund via Pipelinq POS';
		if ($reason !== '') {
			$refundReason = $reason;
		}

		$payload = ['reason' => $refundReason];
		$rawBody = (string)json_encode($payload);

		try {
			$response = $this->transport()->request(
				method: 'POST',
				url: $this->baseUrl() . '/payments/' . urlencode($sessionId) . '/refunds',
				headers: [
					'Authorization' => 'Bearer ' . $apiKey,
					'Content-Type' => 'application/json',
				],
				body: $rawBody
			);
		} catch (Throwable $e) {
			$this->logProviderError(context: 'refund failed', e: $e);
			return $this->failedRefund(sessionId: $sessionId, message: 'Verbinding met betalingsprovider verbroken');
		}         

		if ($response['status'] < 200 || $response['status'] >= 300) {
			return $this->failedRefund(sessionId: $sessionId, message: 'CCV returned HTTP ' . $response['status']);
		}

		$refundId = (string)($response['body']['reference'] ?? ($response['body']['id'] ?? ''));

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

		$merchantId = $this->configValue(key: 'merchantId', default: '');
		$signed = $merchantId . $rawPayload;
		$expected = hash_hmac('sha512', $signed, $secret);

		return $this->safeEquals(expected: $expected, provided: $signature);
	}                       

	   
                        
   
                                                             
   
                                                                                         
   
                                                                                                              
    
	public function parseWebhook(array $payload): array {
		$sessionId = (string)($payload['reference'] ?? ($payload['paymentId'] ?? ''));
		$status = (string)($payload['status'] ?? '');
		$eventId = (string)($payload['eventId'] ?? ($payload['reference'] ?? ''));

		return [
			'sessionId' => $sessionId,
			'status' => $this->mapStatus(providerStatus: $status),
			'eventId' => $eventId,
			'error' => null,
		];
	}                    

	   
                        
   
                                                  
   
                                                                                                              
    
	public function testConnection(): array {
		$apiKey = $this->credential(key: 'apiKey');
		if ($apiKey === '') {
			return [
				'status' => 'error',
				'message' => 'API key niet ingesteld',
			];
		}

		try {
			$response = $this->transport()->request(
				method: 'GET',
				url: $this->baseUrl() . '/terminals',
				headers: ['Authorization' => 'Bearer ' . $apiKey],
				body: null
			);
		} catch (Throwable $e) {
			$this->logProviderError(context: 'testConnection failed', e: $e);
			return [
				'status' => 'error',
				'message' => 'Verbinding met CCV mislukt',
			];
		}         

		if ($response['status'] >= 200 && $response['status'] < 300) {
			return [
				'status' => 'ok',
				'message' => 'Verbinding met CCV succesvol',
			];
		}

		if ($response['status'] === 401 || $response['status'] === 403) {
			return [
				'status' => 'error',
				'message' => 'Fout: Invalid API credentials. Controleer uw API key.',
			];
		}

		return [
			'status' => 'error',
			'message' => 'CCV gaf HTTP ' . $response['status'] . ' terug',
		];
	}                      

	   
                                              
   
                  
    
	private function baseUrl(): string {
		if ($this->isSandbox() === true) {
			return self::SANDBOX_URL;
		}

		return self::LIVE_URL;
	}               

	   
                                                 
   
                                                 
   
                  
    
	private function mapStatus(string $providerStatus): string {
		$map = [
			'success' => 'settled',
			'manualintervention' => 'pending',
			'paid' => 'settled',
			'captured' => 'captured',
			'authorised' => 'captured',
			'authorized' => 'captured',
			'pending' => 'pending',
			'open' => 'pending',
			'failed' => 'failed',
			'cancelled' => 'failed',
			'expired' => 'failed',
			'refunded' => 'refunded',
		];

		return ($map[strtolower($providerStatus)] ?? 'pending');
	}                 
}           
