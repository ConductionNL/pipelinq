<?php

   
                          
  
                                                                        
                                                                         
                                                                       
                                                                         
                              
  
                    
                                         
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                                                                             
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Payment;

use Throwable;

   
                          
  
                                                                                                             
                                                                                                             
   
class StripeAdapter extends AbstractPaymentAdapter {
	   
                    
   
               
    
	private const BASE_URL = 'https://api.stripe.com/v1';

	   
                                                     
   
            
    
	private const REPLAY_WINDOW = 300;

	   
                                
   
                  
   
                                                                                                              
    
	public function getName(): string {
		return 'stripe';
	}               

	   
                                  
   
                                                                      
                                           
                                                   
   
                                                                                              
   
                                                                                                              
    
	public function initiate(array $transactionData, float $amount, string $paymentMethod): array {
		$apiKey = $this->credential(key: 'apiSecret');
		if ($apiKey === '') {
			$apiKey = $this->credential(key: 'apiKey');
		}

		if ($apiKey === '') {
			return $this->failedInitiate(message: 'Stripe API secret not configured');
		}

		$cents = $this->toCents(amount: $amount);
		if ($cents <= 0) {
			return $this->failedInitiate(message: 'Invalid amount');
		}

		$reference = (string)($transactionData['reference'] ?? '');

		$form = [
			'amount' => (string)$cents,
			'currency' => 'eur',
			'metadata[reference]' => $reference,
			'metadata[transactionId]' => (string)($transactionData['id'] ?? ''),
			'metadata[app]' => 'pipelinq',
		];

		$body = http_build_query($form);

		try {
			$response = $this->transport()->request(
				method: 'POST',
				url: self::BASE_URL . '/payment_intents',
				headers: [
					'Authorization' => 'Bearer ' . $apiKey,
					'Content-Type' => 'application/x-www-form-urlencoded',
				],
				body: $body
			);
		} catch (Throwable $e) {
			$this->logProviderError(context: 'initiate failed', e: $e);
			return $this->failedInitiate(message: 'Verbinding met betalingsprovider verbroken');
		}         

		if ($response['status'] < 200 || $response['status'] >= 300) {
			return $this->failedInitiate(message: 'Stripe returned HTTP ' . $response['status']);
		}

		$sessionId = (string)($response['body']['id'] ?? '');
		$clientSecret = (string)($response['body']['client_secret'] ?? '');
		$finalSession = $clientSecret;
		if ($sessionId !== '') {
			$finalSession = $sessionId;
		}

		return [
			'sessionId' => $finalSession,
			'redirectUrl' => null,
			'status' => 'pending',
		];
	}                

	   
                                   
   
                                                  
   
                                                                         
   
                                                                                                              
    
	public function capture(string $sessionId): array {
		$apiKey = $this->credential(key: 'apiSecret');
		if ($apiKey === '') {
			$apiKey = $this->credential(key: 'apiKey');
		}

		if ($apiKey === '' || $sessionId === '') {
			return $this->failedCapture(sessionId: $sessionId, message: 'Stripe API key or session missing');
		}

		try {
			$response = $this->transport()->request(
				method: 'POST',
				url: self::BASE_URL . '/payment_intents/' . urlencode($sessionId) . '/capture',
				headers: [
					'Authorization' => 'Bearer ' . $apiKey,
					'Content-Type' => 'application/x-www-form-urlencoded',
				],
				body: ''
			);
		} catch (Throwable $e) {
			$this->logProviderError(context: 'capture failed', e: $e);
			return $this->failedCapture(sessionId: $sessionId, message: 'Verbinding met betalingsprovider verbroken');
		}         

		if ($response['status'] < 200 || $response['status'] >= 300) {
			return $this->failedCapture(sessionId: $sessionId, message: 'Stripe returned HTTP ' . $response['status']);
		}

		$status = (string)($response['body']['status'] ?? 'pending');

		return [
			'sessionId' => $sessionId,
			'status' => $this->mapStatus(providerStatus: $status),
			'error' => null,
		];
	}               

	   
                                  
   
                                                  
                                     
   
                                                                                           
   
                                                                                                              
    
