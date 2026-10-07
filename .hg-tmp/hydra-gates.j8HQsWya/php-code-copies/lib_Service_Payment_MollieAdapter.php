<?php

   
                          
  
                                                                              
                                                                              
                                                                             
                                                                            
  
                    
                                         
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                                                                             
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Payment;

use Throwable;

   
                          
  
                                                                                                             
                                                                                                             
   
class MollieAdapter extends AbstractPaymentAdapter {
	   
                        
   
               
    
	private const BASE_URL = 'https://api.mollie.com/v2';

	   
                                
   
                  
   
                                                                                                              
    
	public function getName(): string {
		return 'mollie';
	}               

	   
                              
   
                                                                      
                                           
                                                                            
   
                                                                                              
   
                                                                                                              
    
	public function initiate(array $transactionData, float $amount, string $paymentMethod): array {
		$apiKey = $this->credential(key: 'apiKey');
		if ($apiKey === '') {
			return $this->failedInitiate(message: 'Mollie API key not configured');
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

		$description = sprintf('Pipelinq POS %s', $label);

		$payload = [
			'amount' => [
				'currency' => 'EUR',
				'value' => $this->centsToDecimal(cents: $cents),
			],
			'description' => $description,
			'method' => $this->mapMethod(method: $paymentMethod),
			'metadata' => [
				'reference' => $reference,
				'transactionId' => (string)($transactionData['id'] ?? ''),
				'app' => 'pipelinq',
			],
		];

		$rawBody = (string)json_encode($payload);

		try {
			$response = $this->transport()->request(
				method: 'POST',
				url: self::BASE_URL . '/payments',
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
			return $this->failedInitiate(message: 'Mollie returned HTTP ' . $response['status']);
		}

		$body = $response['body'];
		$sessionId = (string)($body['id'] ?? '');
		$redirectUrl = null;
		if (isset($body['_links']['checkout']['href']) === true) {
			$redirectUrl = (string)$body['_links']['checkout']['href'];
		}

		return [
			'sessionId' => $sessionId,
			'redirectUrl' => $redirectUrl,
			'status' => 'pending',
		];
	}                

	   
                                                        
   
                                            
   
                                                                         
   
                                                                                                              
    
	public function capture(string $sessionId): array {
		                                                                   
		                                                         
		$apiKey = $this->credential(key: 'apiKey');
		if ($apiKey === '' || $sessionId === '') {
			return $this->failedCapture(sessionId: $sessionId, message: 'Mollie API key or session missing');
		}

		try {
			$response = $this->transport()->request(
				method: 'GET',
				url: self::BASE_URL . '/payments/' . urlencode($sessionId),
				headers: ['Authorization' => 'Bearer ' . $apiKey],
				body: null
			);
		} catch (Throwable $e) {
			$this->logProviderError(context: 'capture failed', e: $e);
			return $this->failedCapture(sessionId: $sessionId, message: 'Verbinding met betalingsprovider verbroken');
		}         

		if ($response['status'] < 200 || $response['status'] >= 300) {
			return $this->failedCapture(sessionId: $sessionId, message: 'Mollie returned HTTP ' . $response['status']);
		}

		$status = (string)($response['body']['status'] ?? 'pending');
		$mapped = $this->mapStatus(providerStatus: $status);

		return [
			'sessionId' => $sessionId,
			'status' => $mapped,
			'error' => null,
		];
	}               

	   
                            
   
                                            
                                     
   
                                                                                           
   
                                                                                                              
    
	public function refund(string $sessionId, string $reason): array {
		$apiKey = $this->credential(key: 'apiKey');
		if ($apiKey === '' || $sessionId === '') {
			return $this->failedRefund(sessionId: $sessionId, message: 'Mollie API key or session missing');
		}

		$description = 'Refund via Pipelinq POS';
		if ($reason !== '') {
			$description = $reason;
		}

		$payload = ['description' => $description];
		$rawBody = (string)json_encode($payload);

		try {
			$response = $this->transport()->request(
				method: 'POST',
				url: self::BASE_URL . '/payments/' . urlencode($sessionId) . '/refunds',
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
			return $this->failedRefund(sessionId: $sessionId, message: 'Mollie returned HTTP ' . $response['status']);
		}

		$refundId = (string)($response['body']['id'] ?? '');

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

		$expected = hash_hmac('sha256', $rawPayload, $secret);
		return $this->safeEquals(expected: $expected, provided: $signature);
	}                       

	   
                                                                 
   
                                                                     
   
                                                                                         
   
                                                                                                              
    
	public function parseWebhook(array $payload): array {
		$sessionId = (string)($payload['id'] ?? ($payload['paymentId'] ?? ''));
		$status = (string)($payload['status'] ?? '');
		$eventId = (string)($payload['eventId'] ?? ($payload['id'] ?? ''));

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
				url: self::BASE_URL . '/methods',
				headers: ['Authorization' => 'Bearer ' . $apiKey],
				body: null
			);
		} catch (Throwable $e) {
			$this->logProviderError(context: 'testConnection failed', e: $e);
			return [
				'status' => 'error',
				'message' => 'Verbinding met Mollie mislukt',
			];
		}         

		if ($response['status'] >= 200 && $response['status'] < 300) {
			return [
				'status' => 'ok',
				'message' => 'Verbinding met Mollie succesvol',
			];
		}

		return [
			'status' => 'error',
			'message' => 'Mollie gaf HTTP ' . $response['status'] . ' terug — controleer de API key',
		];
	}                      

	   
                                                       
   
                                              
   
                  
    
	private function mapMethod(string $method): string {
		$lower = strtolower($method);
		$map = [
			'ideal' => 'ideal',
			'bancontact' => 'bancontact',
			'card' => 'creditcard',
			'creditcard' => 'creditcard',
		];

		return ($map[$lower] ?? $lower);
	}                 

	   
                                                          
   
                                                    
   
                  
    
	private function mapStatus(string $providerStatus): string {
		$map = [
			'paid' => 'settled',
			'completed' => 'settled',
			'authorized' => 'captured',
			'pending' => 'pending',
			'open' => 'pending',
			'canceled' => 'failed',
			'cancelled' => 'failed',
			'expired' => 'failed',
			'failed' => 'failed',
			'refunded' => 'refunded',
		];

		return ($map[strtolower($providerStatus)] ?? 'pending');
	}                 
}           
