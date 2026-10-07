<?php

   
                             
  
                                                                            
                                                                        
                                                                            
                                                                        
                                                                             
                                                                               
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                            
  
                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\AppointmentDepositService;
use OCA\Pipelinq\Service\BookingService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

   
                                   
  
                                                                              
                                                                               
                                                                          
                                                                  
  
                                                                              
                                                                           
                                    
                                                                                                    
                                                                                               
                                   
                                                                                                
                                                                  
  
                                                   
   
class PortalController extends Controller {
	   
                                                                   
   
                                                                           
                                                                
   
               
    
	private const SIGNING_KEY_CONFIG = 'appointment_portal_signing_key';

	   
                                                  
   
            
    
	private const LINK_TTL_DAYS = 30;

	   
                                                                          
   
            
    
	private const MAX_TEXT_LENGTH = 1000;

	   
                
   
                                         
                                                                                
                                                                                              
                                                
                                                                                
                                                                           
                                                                             
                                                                    
                                                                        
                                              
    
	public function __construct(
		IRequest $request,
		private BookingService $bookingService,
		private AppointmentDepositService $depositService,
		private IAppConfig $appConfig,
		private ContainerInterface $container,
		private IAppManager $appManager,
		private IURLGenerator $urlGenerator,
		private ITimeFactory $time,
		private ISecureRandom $secureRandom,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                                      
   
                                                                              
   
                   
               
         
   
                                                    
    
	#[AnonRateLimit(limit: 120, period: 60)]
	public function services(): JSONResponse {
		try {
			$services = $this->listBookableServices();
			return new JSONResponse(['services' => $services], Http::STATUS_OK);
		} catch (RuntimeException $e) {
			$this->logger->warning('Pipelinq portal: services lookup unavailable');
			return new JSONResponse(['error' => 'Service unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}
	}                

	   
                                                                        
   
                                                                     
   
                                                                              
   
                   
               
         
   
                                                    
    
	#[AnonRateLimit(limit: 120, period: 60)]
	public function availability(): JSONResponse {
		$serviceId = trim((string)$this->request->getParam('serviceId', ''));
		$date = trim((string)$this->request->getParam('date', ''));
		if ($serviceId === '' || $date === '') {
			return new JSONResponse(['error' => 'serviceId and date are required'], Http::STATUS_BAD_REQUEST);
		}

		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
			return new JSONResponse(['error' => 'date must be YYYY-MM-DD'], Http::STATUS_BAD_REQUEST);
		}

		$slots = $this->bookingService->getAvailableSlots(serviceId: $serviceId, date: $date);
		return new JSONResponse(['slots' => $slots], Http::STATUS_OK);
	}                    

	   
                                                                     
   
                                                                                     
   
                                                                            
                                                                           
                                
   
                                                                                
   
                   
               
         
   
                                                                               
                                                                            
                   
   
                                                    
    
	#[AnonRateLimit(limit: 20, period: 60)]
	public function book(): JSONResponse {
		$params = $this->collectBookParams();
		$error = $this->validateBookParams(params: $params);
		if ($error !== null) {
			return $error;
		}

		try {
			$created = $this->createBookingFromPortal(params: $params);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => 'Invalid booking request'], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->error('Pipelinq portal: booking creation failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'Booking could not be created'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$bookingId = $created['bookingId'];

		return new JSONResponse(
			[
				'bookingId' => $bookingId,
				'manageLinks' => $this->buildManageLinks(bookingId: $bookingId),
				'paymentRedirect' => $this->resolvePaymentRedirect(bookingId: $bookingId, created: $created),
				'message' => 'Booking received',
			],
			Http::STATUS_OK
		);
	}            

	   
                                                                   
   
                                                                      
                                                                              
                                                                     
                                                                         
                                                                             
                                                                            
                                                               
   
                                                              
                                                                            
   
                                                                                         
   
                                                                
    
	private function resolvePaymentRedirect(string $bookingId, array $created): ?string {
		$requiresDeposit = (bool)($created['requiresDeposit'] ?? false);
		$depositAmount = (float)($created['depositAmount'] ?? 0.0);
		if ($requiresDeposit === false || $depositAmount <= 0.0) {
			return null;
		}

		$amountCents = (int)round($depositAmount * 100);
		if ($amountCents <= 0) {
			return null;
		}

		$returnUrl = $this->urlGenerator->getAbsoluteURL(
			'/index.php/apps/pipelinq/portal/api/booking/' . rawurlencode($bookingId)
		);

		try {
			$session = $this->depositService->createDepositSession(
				bookingId: $bookingId,
				amountCents: $amountCents,
				currency: (string)($created['currency'] ?? 'EUR'),
				description: 'Deposit for ' . (string)($created['serviceName'] ?? 'appointment'),
				returnUrl: $returnUrl
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq portal: deposit session could not be opened',
				['booking' => $bookingId]
			);
			return null;
		}

		$sessionUrl = (string)$session['sessionUrl'];
		if ($sessionUrl === '') {
			return null;
		}

		return $sessionUrl;
	}                              

	   
                                                                          
   
                                                              
                                                                         
                                                         
                                           
   
                                              
   
                                                                                
   
                   
               
         
   
                                                    
    
	#[AnonRateLimit(limit: 120, period: 60)]
	public function getBooking(string $bookingId): JSONResponse {
		$token = trim((string)$this->request->getParam('token', ''));
		if ($token !== '') {
			$verdict = $this->validateSignedLink(token: $token, expectedBookingId: $bookingId);
			if ($verdict !== null) {
				return $verdict;
			}
		}

		$booking = $this->loadBookingPublic(bookingId: $bookingId);
		if ($booking === null) {
			return new JSONResponse(['error' => 'Booking not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($booking, Http::STATUS_OK);
	}                  

	   
                                                                   
   
                                                         
   
                                                                                              
   
                   
               
         
   
                                                    
    
	#[AnonRateLimit(limit: 30, period: 60)]
	public function reschedule(): JSONResponse {
		$token = trim((string)$this->request->getParam('token', ''));
		$newStartAt = trim((string)$this->request->getParam('newStartAt', ''));
		if ($token === '' || $newStartAt === '') {
			return new JSONResponse(['error' => 'token and newStartAt are required'], Http::STATUS_BAD_REQUEST);
		}

		$payload = $this->openSignedLink(token: $token);
		if (is_string($payload) === true) {
			return new JSONResponse(['error' => 'Link expired or invalid'], Http::STATUS_GONE);
		}

		$bookingId = (string)($payload['bookingId'] ?? '');
		$action = (string)($payload['action'] ?? '');
		if ($bookingId === '' || $action !== 'reschedule') {
			return new JSONResponse(['error' => 'Link expired or invalid'], Http::STATUS_GONE);
		}

		try {
			$newId = $this->bookingService->rescheduleBooking(bookingId: $bookingId, newStartAt: $newStartAt);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => 'Invalid reschedule request'], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->error('Pipelinq portal: reschedule failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'Reschedule failed'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse(['bookingId' => $newId, 'previousBookingId' => $bookingId], Http::STATUS_OK);
	}                  

	   
                                                           
   
                                                   
   
                                                                                                    
   
                   
               
         
   
                                                    
    
	#[AnonRateLimit(limit: 30, period: 60)]
	public function cancel(): JSONResponse {
		$token = trim((string)$this->request->getParam('token', ''));
		$reason = (string)$this->request->getParam('reason', '');
		if ($token === '') {
			return new JSONResponse(['error' => 'token is required'], Http::STATUS_BAD_REQUEST);
		}

		$payload = $this->openSignedLink(token: $token);
		if (is_string($payload) === true) {
			return new JSONResponse(['error' => 'Link expired or invalid'], Http::STATUS_GONE);
		}

		$bookingId = (string)($payload['bookingId'] ?? '');
		$action = (string)($payload['action'] ?? '');
		$customerId = (string)($payload['customerId'] ?? '');
		if ($bookingId === '' || $action !== 'cancel') {
			return new JSONResponse(['error' => 'Link expired or invalid'], Http::STATUS_GONE);
		}

		try {
			$this->bookingService->cancelBooking(
				bookingId: $bookingId,
				reason: substr($reason, 0, self::MAX_TEXT_LENGTH),
				cancelledBy: $customerId
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => 'Invalid cancel request'], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->error('Pipelinq portal: cancel failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'Cancellation failed'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse(['bookingId' => $bookingId, 'cancelled' => true], Http::STATUS_OK);
	}              

	   
                                                  
   
                                 
    
	private function collectBookParams(): array {
		return [
			'customerName' => trim((string)$this->request->getParam('customerName', '')),
			'email' => trim((string)$this->request->getParam('email', '')),
			'phone' => trim((string)$this->request->getParam('phone', '')),
			'serviceId' => trim((string)$this->request->getParam('serviceId', '')),
			'startAt' => trim((string)$this->request->getParam('startAt', '')),
			'notes' => substr((string)$this->request->getParam('notes', ''), 0, self::MAX_TEXT_LENGTH),
		];
	}                         

	   
                                                                   
   
                                                            
   
                                                                                   
    
	private function validateBookParams(array $params): ?JSONResponse {
		$required = ['customerName', 'email', 'phone', 'serviceId', 'startAt'];
		foreach ($required as $field) {
			if (($params[$field] ?? '') === '') {
				return new JSONResponse(['error' => 'Missing required field'], Http::STATUS_BAD_REQUEST);
			}
		}

		if (filter_var($params['email'], FILTER_VALIDATE_EMAIL) === false) {
			return new JSONResponse(['error' => 'Invalid email'], Http::STATUS_BAD_REQUEST);
		}

		if (strtotime($params['startAt']) === false) {
			return new JSONResponse(['error' => 'Invalid startAt'], Http::STATUS_BAD_REQUEST);
		}

		return null;
	}                          

	   
                                                 
   
                                                                          
                                                                         
                                                                         
                                         
   
                                                               
   
                                                                                                                        
   
                                                                         
                                                            
    
	private function createBookingFromPortal(array $params): array {
		$service = $this->loadService(serviceId: $params['serviceId']);
		if ($service === null) {
			throw new InvalidArgumentException('Unknown service');
		}

		if (($service['bookableOnline'] ?? false) !== true) {
			throw new InvalidArgumentException('Service is not bookable online');
		}

		$duration = (int)($service['durationMinutes'] ?? 0);
		if ($duration <= 0) {
			throw new InvalidArgumentException('Service duration is not configured');
		}

		$endAt = $this->computeEndAt(startAt: $params['startAt'], durationMinutes: $duration);

		$customerId = $this->upsertCustomer(
			name: $params['customerName'],
			email: $params['email'],
			phone: $params['phone']
		);

		$bookingId = $this->bookingService->createBooking(
			data: [
				'customerId' => $customerId,
				'serviceId' => $params['serviceId'],
				'startAt' => $params['startAt'],
				'endAt' => $endAt,
				'notes' => $params['notes'],
			],
			source: 'portal'
		);

		return [
			'bookingId' => $bookingId,
			'requiresDeposit' => (bool)($service['requiresDeposit'] ?? false),
			'depositAmount' => (float)($service['depositAmount'] ?? 0.0),
			'currency' => (string)($service['currency'] ?? 'EUR'),
			'serviceName' => (string)($service['name'] ?? 'appointment'),
		];
	}                               

	   
                                                                
   
                                                                            
                                                                        
   
                                            
   
                                                            
    
	private function listBookableServices(): array {
		$register = $this->registerId();
		$schema = $this->schemaId(key: 'service_schema');
		if ($register === '' || $schema === '') {
			throw new RuntimeException('Service register/schema not configured');
		}

		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'bookableOnline' => true,
					],
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq portal: services findAll failed');
			return [];
		}

		$results = ($rows['results'] ?? $rows);
		if (is_array($results) === false) {
			return [];
		}

		$out = [];
		foreach ($results as $row) {
			$data = $this->toArray(object: $row);
			if ($data === null) {
				continue;
			}

			                                                                  
			if (($data['bookableOnline'] ?? false) !== true) {
				continue;
			}

			$out[] = [
				'id' => $this->idOf(object: $data),
				'name' => (string)($data['name'] ?? ''),
				'description' => (string)($data['description'] ?? ''),
				'durationMinutes' => (int)($data['durationMinutes'] ?? 0),
				'price' => (float)($data['price'] ?? 0.0),
				'currency' => (string)($data['currency'] ?? 'EUR'),
				'requiresDeposit' => (bool)($data['requiresDeposit'] ?? false),
				'depositAmount' => (float)($data['depositAmount'] ?? 0.0),
			];
		}             

		return $out;
	}                            

	   
                                                              
   
                                                   
   
                                     
    
	private function loadService(string $serviceId): ?array {
		$register = $this->registerId();
		$schema = $this->schemaId(key: 'service_schema');
		if ($register === '' || $schema === '' || $serviceId === '') {
			return null;
		}

		try {
			$found = $this->getObjectService()->find(
				id: $serviceId,
				register: $register,
				schema: $schema
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq portal: service load failed', ['service' => $serviceId]);
			return null;
		}

		return $this->toArray(object: $found);
	}                   

	   
                                                             
   
                                                                           
                                                                       
   
                                              
   
                                     
    
	private function loadBookingPublic(string $bookingId): ?array {
		$register = $this->registerId();
		$schema = $this->schemaId(key: 'booking_schema');
		if ($register === '' || $schema === '' || $bookingId === '') {
			return null;
		}

		try {
			$found = $this->getObjectService()->find(
				id: $bookingId,
				register: $register,
				schema: $schema
			);
		} catch (\Throwable $e) {
			return null;
		}

		$data = $this->toArray(object: $found);
		if ($data === null) {
			return null;
		}

		return [
			'id' => $this->idOf(object: $data),
			'serviceId' => (string)($data['serviceId'] ?? ''),
			'startAt' => (string)($data['startAt'] ?? ''),
			'endAt' => (string)($data['endAt'] ?? ''),
			'status' => (string)($data['status'] ?? ''),
		];
	}                         

	   
                                       
   
                                                                          
                                                                     
   
                                     
                                    
                                        
   
                                                                            
   
                                                            
   
                                                                                               
                                                                                          
                                                                                  
    
	private function upsertCustomer(string $name, string $email, string $phone): string {
		$register = $this->registerId();
		$schema = $this->schemaId(key: 'contact_schema');
		if ($register === '' || $schema === '') {
			                                                                
			                                                                      
			                                                                
			return ('email:' . substr($email, 0, 240));
		}

		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'email' => $email,
					],
					'limit' => 1,
				]
			);
			$results = ($rows['results'] ?? $rows);
			if (is_array($results) === true && $results !== []) {
				$existing = $this->toArray(object: $results[0]);
				if ($existing !== null) {
					$id = $this->idOf(object: $existing);
					if ($id !== '') {
						return $id;
					}
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq portal: customer lookup failed');
		}         

		try {
			$saved = $this->getObjectService()->saveObject(
				object: [
					'name' => substr($name, 0, 255),
					'email' => $email,
					'phone' => substr($phone, 0, 64),
					'source' => 'portal',
				],
				extend: [],
				register: $register,
				schema: $schema,
				uuid: null
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq portal: customer save failed');
			return ('email:' . substr($email, 0, 240));
		}

		$data = $this->toArray(object: $saved);
		if ($data === null) {
			return ('email:' . substr($email, 0, 240));
		}

		$id = $this->idOf(object: $data);
		if ($id === '') {
			return ('email:' . substr($email, 0, 240));
		}

		return $id;
	}                      

	   
                                              
   
                                              
                                                     
   
                                      
    
	private function computeEndAt(string $startAt, int $durationMinutes): string {
		$startTimestamp = strtotime($startAt);
		if ($startTimestamp === false) {
			return $startAt;
		}

		$end = ($startTimestamp + ($durationMinutes * 60));
		return (new DateTimeImmutable('@' . $end))
			->setTimezone(new DateTimeZone('UTC'))
			->format('Y-m-d\TH:i:sP');
	}                    

	   
                                                           
   
                                                                      
                                                   
   
                                              
   
                                                     
    
	private function buildManageLinks(string $bookingId): array {
		$customerId = $this->lookupCustomerForBooking(bookingId: $bookingId);
		$base = $this->urlGenerator->getAbsoluteURL('/index.php/apps/pipelinq/portal/api/booking/' . rawurlencode($bookingId));
		return [
			'reschedule' => ($base . '?token=' . rawurlencode($this->signLink(bookingId: $bookingId, action: 'reschedule', customerId: $customerId))),
			'cancel' => ($base . '?token=' . rawurlencode($this->signLink(bookingId: $bookingId, action: 'cancel', customerId: $customerId))),
		];
	}                        

	   
                                                                    
   
                                                                  
                                                   
                                                                                      
   
                                                            
   
                                                    
    
	public function signLink(string $bookingId, string $action, string $customerId = ''): string {
		$issuedAt = $this->time->getTime();
		$expiresAt = ($issuedAt + (self::LINK_TTL_DAYS * 24 * 3600));

		$payload = [
			'bookingId' => $bookingId,
			'action' => $action,
			'customerId' => $customerId,
			'issuedAt' => $issuedAt,
			'expiresAt' => $expiresAt,
		];

		$encoded = $this->base64UrlEncode(data: (string)json_encode($payload));
		$signature = $this->base64UrlEncode(data: hash_hmac('sha256', $encoded, $this->signingKey(), true));
		return ($encoded . '.' . $signature);
	}                

	   
                                                       
   
                                                                  
                                                     
   
                                             
   
                                                                                 
   
                                                    
    
	public function openSignedLink(string $token): array|string {
		$parts = explode('.', $token);
		if (count($parts) !== 2) {
			return 'invalid';
		}

		[$encoded, $signature] = $parts;
		$expected = $this->base64UrlEncode(data: hash_hmac('sha256', $encoded, $this->signingKey(), true));
		if (hash_equals($expected, $signature) === false) {
			return 'invalid';
		}

		$decoded = json_decode((string)$this->base64UrlDecode(data: $encoded), true);
		if (is_array($decoded) === false || isset($decoded['expiresAt']) === false) {
			return 'invalid';
		}

		if ((int)$decoded['expiresAt'] < $this->time->getTime()) {
			return 'expired';
		}

		return $decoded;
	}                      

	   
                                                          
   
                                             
                                                                      
   
                                                                         
    
	private function validateSignedLink(string $token, string $expectedBookingId): ?JSONResponse {
		$payload = $this->openSignedLink(token: $token);
		if (is_string($payload) === true) {
			return new JSONResponse(['error' => 'Link expired or invalid'], Http::STATUS_GONE);
		}

		if ((string)($payload['bookingId'] ?? '') !== $expectedBookingId) {
			return new JSONResponse(['error' => 'Link expired or invalid'], Http::STATUS_GONE);
		}

		return null;
	}                          

	   
                                                                           
   
                                              
   
                  
    
	private function lookupCustomerForBooking(string $bookingId): string {
		$register = $this->registerId();
		$schema = $this->schemaId(key: 'booking_schema');
		if ($register === '' || $schema === '' || $bookingId === '') {
			return '';
		}

		try {
			$found = $this->getObjectService()->find(
				id: $bookingId,
				register: $register,
				schema: $schema
			);
		} catch (\Throwable $e) {
			return '';
		}

		$data = $this->toArray(object: $found);
		if ($data === null) {
			return '';
		}

		return (string)($data['customerId'] ?? '');
	}                                

	   
                                                               
   
                                   
    
	private function signingKey(): string {
		$key = $this->appConfig->getValueString(Application::APP_ID, self::SIGNING_KEY_CONFIG, '');
		if ($key === '') {
			$key = $this->secureRandom->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
			$this->appConfig->setValueString(Application::APP_ID, self::SIGNING_KEY_CONFIG, $key, false, true);
		}

		return $key;
	}                  

	   
                                               
   
                                     
   
                                      
    
	private function base64UrlEncode(string $data): string {
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}                       

	   
                     
   
                                           
   
                                                       
    
	private function base64UrlDecode(string $data): string {
		$decoded = base64_decode(strtr($data, '-_', '+/'), true);
		if ($decoded === false) {
			return '';
		}

		return $decoded;
	}                       

	   
                                             
   
                                                                      
                                                                        
                                                                      
                                                                         
   
                                                                       
    
	private function registerId(): string {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($registerId === '') {
			$this->logger->warning(
				'Pipelinq: app-config "register" is not configured; OpenRegister calls are refused, not run unscoped'
			);
		}

		return $registerId;
	}                  

	   
                                          
   
                                                                  
   
                  
    
	private function schemaId(string $key): string {
		return $this->appConfig->getValueString(Application::APP_ID, $key, '');
	}                

	   
                                                                  
   
                                                          
   
                  
    
	private function idOf(array|object $object): string {
		$arr = $this->toArray(object: $object);
		if ($arr === null) {
			return '';
		}

		if (isset($arr['@self']) === true && is_array($arr['@self']) === true) {
			$self = $arr['@self'];
			if (isset($self['id']) === true) {
				return (string)$self['id'];
			}

			if (isset($self['uuid']) === true) {
				return (string)$self['uuid'];
			}
		}

		if (isset($arr['id']) === true) {
			return (string)$arr['id'];
		}

		if (isset($arr['uuid']) === true) {
			return (string)$arr['uuid'];
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

	   
                                                                
   
                                              
   
                                                                        
    
	private function getObjectService(): object {
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			throw new RuntimeException('OpenRegister is not installed.');
		}

		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			throw new RuntimeException('OpenRegister ObjectService is unavailable.');
		}
	}                        
}           
