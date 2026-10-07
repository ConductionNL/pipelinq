<?php

   
                                    
  
                                                                             
                                                                                
                                                                             
                                                                            
                                                                              
                                                                               
                     
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                            
  
                                                   
                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                                                     
  
                                                                  
                                                                             
                                                                              
                                                                            
  
                                                                                 
                                                                                
                                                                       
                                                                           
                                                                         
                                                      
  
                                                   
   
class AppointmentEmailService {
	   
                                                                               
   
                                                                       
                                                                      
   
               
    
	public const LINK_SECRET_KEY = 'appointment_link_secret';

	   
                                            
   
            
    
	public const LINK_TTL_SECONDS = (30 * 24 * 3600);

	   
                                  
   
               
    
	public const BOOKING_SCHEMA_KEY = BookingService::BOOKING_SCHEMA_KEY;

	   
                                  
   
               
    
	public const SERVICE_SCHEMA_KEY = BookingService::SERVICE_SCHEMA_KEY;

	   
                                             
   
               
    
	public const CUSTOMER_SCHEMA_KEY = BookingService::CUSTOMER_SCHEMA_KEY;

	   
                                                                            
   
               
    
	public const RESOURCE_SCHEMA_KEY = 'resource_schema';

	   
                
   
                                                
                                                
                                                                          
                                                
                                              
                                                                                         
                                                                                         
                                                                                             
    
	public function __construct(
		private IAppConfig $appConfig,
		private IMailer $mailer,
		private IURLGenerator $urlGenerator,
		private IL10N $l10n,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly IntegriqConsentClient $integriq,
		private readonly UnsubscribeMail $unsubscribeMail,
	) {
	}                   

	   
                                                                
   
                                              
    
	private ?array $lastRefusal = null;

	   
                                                                                
   
                                                              
   
                                                                                                                                                               
    
	public function lastRefusal(): ?array {
		return $this->lastRefusal;
	}                   

	   
                                                             
   
                                                                            
                                                                             
                                                                             
                                                                       
                                                          
   
                                                         
   
                                              
   
                                                                 
   
                                                    
    
	public function sendConfirmation(string $bookingId): bool {
		if ($bookingId === '') {
			return false;
		}

		$context = $this->loadContext(bookingId: $bookingId);
		if ($context === null) {
			return false;
		}

		$startLocal = $this->formatLocal(iso: (string)($context['booking']['startAt'] ?? ''), pattern: 'd-m-Y H:i');
		$subject = $this->l10n->t(
			'Uw afspraak is bevestigd: %1$s, %2$s',
			[(string)($context['service']['name'] ?? ''), $startLocal]
		);

		$decision = $this->askIntegriq(context: $context);
		if ($decision['send'] === false) {
			return false;
		}

		$body = $this->unsubscribeMail->appendLine(body: $this->composeConfirmationBody(context: $context), unsubscribe: $decision['unsubscribe']);
		$ics = $this->buildIcs(context: $context);

		$accepted = $this->dispatch(
			recipient: (string)$context['recipientEmail'],
			subject: $subject,
			body: $body,
			icsContent: $ics,
			unsubscribe: $decision['unsubscribe']
		);
		if ($accepted === true) {
			$this->stamp(bookingId: $bookingId, field: 'confirmationSentAt');
		}

		return $accepted;
	}                        

	   
                                                                            
   
                                              
   
                                                                  
   
                                                    
    
	public function sendReminder(string $bookingId): bool {
		if ($bookingId === '') {
			return false;
		}

		$context = $this->loadContext(bookingId: $bookingId);
		if ($context === null) {
			return false;
		}

		$startLocal = $this->formatLocal(iso: (string)($context['booking']['startAt'] ?? ''), pattern: 'H:i');
		$subject = $this->l10n->t('Herinnering: Uw afspraak morgen om %s', [$startLocal]);
		$decision = $this->askIntegriq(context: $context);
		if ($decision['send'] === false) {
			return false;
		}

		$body = $this->unsubscribeMail->appendLine(body: $this->composeReminderBody(context: $context), unsubscribe: $decision['unsubscribe']);

		$accepted = $this->dispatch(
			recipient: (string)$context['recipientEmail'],
			subject: $subject,
			body: $body,
			icsContent: null,
			unsubscribe: $decision['unsubscribe']
		);
		if ($accepted === true) {
			$this->stamp(bookingId: $bookingId, field: 'reminderSentAt');
		}

		return $accepted;
	}                    

	   
                                                                         
   
                                                                             
                                                                          
                                                                    
                                                                           
   
                                              
                                                   
                                                                
   
                                                                                
   
                                                    
    
