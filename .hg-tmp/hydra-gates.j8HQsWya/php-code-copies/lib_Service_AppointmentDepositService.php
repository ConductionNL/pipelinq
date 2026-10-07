<?php

   
                                      
  
                                                                     
                                                                    
                                                                      
                                                                     
                                                                  
                                                             
                                                                     
             
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                   
                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                      
  
                                                                   
                                                                   
                                                                      
                                                                    
                                                                      
                                                                       
  
                                                                        
                                                 
  
                                                  
                                                    
  
                                                   
   
class AppointmentDepositService {
	   
                                                             
   
                                                                 
                                                           
                            
   
               
    
	public const PAYMENT_SOURCE_KEY = 'appointment_payment_source';

	   
                                                               
   
                                                                
                                                              
   
               
    
	public const WEBHOOK_SECRET_KEY = 'appointment_payment_webhook_secret';

	   
                                                                   
   
            
    
	public const DEPOSIT_TIMEOUT_SECONDS = 900;

	   
                                                                    
                                                                    
                                                             
   
               
    
	public const SESSION_STATUS_UNAVAILABLE = 'unavailable';

	   
                                                           
   
               
    
	public const SESSION_STATUS_PENDING = 'pending';

	   
                
   
                                                                                 
                                                   
                                                                              
                                                                                  
                                              
   
                                                    
    
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private IURLGenerator $urlGenerator,
		private BookingService $bookingService,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                           
   
                                                                    
                                                                    
                                                             
                                                                    
                                                 
   
                                          
                                                            
                                                                 
                                                                         
                                                                    
   
                                                                                
   
                                                                                     
   
                                                    
    
