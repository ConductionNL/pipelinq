<?php

   
                                       
  
                                                                       
                                                                    
                                                                           
                                                                  
                                                                  
                                                                      
                                 
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                           
  
                                                                          
                                                                      
                                                             
                                                              
                                                                    
  
                                                                 
                                                                        
                                                               
  
                                                  
  
                                                   
   
class AppointmentPaymentProvider {
	   
                                             
   
               
    
	public const KIND_NO_SHOW = 'no-show';

	   
                                             
   
               
    
	public const KIND_CANCELLATION = 'late-cancellation';

	   
                
   
                                                                                      
                                                   
                                              
    
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                     
   
               
                                     
                                                              
                                                        
                                   
   
                                                          
   
                                          
                                            
   
                
   
                                                    
    
	public function chargeNoShowFee(string $bookingId, float $amount): void {
		$this->chargeBookingFee(
			bookingId: $bookingId,
			amount: $amount,
			kind: self::KIND_NO_SHOW,
			stampField: 'noShowFeeChargedAt'
		);
	}                       

	   
                                                               
   
                                                       
                               
   
                                          
                                            
   
                
   
                                                    
    
	public function chargeCancellationFee(string $bookingId, float $amount): void {
		$this->chargeBookingFee(
			bookingId: $bookingId,
			amount: $amount,
			kind: self::KIND_CANCELLATION,
			stampField: 'cancellationFeeChargedAt'
		);
	}                             

	   
                                                                  
   
                                                        
   
                
   
                                                    
    
	public function setPaymentService(?object $service): void {
		$this->paymentService = $service;
	}                         

	   
                                                           
   
                    
    
	private ?object $paymentService = null;

	   
                                                
   
                                          
                                                                
                                                                      
                                                                
   
                
    
	private function chargeBookingFee(
		string $bookingId,
		float $amount,
		string $kind,
		string $stampField,
	): void {
		if ($bookingId === '' || $amount <= 0.0) {
			return;
		}

		$cents = (int)round(($amount * 100));
		if ($cents <= 0) {
			return;
		}

		$payment = $this->resolvePaymentService();
		if ($payment === null) {
			$this->logger->info(
				'Pipelinq: openconnector PaymentService unavailable — charge skipped',
				[
					'booking' => $bookingId,
					'kind' => $kind,
				]
			);
			return;
		}

		$booking = $this->loadBooking(bookingId: $bookingId);
		if ($booking === null) {
			return;
		}

		$customerId = (string)($booking['customerId'] ?? '');
		if ($this->hasPaymentMethodOnFile(customerId: $customerId) === false) {
			$this->logger->info(
				'Pipelinq: no payment method on file — charge skipped',
				[
					'booking' => $bookingId,
					'customer' => $customerId,
					'kind' => $kind,
				]
			);
			return;
		}

		$sourceSlug = $this->appConfig->getValueString(
			Application::APP_ID,
			AppointmentDepositService::PAYMENT_SOURCE_KEY,
			''
		);
		if ($sourceSlug === '') {
			$this->logger->warning(
				'Pipelinq: appointment_payment_source not configured — charge skipped',
				[
					'booking' => $bookingId,
					'kind' => $kind,
				]
			);
			return;
		}

		try {
			                                                       
			$payment->chargeCustomer(
				$sourceSlug,
				[
					'customerId' => $customerId,
					'amount' => [
						'value' => $this->centsToDecimal(cents: $cents),
						'currency' => 'EUR',
					],
					'description' => sprintf('Pipelinq %s fee — booking %s', $kind, $bookingId),
					'metadata' => [
						'app' => Application::APP_ID,
						'kind' => $kind,
						'bookingId' => $bookingId,
					],
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: openconnector chargeCustomer failed',
				[
					'booking' => $bookingId,
					'kind' => $kind,
					'cents' => $cents,
				]
			);
			return;
		}         

		$this->stampBooking(bookingId: $bookingId, field: $stampField, value: $this->nowIso());
	}                        

	   
                                                               
   
                       
    
	private function resolvePaymentService(): ?object {
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

	   
                                                           
   
                                          
   
                                     
    
	private function loadBooking(string $bookingId): ?array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(
			Application::APP_ID,
			BookingService::BOOKING_SCHEMA_KEY,
			''
		);
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			return null;
		}

		try {
			$found = $objectService->find(
				id: $bookingId,
				register: $register,
				schema: $schema
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: booking lookup failed during fee charge',
				['booking' => $bookingId]
			);
			return null;
		}

		return $this->toArray(object: $found);
	}                   

	   
                                                        
   
                                                                     
                                                                  
                                                                 
                                     
   
                                            
   
                
    
	private function hasPaymentMethodOnFile(string $customerId): bool {
		if ($customerId === '') {
			return false;
		}

		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(
			Application::APP_ID,
			BookingService::CUSTOMER_SCHEMA_KEY,
			''
		);
		if ($register === '' || $schema === '') {
			return false;
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			return false;
		}

		try {
			$customer = $objectService->find(
				id: $customerId,
				register: $register,
				schema: $schema
			);
		} catch (Throwable $e) {
			return false;
		}

		$data = $this->toArray(object: $customer);
		if ($data === null) {
			return false;
		}

		$token = trim((string)($data['paymentMethodToken'] ?? ''));
		return ($token !== '');
	}                              

	   
                                                
   
                                          
                                                              
                                            
   
                
    
	private function stampBooking(string $bookingId, string $field, string $value): void {
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
			return;
		}

		$data = $this->toArray(object: $booking);
		if ($data === null) {
			return;
		}

		$data[$field] = $value;
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
				'Pipelinq: booking stamp save failed',
				[
					'booking' => $bookingId,
					'field' => $field,
				]
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

	   
                        
   
                  
    
	private function nowIso(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');
	}              
}           