	public function signLinkToken(string $bookingId, string $action, int $expiresAt): string {
		$payload = $bookingId . '.' . $action . '.' . $expiresAt;
		$sig = hash_hmac('sha256', $payload, $this->linkSecret());
		return $payload . '.' . $sig;
	}                     

	   
                                        
   
                                                                   
   
                  
    
	private function composeConfirmationBody(array $context): string {
		$customerName = (string)($context['customerName'] ?? '');
		$serviceName = (string)($context['service']['name'] ?? '');
		$resourceName = (string)($context['resourceName'] ?? '');
		$startLocal = $this->formatLocal(iso: (string)($context['booking']['startAt'] ?? ''), pattern: 'd-m-Y H:i');
		$endLocal = $this->formatLocal(iso: (string)($context['booking']['endAt'] ?? ''), pattern: 'H:i');
		$notes = (string)($context['booking']['notes'] ?? '');
		$price = $this->formatPrice(context: $context);

		$greetingName = $customerName;
		if ($greetingName === '') {
			$greetingName = $this->l10n->t('klant');
		}

		$lines = [];
		$lines[] = $this->l10n->t('Beste %s,', [$greetingName]);
		$lines[] = '';
		$lines[] = $this->l10n->t('Uw afspraak is bevestigd.');
		$lines[] = '';
		$lines[] = $this->l10n->t('Dienst: %s', [$serviceName]);
		if ($resourceName !== '') {
			$lines[] = $this->l10n->t('Medewerker/locatie: %s', [$resourceName]);
		}

		$lines[] = $this->l10n->t('Datum en tijd: %1$s tot %2$s', [$startLocal, $endLocal]);
		if ($price !== '') {
			$lines[] = $this->l10n->t('Prijs: %s', [$price]);
		}

		if ($notes !== '') {
			$lines[] = '';
			$lines[] = $this->l10n->t('Opmerkingen:');
			$lines[] = $notes;
		}

		$lines[] = '';
		$lines[] = $this->l10n->t('Wilt u uw afspraak verplaatsen of annuleren?');
		$lines[] = $this->l10n->t('Afspraak verplaatsen: %s', [(string)($context['rescheduleLink'] ?? '')]);
		$lines[] = $this->l10n->t('Afspraak annuleren: %s', [(string)($context['cancelLink'] ?? '')]);
		$lines[] = '';
		$lines[] = $this->l10n->t('Tot ziens!');

		return implode("\n", $lines);
	}                               

	   
                                    
   
                                                                   
   
                  
    
	private function composeReminderBody(array $context): string {
		$customerName = (string)($context['customerName'] ?? '');
		$serviceName = (string)($context['service']['name'] ?? '');
		$resourceName = (string)($context['resourceName'] ?? '');
		$startLocal = $this->formatLocal(iso: (string)($context['booking']['startAt'] ?? ''), pattern: 'd-m-Y H:i');
		$price = $this->formatPrice(context: $context);

		$greetingName = $customerName;
		if ($greetingName === '') {
			$greetingName = $this->l10n->t('klant');
		}

		$lines = [];
		$lines[] = $this->l10n->t('Beste %s,', [$greetingName]);
		$lines[] = '';
		$lines[] = $this->l10n->t('Een vriendelijke herinnering: uw afspraak is morgen.');
		$lines[] = '';
		$lines[] = $this->l10n->t('Dienst: %s', [$serviceName]);
		if ($resourceName !== '') {
			$lines[] = $this->l10n->t('Medewerker/locatie: %s', [$resourceName]);
		}

		$lines[] = $this->l10n->t('Tijd: %s', [$startLocal]);
		if ($price !== '') {
			$lines[] = $this->l10n->t('Prijs: %s', [$price]);
		}

		$lines[] = '';
		$lines[] = $this->l10n->t('Wilt u uw afspraak verplaatsen of annuleren?');
		$lines[] = $this->l10n->t('Afspraak verplaatsen: %s', [(string)($context['rescheduleLink'] ?? '')]);
		$lines[] = $this->l10n->t('Afspraak annuleren: %s', [(string)($context['cancelLink'] ?? '')]);
		$lines[] = '';
		$lines[] = $this->l10n->t('Tot morgen!');

		return implode("\n", $lines);
	}                           

	   
                                               
   
                                                                           
                                                                             
                                                                        
                                                              
   
                                                                   
   
                  
   
                                                    
    
