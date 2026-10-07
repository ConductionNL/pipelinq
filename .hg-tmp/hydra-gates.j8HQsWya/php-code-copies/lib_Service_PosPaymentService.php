<?php

   
                              
  
                                                                        
                                                                          
                                                                       
                                                                       
                                                                      
                                                                          
                                                        
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                                                                             
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Payment\AbstractPaymentAdapter;
use OCA\Pipelinq\Service\Payment\AdyenAdapter;
use OCA\Pipelinq\Service\Payment\BrokerHttpTransport;
use OCA\Pipelinq\Service\Payment\CcvAdapter;
use OCA\Pipelinq\Service\Payment\MollieAdapter;
use OCA\Pipelinq\Service\Payment\PaymentProviderInterface;
use OCA\Pipelinq\Service\Payment\StripeAdapter;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                     
  
                                                                              
                                                                            
                                                                           
                                                                   
                                                                             
                                                                           
                                                                            
                                                                    
                
                                                                               
                                                                 
                                                                               
                                                                          
                                                                            
                                                                             
                                                              
  
                                                                                                             
                                                                                                             
                                                                                                             
                                                                                                             
                                                                                                             
                                                                                                             
                                                                                                             
                                                                                                             
   
class PosPaymentService {
	   
                                                 
   
               
    
	public const CONFIG_PREFIX = 'payment_provider.';

	   
                                          
   
               
    
	public const EVENT_SETTLED = 'pipelinq.PosPayment.settled';

	   
                                      
   
               
    
	public const EVENT_REFUNDED = 'pipelinq.PosPayment.refunded';

	   
                                                    
   
               
    
	private const EVENT_SOURCE = '/apps/pipelinq/pos/payment';

	   
                                         
   
                           
    
	public const PROVIDERS = ['mollie', 'ccv', 'adyen', 'stripe'];

	   
                                                                              
   
                                                                                    
                                                                                   
                                                                               
                                                                                  
                                                                                   
                                                                                
                        
   
                                                                                     
                                                                                  
                                                                                  
   
                           
    
	public const SENSITIVE_FIELDS = ['webhookSecret'];

	   
                                                                            
   
                                                                                 
                                     
   
                           
    