	public function refund(string $sessionId, string $reason): array {
		$apiKey = $this->credential(key: 'apiSecret');
		if ($apiKey === '') {
			$apiKey = $this->credential(key: 'apiKey');
		}

		if ($apiKey === '' || $sessionId === '') {
			return $this->failedRefund(sessionId: $sessionId, message: 'Stripe API key or session missing');
		}

		$refundReason = 'Refund via Pipelinq POS';
		if ($reason !== '') {
			$refundReason = $reason;
		}

		$form = [
			'payment_intent' => $sessionId,
			'reason' => 'requested_by_customer',
			'metadata[reason]' => $refundReason,
			'metadata[app]' => 'pipelinq',
		];

		$body = http_build_query($form);

		try {
			$response = $this->transport()->request(
				method: 'POST',
				url: self::BASE_URL . '/refunds',
				headers: [
					'Authorization' => 'Bearer ' . $apiKey,
					'Content-Type' => 'application/x-www-form-urlencoded',
				],
				body: $body
			);
		} catch (Throwable $e) {
			$this->logProviderError(context: 'refund failed', e: $e);
			return $this->failedRefund(sessionId: $sessionId, message: 'Verbinding met betalingsprovider verbroken');
		}         

		if ($response['status'] < 200 || $response['status'] >= 300) {
			return $this->failedRefund(sessionId: $sessionId, message: 'Stripe returned HTTP ' . $response['status']);
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

		$parsed = $this->parseStripeSignature(signature: $signature);
		$timestamp = (int)($parsed['t'] ?? 0);
		$provided = (string)($parsed['v1'] ?? '');

		if ($timestamp === 0 || $provided === '') {
			return false;
		}

		                                                              
		$now = time();
		if (($now - $timestamp) > self::REPLAY_WINDOW) {
			return false;
		}

		$signedPayload = $timestamp . '.' . $rawPayload;
		$expected = hash_hmac('sha256', $signedPayload, $secret);

		return $this->safeEquals(expected: $expected, provided: $provided);
	}                       

	   
                                    
   
                                                             
   
                                                                                         
   
                                                                                                              
    
	public function parseWebhook(array $payload): array {
		$eventId = (string)($payload['id'] ?? '');
		$eventType = (string)($payload['type'] ?? '');
		$object = ($payload['data']['object'] ?? []);
		$sessionId = (string)($object['payment_intent'] ?? ($object['id'] ?? ''));

		return [
			'sessionId' => $sessionId,
			'status' => $this->mapEventType(eventType: $eventType),
			'eventId' => $eventId,
			'error' => null,
		];
	}                    

	   
                                                      
   
                                                  
   
                                                                                                              
    
	public function testConnection(): array {
		$apiKey = $this->credential(key: 'apiSecret');
		if ($apiKey === '') {
			$apiKey = $this->credential(key: 'apiKey');
		}

		if ($apiKey === '') {
			return [
				'status' => 'error',
				'message' => 'API secret niet ingesteld',
			];
		}

		try {
			$response = $this->transport()->request(
				method: 'GET',
				url: self::BASE_URL . '/balance',
				headers: ['Authorization' => 'Bearer ' . $apiKey],
				body: null
			);
		} catch (Throwable $e) {
			$this->logProviderError(context: 'testConnection failed', e: $e);
			return [
				'status' => 'error',
				'message' => 'Verbinding met Stripe mislukt',
			];
		}         

		if ($response['status'] >= 200 && $response['status'] < 300) {
			return [
				'status' => 'ok',
				'message' => 'Verbinding met Stripe succesvol',
			];
		}

		return [
			'status' => 'error',
			'message' => 'Stripe gaf HTTP ' . $response['status'] . ' terug',
		];
	}                      

	   
                                                          
   
                                              
   
                                 
    
	private function parseStripeSignature(string $signature): array {
		$out = [];
		foreach (explode(',', $signature) as $segment) {
			$pair = explode('=', trim($segment), 2);
			if (count($pair) === 2) {
				$out[$pair[0]] = $pair[1];
			}
		}

		return $out;
	}                            

	   
                                                                
   
                                                    
   
                  
    
	private function mapStatus(string $providerStatus): string {
		$map = [
			'succeeded' => 'settled',
			'requires_capture' => 'captured',
			'requires_confirmation' => 'pending',
			'requires_action' => 'pending',
			'processing' => 'pending',
			'requires_payment_method' => 'failed',
			'canceled' => 'failed',
		];

		return ($map[strtolower($providerStatus)] ?? 'pending');
	}                 

	   
                                                              
   
                                            
   
                  
    
	private function mapEventType(string $eventType): string {
		$map = [
			'payment_intent.succeeded' => 'settled',
			'payment_intent.amount_capturable_updated' => 'captured',
			'payment_intent.payment_failed' => 'failed',
			'payment_intent.canceled' => 'failed',
			'charge.refunded' => 'refunded',
			'refund.created' => 'refunded',
		];

		return ($map[strtolower($eventType)] ?? 'pending');
	}                    
}           