	public function createDepositSession(
		string $bookingId,
		int $amountCents,
		string $currency = 'EUR',
		string $description = '',
		string $returnUrl = '',
	): array {
		if ($bookingId === '') {
			throw new InvalidArgumentException('bookingId must be a non-empty string');
		}

		if ($amountCents <= 0) {
			throw new InvalidArgumentException('amountCents must be positive');
		}

		$payment = $this->resolvePaymentService();
		if ($payment === null) {
			$this->logger->info(
				'Pipelinq: openconnector PaymentService unavailable — deposit session skipped',
				['booking' => $bookingId]
			);
			return [
				'sessionUrl' => '',
				'status' => self::SESSION_STATUS_UNAVAILABLE,
				'providerReference' => '',
			];
		}

		$sourceSlug = $this->paymentSourceSlug();
		if ($sourceSlug === '') {
			$this->logger->warning(
				'Pipelinq: appointment_payment_source not configured — deposit session skipped',
				['booking' => $bookingId]
			);
			return [
				'sessionUrl' => '',
				'status' => self::SESSION_STATUS_UNAVAILABLE,
				'providerReference' => '',
			];
		}

		$callbackUrl = $this->callbackUrl();

		try {
			                                                       
			$result = $payment->createSession(
				$sourceSlug,
				[
					'amount' => [
						'value' => $this->centsToDecimal(cents: $amountCents),
						'currency' => strtoupper($currency),
					],
					'description' => $this->truncate(value: $description, max: 255),
					'returnUrl' => $returnUrl,
					'webhookUrl' => $callbackUrl,
					'metadata' => [
						'app' => Application::APP_ID,
						'kind' => 'appointment-deposit',
						'bookingId' => $bookingId,
					],
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: openconnector createSession failed',
				[
					'booking' => $bookingId,
					'amount' => $amountCents,
				]
			);
			return [
				'sessionUrl' => '',
				'status' => self::SESSION_STATUS_UNAVAILABLE,
				'providerReference' => '',
			];
		}         

		$sessionUrl = $this->stringField(result: $result, key: 'sessionUrl');
		$providerReference = $this->stringField(result: $result, key: 'id');

		return [
			'sessionUrl' => $sessionUrl,
			'status' => self::SESSION_STATUS_PENDING,
			'providerReference' => $providerReference,
		];
	}                            

	   
                                                           
   
                                                                   
                                                                     
                                                                   
                                                                    
                                                                 
                                           
   
                                          
                                                                   
   
                                                                    
   
                                                           
   
                                                    
    
	public function handlePaymentCallback(string $bookingId, string $status): string {
		if ($bookingId === '' || $status === '') {
			throw new InvalidArgumentException('bookingId and status must be non-empty');
		}

		$normalised = strtolower(trim($status));
		if ($normalised === 'paid' || $normalised === 'succeeded' || $normalised === 'success') {
			try {
				$this->bookingService->confirmBooking(
					bookingId: $bookingId,
					reason: 'Deposit payment confirmed'
				);
				$this->markDepositPaid(bookingId: $bookingId);
				return 'confirmed';
			} catch (Throwable $e) {
				$this->logger->warning(
					'Pipelinq: deposit confirm failed',
					[
						'booking' => $bookingId,
						'status' => $normalised,
					]
				);
				return 'unchanged';
			}
		}

		                                                                     
		                                                           
		$this->logger->info(
			'Pipelinq: deposit payment not confirmed',
			[
				'booking' => $bookingId,
				'status' => $normalised,
			]
		);
		return 'unchanged';
	}                             

	   
                                                                          
   
                                                                                
                                                                                 
   
                
   
                                                    
    
	public function isDepositExpired(string $createdAtIso, ?int $nowEpoch = null): bool {
		if ($createdAtIso === '') {
			return false;
		}

		$created = strtotime($createdAtIso);
		if ($created === false) {
			return false;
		}

		$now = ($nowEpoch ?? time());
		return (($now - $created) >= self::DEPOSIT_TIMEOUT_SECONDS);
	}                        

	   
                                                
   
                                                                  
                                                                     
                                                         
   
                                          
   
                
   
                                                    
    
	public function releaseExpiredDeposit(string $bookingId): void {
		if ($bookingId === '') {
			return;
		}

		try {
			$this->bookingService->cancelBooking(
				bookingId: $bookingId,
				reason: 'Deposit not paid within 15 minutes',
				cancelledBy: BookingService::ACTOR_SYSTEM
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: deposit-timeout release failed',
				['booking' => $bookingId]
			);
		}
	}                             

	   
                                                                   
   
                                                                    
                                                                  
                                                                   
   
                       
   
                                                    
    
	public function resolvePaymentService(): ?object {
		                                                             
		if ($this->paymentService !== null) {
			return $this->paymentService;
		}

		try {
			                                                                     
			                                                                  
			                                                                        
			                                                                      
			                                                                       
			                                                                        
			                                                       
			  
			                                                                  
			                                                                       
			                                                                  
			                                                                     
			                                                          
			  
			                                                                         
			                                                                    
			                                                                     
			                                                                      
			                         
			$service = $this->container->get('OCA\\OpenConnector\\Service\\PaymentService');
			if (is_object($service) === true) {
				return $service;
			}
		} catch (Throwable $e) {
			                                                            
		}

		return null;
	}                             

	   
                                                                   
   
                                                                 
                                                               
   
                                                        
   
                
   
                                                    
    
	public function setPaymentService(?object $service): void {
		$this->paymentService = $service;
	}                         

	   
                                                           
   
                    
    
	private ?object $paymentService = null;

	   
                                       
   
                  
    
	private function paymentSourceSlug(): string {
		return $this->appConfig->getValueString(
			Application::APP_ID,
			self::PAYMENT_SOURCE_KEY,
			''
		);
	}                         

	   
                                                         
   
                  
    
	private function callbackUrl(): string {
		try {
			return $this->urlGenerator->linkToOCSRouteAbsolute(
				routeName: Application::APP_ID . '.appointmentPaymentWebhook.callback'
			);
		} catch (Throwable $e) {
			                                                             
			                                                            
			                                          
			return '/index.php/apps/' . Application::APP_ID . '/api/appointment-payment-webhook';
		}
	}                   

	   
                                                
   
                                                                 
                                                               
                                                       
   
                                          
   
                
    
	private function markDepositPaid(string $bookingId): void {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(
			Application::APP_ID,
			BookingService::BOOKING_SCHEMA_KEY,
			''
		);
		if ($register === '' || $schema === '') {
			return;
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			return;
		}

		try {
			$booking = $objectService->find(
				id: $bookingId,
				register: $register,
				schema: $schema
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: depositPaidAt load failed',
				['booking' => $bookingId]
			);
			return;
		}

		$data = $this->toArray(object: $booking);
		if ($data === null) {
			return;
		}

		$data['depositPaidAt'] = $this->nowIso();
		if (array_key_exists('@self', $data) === true) {
			unset($data['@self']);
		}

		try {
			$objectService->saveObject(
				object: $data,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: $bookingId
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: depositPaidAt save failed',
				['booking' => $bookingId]
			);
		}
	}                       

	   
                                                              
   
                                                             
                                                                   
                                                                       
   
                                              
   
                  
    
	private function centsToDecimal(int $cents): string {
		$sign = '';
		$absolute = $cents;
		if ($cents < 0) {
			$sign = '-';
			$absolute = -$cents;
		}

		$major = intdiv($absolute, 100);
		$minor = ($absolute % 100);
		return sprintf('%s%d.%02d', $sign, $major, $minor);
	}                      

	   
                                                       
   
                                                      
                                  
   
                  
    
	private function stringField(mixed $result, string $key): string {
		if (is_array($result) === true && isset($result[$key]) === true) {
			return (string)$result[$key];
		}

		if (is_object($result) === true && isset($result->{$key}) === true) {
			return (string)$result->{$key};
		}

		return '';
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

	   
                                          
   
                                   
                                   
   
                  
    
	private function truncate(string $value, int $max): string {
		if (strlen($value) <= $max) {
			return $value;
		}

		return substr($value, 0, $max);
	}                

	   
                        
   
                  
    
	private function nowIso(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');
	}              
}           