	public const RETIRED_SECRET_FIELDS = ['apiKey', 'apiSecret'];

	   
                                                                             
   
               
    
	public const MASK = '***SET***';

	   
                
   
                                                                        
                                                   
                                                                   
                                                                
                                                                          
                                                                             
                                                                         
                                                        
                                              
    
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private ICrypto $crypto,
		private IGroupManager $groupMgr,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
	}                   

	                                                                        
	                                                           
	                                                                        

	   
                                               
   
                                            
   
                                                                                                              
    
	public function listProviders(): array {
		$providers = [];
		foreach (self::PROVIDERS as $name) {
			$providers[] = $this->getProviderConfig(name: $name, includeMaskedSecrets: true);
		}

		return $providers;
	}                     

	   
                                                      
   
                                          
                                                                                                         
   
                                
   
                                                                                                              
   
                                                                          
                                                                       
                                             
    
	public function getProviderConfig(string $name, bool $includeMaskedSecrets = true): array {
		$this->assertKnownProvider(name: $name);

		$config = [
			'name' => $name,
			'displayName' => $this->displayNameFor(name: $name),
			'type' => $this->typeFor(name: $name),
			'isActive' => $this->readBool(name: $name, key: 'isActive', default: false),
			'environment' => $this->readString(name: $name, key: 'environment', default: 'sandbox'),
			'testMode' => $this->readBool(name: $name, key: 'testMode', default: true),
			'config' => $this->readObject(name: $name, key: 'config'),
			'lastTestedAt' => $this->readString(name: $name, key: 'lastTestedAt', default: ''),
			'testResult' => $this->readObject(name: $name, key: 'testResult'),
			                                                                           
			                                                      
			'credentialId' => $this->readString(name: $name, key: 'credentialId', default: ''),
		];

		if ($includeMaskedSecrets === true) {
			foreach (self::SENSITIVE_FIELDS as $field) {
				$stored = $this->readString(name: $name, key: $field, default: '');
				$config[$field] = '';
				if ($stored !== '') {
					$config[$field] = self::MASK;
				}
			}
		}

		return $config;
	}                         

	   
                                                                       
   
                                                                        
                                                                        
                                                                       
             
   
                                          
                                                       
   
                                                            
   
                                                                                                              
                                                                                                              
    
	public function updateProvider(string $name, array $data): array {
		$this->assertKnownProvider(name: $name);

		$this->writeNonSensitiveFields(name: $name, data: $data);
		$this->writeSensitiveFields(name: $name, data: $data);

		return $this->getProviderConfig(name: $name, includeMaskedSecrets: true);
	}                      

	   
                                                                      
   
                                          
                                                       
   
                
    
	private function writeNonSensitiveFields(string $name, array $data): void {
		if (array_key_exists('isActive', $data) === true) {
			$this->writeBool(name: $name, key: 'isActive', value: (bool)$data['isActive']);
		}

		if (array_key_exists('environment', $data) === true) {
			$env = (string)$data['environment'];
			if ($env === 'live' || $env === 'sandbox') {
				$this->writeString(name: $name, key: 'environment', value: $env);
			}
		}

		if (array_key_exists('testMode', $data) === true) {
			$this->writeBool(name: $name, key: 'testMode', value: (bool)$data['testMode']);
		}

		if (array_key_exists('config', $data) === true && is_array($data['config']) === true) {
			$this->writeObject(name: $name, key: 'config', value: $data['config']);
		}

		                                                                             
		                                                                              
		                                                                       
		if (array_key_exists('credentialId', $data) === true) {
			$this->writeString(name: $name, key: 'credentialId', value: (string)$data['credentialId']);
		}
	}                               

	   
                                                                         
   
                                                                   
                                                                   
   
                                          
                                                       
   
                
    
	private function writeSensitiveFields(string $name, array $data): void {
		                                                                               
		                                                                                 
		foreach (self::RETIRED_SECRET_FIELDS as $retired) {
			if (array_key_exists($retired, $data) === true) {
				$this->logger->warning(
					'Pipelinq POS payment: a retired credential field was submitted and ignored. '
					. 'PSP keys live in the credential broker now; set `credentialId` instead.',
					[
						'provider' => $name,
						'field' => $retired,
					]
				);
			}
		}

		foreach (self::SENSITIVE_FIELDS as $field) {
			if (array_key_exists($field, $data) === false) {
				continue;
			}

			$raw = (string)$data[$field];
			if ($raw === '' || $raw === self::MASK) {
				                                                      
				continue;
			}

			$encrypted = $this->crypto->encrypt($raw);
			$this->writeString(name: $name, key: $field, value: $encrypted);
			                                               
			unset($raw);
		}
	}                            

	   
                                               
   
                                          
   
                                    
   
                                                                
                                                                                 
   
                                                                                                              
    
	public function getPaymentProvider(string $name): PaymentProviderInterface {
		$this->assertKnownProvider(name: $name);

		                                                                                 
		                                                                                  
		                                                               
		if (BrokerHttpTransport::isAvailable() === false) {
			throw new OCSNotFoundException(
				'Payment provider ' . $name . ' cannot be used: the OpenRegister credential broker is not available.'
			);
		}

		$credentialId = $this->readString(name: $name, key: 'credentialId', default: '');
		if ($credentialId === '') {
			throw new OCSNotFoundException(
				'Payment provider ' . $name . ' has no credential. Select one from the credential broker in the '
				. 'POS payment settings.'
			);
		}

		                                                                                  
		                                                                             
		$credentials = $this->resolveDecryptedCredentials(name: $name);

		$config = [
			'environment' => $this->readString(name: $name, key: 'environment', default: 'sandbox'),
			'testMode' => $this->readBool(name: $name, key: 'testMode', default: true),
		];

		$providerConfig = $this->readObject(name: $name, key: 'config');
		foreach ($providerConfig as $key => $value) {
			if (is_string($key) === true) {
				$config[$key] = $value;
			}
		}

		$adapter = $this->instantiateAdapter(name: $name, credentials: $credentials, config: $config);
		$adapter->setHttpTransport(
			new BrokerHttpTransport(
				credentialId: $credentialId,
				logger: $this->logger,
				container: $this->container,
				actingUserId: $this->currentUid()
			)
		);

		return $adapter;
	}                          

	   
                                                    
   
                                                                                  
                                                                                  
                                                                                 
                                                                    
   
                                                                  
    
	private function currentUid(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		return $user->getUID();
	}                  

	                                                                        
	                                                               
	                                                                        

	   
                                         
   
                                                    
                                                  
                                                               
   
                                                                                             
   
                                                                   
                                                                      
   
                                                                                                              
    
	public function initiatePayment(string $transactionId, string $providerName, string $paymentMethod): array {
		$transaction = $this->loadTransaction(id: $transactionId);
		$status = (string)($transaction['status'] ?? '');
		if ($status !== 'confirmed') {
			throw new OCSBadRequestException('Transaction is not confirmed');
		}

		$provider = $this->getPaymentProvider(name: $providerName);

		$amount = (float)($transaction['total'] ?? 0);
		$result = $provider->initiate(
			transactionData: $transaction,
			amount: $amount,
			paymentMethod: $paymentMethod
		);

		if ($result['status'] === 'failed') {
			                                                          
			return [
				'transaction' => $transaction,
				'payment' => $result,
			];
		}

		$updated = $this->saveTransaction(
			id: $transactionId,
			patch: [
				'paymentProvider' => $providerName,
				'paymentSessionId' => $result['sessionId'],
				'paymentStatus' => 'pending',
				'paymentMethod' => $paymentMethod,
			]
		);

		return [
			'transaction' => $updated,
			'payment' => $result,
		];
	}                       

	   
                                  
   
                                                    
   
                                
   
                                                                                                              
    
	public function capturePayment(string $transactionId): array {
		$transaction = $this->loadTransaction(id: $transactionId);
		$sessionId = (string)($transaction['paymentSessionId'] ?? '');
		$providerName = (string)($transaction['paymentProvider'] ?? '');

		if ($providerName === '' || $sessionId === '') {
			throw new OCSBadRequestException('No payment session to capture');
		}

		$provider = $this->getPaymentProvider(name: $providerName);
		$result = $provider->capture(sessionId: $sessionId);

		if ($result['status'] === 'failed') {
			return [
				'transaction' => $transaction,
				'payment' => $result,
			];
		}

		$newStatus = $result['status'];
		$updated = $this->saveTransaction(
			id: $transactionId,
			patch: ['paymentStatus' => $newStatus]
		);

		if ($newStatus === 'settled') {
			$this->emitSettledEvent(transaction: $updated);
		}

		return [
			'transaction' => $updated,
			'payment' => $result,
		];
	}                      

	   
                                                          
   
                                                    
                                            
                                             
   
                                
   
                                                                       
                                                                          
   
                                                                                                              
    
	public function refundPayment(string $transactionId, string $reason, string $userId): array {
		if ($this->isManager(userId: $userId) === false) {
			throw new OCSForbiddenException('Manager permission required');
		}

		$transaction = $this->loadTransaction(id: $transactionId);
		$paymentStatus = (string)($transaction['paymentStatus'] ?? '');
		if ($paymentStatus !== 'settled' && $paymentStatus !== 'captured') {
			throw new OCSBadRequestException('Transaction payment not settled');
		}

		$providerName = (string)($transaction['paymentProvider'] ?? '');
		$sessionId = (string)($transaction['paymentSessionId'] ?? '');
		if ($providerName === '' || $sessionId === '') {
			throw new OCSBadRequestException('No payment session to refund');
		}

		$provider = $this->getPaymentProvider(name: $providerName);
		$result = $provider->refund(sessionId: $sessionId, reason: $reason);

		if ($result['status'] === 'failed') {
			return [
				'transaction' => $transaction,
				'payment' => $result,
			];
		}

		$updated = $this->saveTransaction(
			id: $transactionId,
			patch: [
				'paymentStatus' => 'refunded',
				'refundReason' => $reason,
				'refundedAt' => $this->nowIso(),
			]
		);

		$this->emitRefundedEvent(
			transaction: $updated,
			refundId: $result['refundId'],
			reason: $reason
		);

		return [
			'transaction' => $updated,
			'payment' => $result,
		];
	}                     

	                                                                        
	                                                
	                                                                        

	   
                                        
   
                                                                        
                                                   
   
                                                                          
                                             
                                                                    
                                                                          
                                                                   
                                                                            
                                                                             
                                                                    
                                                                          
                                                                         
                                                
   
                                                  
                                                   
                                                        
   
                                                                                                           
   
                                                                                                              
    
	public function handleWebhook(string $providerName, string $rawPayload, string $signature): array {
		if (in_array(needle: $providerName, haystack: self::PROVIDERS, strict: true) === false) {
			return [
				'status' => 'invalid',
				'error' => 'Unknown provider',
			];
		}

		try {
			$provider = $this->getPaymentProvider(name: $providerName);
		} catch (Throwable $e) {
			return [
				'status' => 'invalid',
				'error' => 'Provider not configured',
			];
		}

		if ($provider->validateWebhook(rawPayload: $rawPayload, signature: $signature) === false) {
			$this->logger->warning(
				'Pipelinq POS payment: invalid webhook signature',
				['provider' => $providerName]
			);
			return [
				'status' => 'invalid',
				'error' => 'Signature mismatch',
			];
		}

		$decoded = [];
		if ($rawPayload !== '') {
			$maybe = json_decode($rawPayload, true);
			if (is_array($maybe) === true) {
				$decoded = $maybe;
			}
		}

		$envelope = $provider->parseWebhook(payload: $decoded);
		$sessionId = $envelope['sessionId'];
		$eventId = $envelope['eventId'];
		$status = $envelope['status'];

		if ($sessionId === '') {
			                                                                
			$this->logger->info(
				'Pipelinq POS payment: webhook with no sessionId',
				['provider' => $providerName]
			);
			return ['status' => 'ignored'];
		}

		return $this->handleSettlement(
			providerName: $providerName,
			sessionId: $sessionId,
			paymentStatus: $status,
			eventId: $eventId
		);
	}                     

	   
                                         
   
                                                  
                                                     
                                                                 
                                                                   
   
                                
   
                                                                                                              
                                                                                                              
    
	public function handleSettlement(string $providerName, string $sessionId, string $paymentStatus, string $eventId): array {
		$transaction = $this->findTransactionBySessionId(sessionId: $sessionId);
		if ($transaction === null) {
			                                                             
			                                                                
			                                                                 
			                                                                
			                                                             
			$this->logger->error(
				'Pipelinq POS payment: webhook for unmatched transaction; asking the provider to retry',
				[
					'provider' => $providerName,
					'session' => $sessionId,
				]
			);
			return ['status' => 'unmatched'];
		}

		                                                                   
		$lastEventId = (string)($transaction['paymentWebhookEventId'] ?? '');
		if ($eventId !== '' && $lastEventId === $eventId) {
			return [
				'status' => 'duplicate',
				'transactionId' => (string)($transaction['@self']['id'] ?? ($transaction['id'] ?? '')),
			];
		}

		$previousStatus = (string)($transaction['paymentStatus'] ?? '');
		$patch = $this->buildSettlementPatch(
			paymentStatus: $paymentStatus,
			previousStatus: $previousStatus,
			eventId: $eventId
		);

		$id = (string)($transaction['@self']['id'] ?? ($transaction['id'] ?? ''));
		$updated = $this->saveTransaction(id: $id, patch: $patch);

		$this->emitSettlementEvents(
			paymentStatus: $paymentStatus,
			previousStatus: $previousStatus,
			transaction: $updated,
			eventId: $eventId
		);

		return [
			'status' => 'ok',
			'transactionId' => $id,
		];
	}                        

	   
                                                        
   
                                                                 
                                                                              
                                                                   
   
                                
    
	private function buildSettlementPatch(string $paymentStatus, string $previousStatus, string $eventId): array {
		$patch = [
			'paymentStatus' => $paymentStatus,
			'paymentWebhookEventId' => $eventId,
		];

		if ($paymentStatus === 'settled' && $previousStatus !== 'settled') {
			$patch['settledAt'] = $this->nowIso();
		}

		if ($paymentStatus === 'refunded' && $previousStatus !== 'refunded') {
			$patch['refundedAt'] = $this->nowIso();
		}

		return $patch;
	}                            

	   
                                                                                
   
                                                                 
                                                                              
                                                                     
                                                                              
   
                
    
	private function emitSettlementEvents(string $paymentStatus, string $previousStatus, array $transaction, string $eventId): void {
		if ($paymentStatus === 'settled' && $previousStatus !== 'settled') {
			$this->emitSettledEvent(transaction: $transaction);
		}

		if ($paymentStatus === 'refunded' && $previousStatus !== 'refunded') {
			$this->emitRefundedEvent(transaction: $transaction, refundId: $eventId, reason: 'Provider-initiated refund');
		}
	}                            

	   
                                                  
   
                                          
   
                                                  
   
                                                                                                              
    
	public function testConnection(string $name): array {
		$this->assertKnownProvider(name: $name);

		try {
			$provider = $this->getPaymentProvider(name: $name);
		} catch (Throwable $e) {
			return [
				'status' => 'error',
				'message' => 'Provider niet geconfigureerd',
			];
		}

		$result = $provider->testConnection();
		$this->writeString(name: $name, key: 'lastTestedAt', value: $this->nowIso());
		$this->writeObject(name: $name, key: 'testResult', value: $result);

		return $result;
	}                      

	                                                                        
	                                     
	                                                                        

	   
                                                      
   
                                                                     
   
                
   
                                                                                                              
    
	public function emitSettledEvent(array $transaction): void {
		$payload = $this->buildSettledPayload(transaction: $transaction);
		$this->dispatch(eventName: self::EVENT_SETTLED, payload: $payload);
	}                        

	   
                                                       
   
                                                                      
                                                   
                                            
   
                
   
                                                                                                              
    
	public function emitRefundedEvent(array $transaction, string $refundId, string $reason): void {
		$payload = $this->buildRefundedPayload(transaction: $transaction, refundId: $refundId, reason: $reason);
		$this->dispatch(eventName: self::EVENT_REFUNDED, payload: $payload);
	}                         

	                                                                        
	                                 
	                                                                        

	   
                                                           
   
                                 
   
                
   
                                  
    
	private function assertKnownProvider(string $name): void {
		if (in_array(needle: $name, haystack: self::PROVIDERS, strict: true) === false) {
			throw new OCSBadRequestException('Provider ' . $name . ' is not configured');
		}
	}                           

	   
                                                    
   
                                          
                                                                        
                                                          
   
                                    
    
	private function instantiateAdapter(string $name, array $credentials, array $config): PaymentProviderInterface {
		return match ($name) {
			'mollie' => new MollieAdapter(credentials: $credentials, config: $config, logger: $this->logger),
			'ccv' => new CcvAdapter(credentials: $credentials, config: $config, logger: $this->logger),
			'adyen' => new AdyenAdapter(credentials: $credentials, config: $config, logger: $this->logger),
			'stripe' => new StripeAdapter(credentials: $credentials, config: $config, logger: $this->logger),
			default => throw new OCSBadRequestException('Provider ' . $name . ' is not configured'),
		};
	}                          

	   
                                                 
   
                                          
   
                                 
    
	private function resolveDecryptedCredentials(string $name): array {
		                                                                                
		                                                                                   
		                                                                              
		                                                                                 
		                                                          
		$out = [];
		foreach (self::RETIRED_SECRET_FIELDS as $field) {
			$out[$field] = AbstractPaymentAdapter::BROKER_MANAGED_SECRET;
		}

		foreach (self::SENSITIVE_FIELDS as $field) {
			$encrypted = $this->readString(name: $name, key: $field, default: '');
			if ($encrypted === '') {
				$out[$field] = '';
				continue;
			}

			try {
				$out[$field] = $this->crypto->decrypt($encrypted);
			} catch (Throwable $e) {
				$this->logger->warning(
					'Pipelinq POS payment: failed to decrypt credential',
					[
						'provider' => $name,
						'field' => $field,
					]
				);
				$out[$field] = '';
			}         
		}

		return $out;
	}                                   

	   
                                               
   
                                          
   
                  
    
	private function displayNameFor(string $name): string {
		$map = [
			'mollie' => 'Mollie',
			'ccv' => 'CCV',
			'adyen' => 'Adyen',
			'stripe' => 'Stripe',
		];

		return ($map[$name] ?? ucfirst($name));
	}                      

	   
                                        
   
                                          
   
                  
    
	private function typeFor(string $name): string {
		$map = [
			'mollie' => 'online',
			'stripe' => 'online',
			'ccv' => 'terminal',
			'adyen' => 'terminal',
		];

		return ($map[$name] ?? 'online');
	}               

	                                                                        
	                                    
	                                                                        

	   
                                         
   
                                          
                                      
                                       
   
                  
    
	private function readString(string $name, string $key, string $default = ''): string {
		return $this->appConfig->getValueString(
			Application::APP_ID,
			self::CONFIG_PREFIX . $name . '.' . $key,
			$default
		);
	}                  

	   
                          
   
                                          
                                      
                                     
   
                
   
                                                                                                                                             
    
	private function readBool(string $name, string $key, bool $default = false): bool {
		$raw = $this->appConfig->getValueString(
			Application::APP_ID,
			self::CONFIG_PREFIX . $name . '.' . $key,
			''
		);
		if ($raw === '') {
			return $default;
		}

		return ($raw === 'true' || $raw === '1');
	}                

	   
                                            
   
                                          
                                      
   
                                
    
	private function readObject(string $name, string $key): array {
		$raw = $this->appConfig->getValueString(
			Application::APP_ID,
			self::CONFIG_PREFIX . $name . '.' . $key,
			''
		);
		if ($raw === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}                  

	   
                                
   
                                          
                                      
                                   
   
                
    
	private function writeString(string $name, string $key, string $value): void {
		$this->appConfig->setValueString(
			Application::APP_ID,
			self::CONFIG_PREFIX . $name . '.' . $key,
			$value
		);
	}                   

	   
                                                                
   
                                          
                                      
                                 
   
                
    
	private function writeBool(string $name, string $key, bool $value): void {
		$encoded = 'false';
		if ($value === true) {
			$encoded = 'true';
		}

		$this->appConfig->setValueString(
			Application::APP_ID,
			self::CONFIG_PREFIX . $name . '.' . $key,
			$encoded
		);
	}                 

	   
                                             
   
                                          
                                      
                                                 
   
                
    
	private function writeObject(string $name, string $key, array $value): void {
		$encoded = json_encode($value);
		if (is_string($encoded) === false) {
			return;
		}

		$this->appConfig->setValueString(
			Application::APP_ID,
			self::CONFIG_PREFIX . $name . '.' . $key,
			$encoded
		);
	}                   

	                                                                        
	                                
	                                                                        

	   
                                                                           
   
                             
   
                                
   
                                
    
	private function loadTransaction(string $id): array {
		if ($id === '') {
			throw new OCSNotFoundException('Transaction not found');
		}

		$register = $this->registerId();
		$schema = $this->posTransactionSchema();
		if ($register === '' || $schema === '') {
			throw new OCSNotFoundException('Transaction not found');
		}

		                                                                         
		                                                                   
		                                                                        
		                                                                    
		if (class_exists('\OCA\OpenRegister\Service\ObjectService') === false) {
			throw new OCSNotFoundException('Transaction storage unavailable');
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			throw new OCSNotFoundException('Transaction storage unavailable');
		}

		try {
			$found = $objectService->find(
				id: $id,
				register: $register,
				schema: $schema
			);
		} catch (Throwable $e) {
			throw new OCSNotFoundException('Transaction not found');
		}

		$data = $this->toArray(object: $found);
		if ($data === null) {
			throw new OCSNotFoundException('Transaction not found');
		}

		return $data;
	}                       

	   
                                           
   
                                                                   
                                                                        
                                                                          
                                                                          
                                                                           
                                                                 
                                                                      
                                                                           
                             
   
                                                                          
                                                                    
   
                                            
   
                                     
    
	private function findTransactionBySessionId(string $sessionId): ?array {
		if ($sessionId === '') {
			return null;
		}

		$register = $this->registerId();
		$schema = $this->posTransactionSchema();
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			return null;
		}

		try {
			$result = $objectService->findAll(
				config: [
					'filters' => [
						'paymentSessionId' => $sessionId,
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 2,
				]
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Pipelinq POS payment: transaction lookup by session id failed',
				[
					'session' => $sessionId,
					'class' => get_class($e),
					'exception' => $e->getMessage(),
				]
			);
			return null;
		}         

		$rows = $this->resultToArray(result: $result);
		if ($rows === []) {
			return null;
		}

		if (count($rows) > 1) {
			                                                            
			                                                             
			                                                                
			$this->logger->error(
				'Pipelinq POS payment: paymentSessionId is not unique; settling the first match',
				[
					'session' => $sessionId,
					'matches' => array_map(
						static function (array $row): string {
							return (string)($row['@self']['id'] ?? ($row['id'] ?? ''));
						},
						$rows
					),
				]
			);
		}

		return $rows[0];
	}                                  

	   
                                       
   
                             
                                                         
   
                                
    
	private function saveTransaction(string $id, array $patch): array {
		$current = $this->loadTransaction(id: $id);
		unset($current['@self']);

		foreach ($patch as $key => $value) {
			$current[$key] = $value;
		}

		$register = $this->registerId();
		$schema = $this->posTransactionSchema();

		                                                                
		                                                                       
		                                                                        
		                                                                    
		                                                                     
		                                                                   
		                                                                     
		                                                                     
		                                                                   
		                                                                   
		                                                   
		if ($register === '' || $schema === '') {
			$this->logger->warning(
				'Pipelinq POS payment: saveTransaction refused — register/posTransaction_schema not configured',
				['transactionId' => $id]
			);
			return $current;
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$saved = $objectService->saveObject(
				object: $current,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: $id
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq POS payment: saveTransaction failed',
				['transactionId' => $id]
			);
			return $current;
		}

		return ($this->toArray(object: $saved) ?? $current);
	}                       

	   
                                           
   
                                                                       
                                                                      
                                                                           
                                                                            
                                                                           
                                                                      
   
                                                                       
    
	private function registerId(): string {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($registerId === '') {
			$this->logger->warning(
				'Pipelinq POS payment: app-config "register" is not configured; transaction storage is refused, not run unscoped'
			);
		}

		return $registerId;
	}                  

	   
                                               
   
                                                                       
                                                                    
                                                                             
                                                                          
                                                                       
                                                                      
   
                                                                     
    
	private function posTransactionSchema(): string {
		$schemaId = $this->appConfig->getValueString(Application::APP_ID, 'posTransaction_schema', '');
		if ($schemaId === '') {
			$this->logger->warning(
				'Pipelinq POS payment: app-config "posTransaction_schema" is not configured; storage is refused, not run unscoped'
			);
		}

		return $schemaId;
	}                            

	   
                                                                  
   
                                      
   
                
    
	private function isManager(string $userId): bool {
		if ($userId === '') {
			return false;
		}

		$managersGroup = $this->appConfig->getValueString(
			Application::APP_ID,
			'pos_managers_group',
			'pos_managers'
		);

		if ($this->groupMgr->isAdmin($userId) === true) {
			return true;
		}

		if ($this->groupMgr->isInGroup($userId, $managersGroup) === true) {
			return true;
		}

		return false;
	}                 

	   
                                                                     
   
                                    
   
                                            
    
	private function resultToArray(mixed $result): array {
		if (is_array($result) === true) {
			                                                                
			$hasResultsEnvelope = isset($result['results']) === true && is_array($result['results']) === true;
			$rows = $result;
			if ($hasResultsEnvelope === true) {
				$rows = $result['results'];
			}

			$out = [];
			foreach ($rows as $row) {
				$arr = $this->toArray(object: $row);
				if ($arr !== null) {
					$out[] = $arr;
				}
			}

			return $out;
		}

		return [];
	}                     

	   
                                                      
   
                                                    
   
                                     
    
	private function toArray(mixed $object): ?array {
		if ($object === null) {
			return null;
		}

		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true) {
			if (method_exists($object, 'jsonSerialize') === true) {
				$serialised = $object->jsonSerialize();
				if (is_array($serialised) === true) {
					return $serialised;
				}
			}

			if (method_exists($object, 'toArray') === true) {
				$arr = $object->toArray();
				if (is_array($arr) === true) {
					return $arr;
				}
			}

			return (array)$object;
		}

		return null;
	}               

	                                                                        
	                                           
	                                                                        

	   
                                                           
   
                                                             
   
                                
    
	private function buildSettledPayload(array $transaction): array {
		$id = (string)($transaction['@self']['id'] ?? ($transaction['id'] ?? ''));

		return [
			'specversion' => '1.0',
			'type' => self::EVENT_SETTLED,
			'source' => self::EVENT_SOURCE,
			'id' => $this->uuid(),
			'time' => $this->nowIso(),
			'datacontenttype' => 'application/json',
			'data' => [
				'transactionId' => $id,
				'reference' => (string)($transaction['reference'] ?? ''),
				'paymentProvider' => (string)($transaction['paymentProvider'] ?? ''),
				'paymentMethod' => (string)($transaction['paymentMethod'] ?? ''),
				'paymentSessionId' => (string)($transaction['paymentSessionId'] ?? ''),
				'total' => (float)($transaction['total'] ?? 0),
				'settledAt' => (string)($transaction['settledAt'] ?? $this->nowIso()),
			],
		];
	}                           

	   
                                                            
   
                                                             
                                          
                                     
   
                                
    
	private function buildRefundedPayload(array $transaction, string $refundId, string $reason): array {
		$id = (string)($transaction['@self']['id'] ?? ($transaction['id'] ?? ''));

		return [
			'specversion' => '1.0',
			'type' => self::EVENT_REFUNDED,
			'source' => self::EVENT_SOURCE,
			'id' => $this->uuid(),
			'time' => $this->nowIso(),
			'datacontenttype' => 'application/json',
			'data' => [
				'transactionId' => $id,
				'reference' => (string)($transaction['reference'] ?? ''),
				'paymentProvider' => (string)($transaction['paymentProvider'] ?? ''),
				'paymentSessionId' => (string)($transaction['paymentSessionId'] ?? ''),
				'total' => (float)($transaction['total'] ?? 0),
				'refundId' => $refundId,
				'refundReason' => $reason,
				'refundedAt' => (string)($transaction['refundedAt'] ?? $this->nowIso()),
			],
		];
	}                            

	   
                                                                        
   
                                            
                                                                 
   
                
    
	private function dispatch(string $eventName, array $payload): void {
		try {
			$webhookService = $this->container->get('OCA\\OpenRegister\\Service\\WebhookService');
			$event = new Event();
			$webhookService->dispatchEvent(
				_event: $event,
				eventName: $eventName,
				payload: $payload
			);
		} catch (Throwable $e) {
			$this->logger->info(
				'Pipelinq POS payment: CloudEvent not dispatched (no consumer or OR unavailable)',
				['event' => $eventName]
			);
		}         
	}                

	   
                                  
   
                  
    
	private function uuid(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}            

	   
                        
   
                  
    
	private function nowIso(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');
	}              
}           