	private function buildIcs(array $context): string {
		$bookingId = (string)($context['bookingId'] ?? '');
		$startIso = (string)($context['booking']['startAt'] ?? '');
		$endIso = (string)($context['booking']['endAt'] ?? '');
		$summary = $this->icsEscape(value: (string)($context['service']['name'] ?? 'Booking'));
		$desc = $this->icsEscape(value: (string)($context['booking']['notes'] ?? ''));
		$attendee = (string)($context['recipientEmail'] ?? '');
		$organiser = (string)($context['organiserEmail'] ?? '');

		$dtstamp = $this->toIcsDateTime(iso: $this->nowIso());
		$dtstart = $this->toIcsDateTime(iso: $startIso);
		$dtend = $this->toIcsDateTime(iso: $endIso);

		$lines = [];
		$lines[] = 'BEGIN:VCALENDAR';
		$lines[] = 'VERSION:2.0';
		$lines[] = 'PRODID:-//Conduction//Pipelinq//EN';
		$lines[] = 'CALSCALE:GREGORIAN';
		$lines[] = 'METHOD:REQUEST';
		$lines[] = 'BEGIN:VEVENT';
		$lines[] = 'UID:' . $bookingId . '@pipelinq';
		$lines[] = 'DTSTAMP:' . $dtstamp;
		$lines[] = 'DTSTART:' . $dtstart;
		$lines[] = 'DTEND:' . $dtend;
		$lines[] = 'SUMMARY:' . $summary;
		if ($desc !== '') {
			$lines[] = 'DESCRIPTION:' . $desc;
		}

		if ($attendee !== '') {
			$lines[] = 'ATTENDEE;CN=' . $this->icsEscape(value: (string)($context['customerName'] ?? '')) . ';RSVP=TRUE:mailto:' . $attendee;
		}

		if ($organiser !== '') {
			$lines[] = 'ORGANIZER:mailto:' . $organiser;
		}

		$lines[] = 'STATUS:CONFIRMED';
		$lines[] = 'END:VEVENT';
		$lines[] = 'END:VCALENDAR';

		                                       
		return implode("\r\n", $lines) . "\r\n";
	}                

	   
                                                                                    
   
                                                   
                                        
                                        
                                                                   
                                                                                          
   
                                                 
    
	private function dispatch(string $recipient, string $subject, string $body, ?string $icsContent, ?array $unsubscribe = null): bool {
		if ($recipient === '' || $this->mailer->validateMailAddress($recipient) === false) {
			$this->logger->warning(
				'Pipelinq appointment email: no valid recipient',
				['recipient' => $recipient]
			);
			return false;
		}

		try {
			$message = $this->mailer->createMessage();
			$message->setTo([$recipient]);
			$message->setSubject($subject);
			$message->setPlainBody($body);

			if ($icsContent !== null && $icsContent !== '') {
				$attachment = $this->mailer->createAttachment(
					$icsContent,
					'appointment.ics',
					'text/calendar; method=REQUEST; charset=UTF-8'
				);
				$message->attach($attachment);
			}

			$sender = $this->appConfig->getValueString(Application::APP_ID, 'appointment_email_sender', '');
			if ($sender !== '' && $this->mailer->validateMailAddress($sender) === true) {
				$message->setFrom([$sender]);
			}

			$this->unsubscribeMail->applyHeaders(message: $message, unsubscribe: $unsubscribe);

			$failed = $this->mailer->send($message);
			return empty($failed) === true;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq appointment email: dispatch failed',
				['exception' => $e->getMessage()]
			);
			return false;
		}         
	}                

	   
                                                                     
   
                                                                        
                                                      
   
                                                                 
   
                                                                                                         
   
                                                                                                                                                               
    
	private function askIntegriq(array $context): array {
		$this->lastRefusal = null;
		$decision = $this->integriq->decideOne(
			channel: 'email',
			category: IntegriqConsentClient::CATEGORY_REMINDER,
			requiresConsent: false,
			address: (string)$context['recipientEmail'],
			contactRef: (string)($context['booking']['customerId'] ?? ''),
		);
		if ($decision['send'] === true) {
			return $decision;
		}

		$this->lastRefusal = ['code' => $decision['code'], 'reason' => $decision['reason']];
		$this->logger->warning(
			'Pipelinq appointment email: not sent, integriq refused it',
			['booking' => (string)$context['bookingId'], 'code' => $decision['code']]
		);
		$this->stamp(bookingId: (string)$context['bookingId'], field: 'mailNotSentAt', extra: ['mailNotSentReason' => $decision['code']]);
		return $decision;
	}                   

	   
                                                                        
   
                                              
   
                                                                                              
    
	private function loadContext(string $bookingId): ?array {
		$booking = $this->loadObject(schemaKey: self::BOOKING_SCHEMA_KEY, id: $bookingId);
		if ($booking === null) {
			return null;
		}

		$service = $this->loadObject(schemaKey: self::SERVICE_SCHEMA_KEY, id: (string)($booking['serviceId'] ?? ''));
		$customer = $this->loadObject(schemaKey: self::CUSTOMER_SCHEMA_KEY, id: (string)($booking['customerId'] ?? ''));

		$recipient = $this->resolveCustomerEmail(customer: $customer);
		if ($recipient === '') {
			$this->logger->info(
				'Pipelinq appointment email: skipped — no recipient email on customer',
				['booking' => $bookingId]
			);
			return null;
		}

		$customerName = $this->resolveCustomerName(customer: $customer);
		$resourceName = $this->resolveResourceName(booking: $booking);

		                                                    
		$expiresAt = (time() + self::LINK_TTL_SECONDS);
		$rescheduleToken = $this->signLinkToken(bookingId: $bookingId, action: 'reschedule', expiresAt: $expiresAt);
		$cancelToken = $this->signLinkToken(bookingId: $bookingId, action: 'cancel', expiresAt: $expiresAt);

		$rescheduleLink = $this->urlGenerator->getAbsoluteURL('/index.php/apps/pipelinq/portal/reschedule')
			. '?link=' . rawurlencode($rescheduleToken) . '&bookingId=' . rawurlencode($bookingId);
		$cancelLink = $this->urlGenerator->getAbsoluteURL('/index.php/apps/pipelinq/portal/cancel')
			. '?link=' . rawurlencode($cancelToken) . '&bookingId=' . rawurlencode($bookingId);

		$organiser = $this->appConfig->getValueString(Application::APP_ID, 'appointment_email_sender', '');

		return [
			'bookingId' => $bookingId,
			'booking' => $booking,
			'service' => ($service ?? []),
			'customerName' => $customerName,
			'resourceName' => $resourceName,
			'recipientEmail' => $recipient,
			'organiserEmail' => $organiser,
			'rescheduleLink' => $rescheduleLink,
			'cancelLink' => $cancelLink,
		];
	}                   

	   
                                                       
   
                                              
                                                                                                
                                                                   
   
                
    
	private function stamp(string $bookingId, string $field, array $extra = []): void {
		$register = $this->registerId();
		$schema = $this->schemaId(key: self::BOOKING_SCHEMA_KEY);
		if ($register === '' || $schema === '') {
			return;
		}

		try {
			$found = $this->getObjectService()->find(
				id: $bookingId,
				register: $register,
				schema: $schema
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq appointment email: stamp lookup failed', ['booking' => $bookingId]);
			return;
		}

		$data = $this->toArray(object: $found);
		if ($data === null) {
			return;
		}

		$data[$field] = $this->nowIso();
		foreach ($extra as $key => $value) {
			$data[$key] = $value;
		}

		if (array_key_exists('@self', $data) === true) {
			unset($data['@self']);
		}

		try {
			$this->getObjectService()->saveObject(
				object: $data,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: $bookingId
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq appointment email: stamp save failed',
				['booking' => $bookingId, 'field' => $field]
			);
		}
	}             

	   
                                                                                        
   
                                                                             
   
                  
   
                                                                                 
                                                                                
            
    
	private function resolveCustomerEmail(?array $customer): string {
		if ($customer === null) {
			return '';
		}

		foreach (['email', 'emailAddress', 'mail'] as $key) {
			$value = (string)($customer[$key] ?? '');
			if ($value !== '') {
				return $value;
			}
		}

		$emails = $customer['emails'] ?? null;
		if (is_array($emails) === true) {
			foreach ($emails as $entry) {
				if (is_string($entry) === true && $entry !== '') {
					return $entry;
				}

				if (is_array($entry) === true) {
					$value = (string)($entry['value'] ?? $entry['address'] ?? '');
					if ($value !== '') {
						return $value;
					}
				}
			}
		}

		return '';
	}                            

	   
                                                      
   
                                                                   
   
                  
    
	private function resolveCustomerName(?array $customer): string {
		if ($customer === null) {
			return '';
		}

		foreach (['fullName', 'displayName', 'name'] as $key) {
			$value = (string)($customer[$key] ?? '');
			if ($value !== '') {
				return $value;
			}
		}

		$first = (string)($customer['firstName'] ?? '');
		$last = (string)($customer['lastName'] ?? '');
		return trim($first . ' ' . $last);
	}                           

	   
                                                                       
   
                                                     
   
                  
    
	private function resolveResourceName(array $booking): string {
		$assignments = ($booking['resourceAssignments'] ?? []);
		if (is_array($assignments) === false || $assignments === []) {
			return '';
		}

		$first = $assignments[0] ?? null;
		if (is_array($first) === false) {
			return '';
		}

		$resourceId = (string)($first['resourceId'] ?? '');
		if ($resourceId === '') {
			return '';
		}

		$resource = $this->loadObject(schemaKey: self::RESOURCE_SCHEMA_KEY, id: $resourceId);
		if ($resource === null) {
			return '';
		}

		return (string)($resource['name'] ?? '');
	}                           

	   
                                                                  
   
                                                                   
   
                  
    
	private function formatPrice(array $context): string {
		$service = ($context['service'] ?? []);
		if (is_array($service) === false) {
			return '';
		}

		$price = (float)($service['price'] ?? 0.0);
		$currency = (string)($service['currency'] ?? 'EUR');
		if ($price <= 0.0) {
			return '';
		}

		return $currency . ' ' . number_format($price, 2, ',', '.');
	}                   

	   
                                                                                    
   
                                                                            
                                       
   
                                     
    
	private function loadObject(string $schemaKey, string $id): ?array {
		if ($id === '') {
			return null;
		}

		$register = $this->registerId();
		$schema = $this->schemaId(key: $schemaKey);
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$found = $this->getObjectService()->find(
				id: $id,
				register: $register,
				schema: $schema
			);
		} catch (\Throwable $e) {
			return null;
		}

		return $this->toArray(object: $found);
	}                  

	   
                                                    
   
                  
    
	private function linkSecret(): string {
		$secret = $this->appConfig->getValueString(Application::APP_ID, self::LINK_SECRET_KEY, '');
		if ($secret !== '') {
			return $secret;
		}

		try {
			$secret = bin2hex(random_bytes(32));
		} catch (\Throwable $e) {
			                                                            
			$secret = hash('sha256', Application::APP_ID . '|' . (string)microtime(true));
		}

		$this->appConfig->setValueString(Application::APP_ID, self::LINK_SECRET_KEY, $secret);
		return $secret;
	}                  

	   
                                                           
   
                                          
                                                   
   
                  
    
	private function formatLocal(string $iso, string $pattern): string {
		if ($iso === '') {
			return '';
		}

		try {
			$dateTime = new DateTimeImmutable($iso);
			$dateTime = $dateTime->setTimezone(new DateTimeZone('Europe/Amsterdam'));
			return $dateTime->format($pattern);
		} catch (\Throwable $e) {
			return $iso;
		}
	}                   

	   
                                                                        
   
                                          
   
                  
    
	private function toIcsDateTime(string $iso): string {
		try {
			$dateTime = new DateTimeImmutable($iso);
			$dateTime = $dateTime->setTimezone(new DateTimeZone('UTC'));
			return $dateTime->format('Ymd\THis\Z');
		} catch (\Throwable $e) {
			return '';
		}
	}                     

	   
                                                                      
   
                                   
   
                  
    
	private function icsEscape(string $value): string {
		$value = str_replace('\\', '\\\\', $value);
		$value = str_replace([',', ';'], ['\\,', '\\;'], $value);
		$value = str_replace(["\r\n", "\n", "\r"], '\\n', $value);
		return $value;
	}                 

	   
                        
   
                  
    
	private function nowIso(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');
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
		                                                                 
		                                                              
		return $this->objectService;
	}                        
}           
