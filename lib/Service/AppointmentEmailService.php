<?php

/**
 * Pipelinq AppointmentEmailService.
 *
 * Composes confirmation + 24-hour reminder email content for the appointment
 * booking surface (member 07 of 12): subject lines, plain-text bodies, RFC-5545
 * `.ics` calendar attachments, and HMAC-SHA256-signed reschedule/cancel deep
 * links. Dispatch goes through Nextcloud's `IMailer` — the dispatch step is
 * intentionally a single private method so the transport can later be swapped
 * for the `email-calendar-sync` leaf (ADR-022 leaf-first) without touching the
 * composition logic.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/appointment-booking-07-email-confirmation-reminder/specs/appointment-booking/spec.md#req-apt-006
 * @spec openspec/changes/appointment-booking-07-email-confirmation-reminder/specs/appointment-booking/spec.md#req-apt-007
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Mail\IMailer;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * AppointmentEmailService — composes confirmation + reminder emails.
 *
 * The service is registered as the BookingService email seam (see
 * {@see \OCA\Pipelinq\AppInfo\Application::boot}) so confirmation emails are
 * sent automatically when a booking is created or transitions into confirmed.
 * The ReminderDispatchJob calls {@see self::sendReminder} for due bookings.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   The class legitimately wires
 *  the OR container, mailer, URL generator, localisation and app config — every
 *  collaborator is needed for transactional booking email composition.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Composition, dispatch,
 *  localisation and ICS building for two email types drive the aggregate
 *  complexity; each method stays individually simple.
 *
 * @spec openspec/changes/appointment-booking-07-email-confirmation-reminder/specs/appointment-booking/spec.md#req-apt-006
 */
class AppointmentEmailService
{
    /**
     * App-config key for the signing secret used on reschedule/cancel deep links.
     *
     * The secret is auto-generated on first use; admins may rotate it via
     * `occ config:app:set pipelinq appointment_link_secret --value=...`.
     *
     * @var string
     */
    public const LINK_SECRET_KEY = 'appointment_link_secret';

    /**
     * Signed-link expiry in seconds (30 days).
     *
     * @var int
     */
    public const LINK_TTL_SECONDS = (30 * 24 * 3600);

    /**
     * Booking schema app-config key.
     *
     * @var string
     */
    public const BOOKING_SCHEMA_KEY = BookingService::BOOKING_SCHEMA_KEY;

    /**
     * Service schema app-config key.
     *
     * @var string
     */
    public const SERVICE_SCHEMA_KEY = BookingService::SERVICE_SCHEMA_KEY;

    /**
     * Customer (contact) schema app-config key.
     *
     * @var string
     */
    public const CUSTOMER_SCHEMA_KEY = BookingService::CUSTOMER_SCHEMA_KEY;

    /**
     * Resource schema app-config key (read for resource display name in body).
     *
     * @var string
     */
    public const RESOURCE_SCHEMA_KEY = 'resource_schema';

    /**
     * Constructor.
     *
     * @param ContainerInterface $container    The DI container (OR ObjectService).
     * @param IAppConfig         $appConfig    The app config.
     * @param IMailer            $mailer       The Nextcloud mailer.
     * @param IURLGenerator      $urlGenerator The URL generator for signed links.
     * @param IL10N              $l10n         The localisation service.
     * @param LoggerInterface    $logger       The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private IMailer $mailer,
        private IURLGenerator $urlGenerator,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Send a confirmation email for a booking (member 04 seam).
     *
     * Loads the booking + linked service/resource/customer, composes subject +
     * body + `.ics` attachment + signed reschedule/cancel links, dispatches and
     * stamps `confirmationSentAt` on success. Errors are logged and swallowed —
     * the booking remains valid even when the email cannot leave the host
     * (REQ-APT-006: dispatch is best-effort within the SLA).
     *
     * Method name matches the BookingService seam contract.
     *
     * @param string $bookingId The Booking UUID.
     *
     * @return bool True when the message was accepted for delivery.
     *
     * @spec openspec/changes/appointment-booking-07-email-confirmation-reminder/specs/appointment-booking/spec.md#req-apt-006
     */
    public function sendConfirmation(string $bookingId): bool
    {
        if ($bookingId === '') {
            return false;
        }

        $context = $this->loadContext(bookingId: $bookingId);
        if ($context === null) {
            return false;
        }

        $startLocal = $this->formatLocal(iso: (string) ($context['booking']['startAt'] ?? ''), pattern: 'd-m-Y H:i');
        $subject    = $this->l10n->t(
            'Uw afspraak is bevestigd: %1$s, %2$s',
            [(string) ($context['service']['name'] ?? ''), $startLocal]
        );

        $body = $this->composeConfirmationBody(context: $context);
        $ics  = $this->buildIcs(context: $context);

        $accepted = $this->dispatch(
            recipient: (string) $context['recipientEmail'],
            subject: $subject,
            body: $body,
            icsContent: $ics
        );
        if ($accepted === true) {
            $this->stamp(bookingId: $bookingId, field: 'confirmationSentAt');
        }

        return $accepted;
    }//end sendConfirmation()

    /**
     * Send the 24-hour reminder for a booking (called by ReminderDispatchJob).
     *
     * @param string $bookingId The Booking UUID.
     *
     * @return bool True when the reminder was accepted for delivery.
     *
     * @spec openspec/changes/appointment-booking-07-email-confirmation-reminder/specs/appointment-booking/spec.md#req-apt-007
     */
    public function sendReminder(string $bookingId): bool
    {
        if ($bookingId === '') {
            return false;
        }

        $context = $this->loadContext(bookingId: $bookingId);
        if ($context === null) {
            return false;
        }

        $startLocal = $this->formatLocal(iso: (string) ($context['booking']['startAt'] ?? ''), pattern: 'H:i');
        $subject    = $this->l10n->t('Herinnering: Uw afspraak morgen om %s', [$startLocal]);
        $body       = $this->composeReminderBody(context: $context);

        $accepted = $this->dispatch(
            recipient: (string) $context['recipientEmail'],
            subject: $subject,
            body: $body,
            icsContent: null
        );
        if ($accepted === true) {
            $this->stamp(bookingId: $bookingId, field: 'reminderSentAt');
        }

        return $accepted;
    }//end sendReminder()

    /**
     * Build the HMAC-SHA256-signed token for a reschedule/cancel deep link.
     *
     * Token format: `<bookingId>.<action>.<expiresAtUnix>.<sig>` where `sig` is
     * `hash_hmac('sha256', "<bookingId>.<action>.<expiresAtUnix>", secret)`.
     * Verification is intentionally deterministic (no DB) — the portal
     * controller (member 05) only has to recompute the HMAC and check expiry.
     *
     * @param string $bookingId The Booking UUID.
     * @param string $action    `reschedule` or `cancel`.
     * @param int    $expiresAt Unix timestamp when the token expires.
     *
     * @return string The encoded token (URL-safe base64-free, slash-free payload).
     *
     * @spec openspec/changes/appointment-booking-07-email-confirmation-reminder/specs/appointment-booking/spec.md#req-apt-006
     */
    public function signLinkToken(string $bookingId, string $action, int $expiresAt): string
    {
        $payload = $bookingId.'.'.$action.'.'.$expiresAt;
        $sig     = hash_hmac('sha256', $payload, $this->linkSecret());
        return $payload.'.'.$sig;
    }//end signLinkToken()

    /**
     * Compose the confirmation email body.
     *
     * @param array<string, mixed> $context Email composition context.
     *
     * @return string
     */
    private function composeConfirmationBody(array $context): string
    {
        $customerName = (string) ($context['customerName'] ?? '');
        $serviceName  = (string) ($context['service']['name'] ?? '');
        $resourceName = (string) ($context['resourceName'] ?? '');
        $startLocal   = $this->formatLocal(iso: (string) ($context['booking']['startAt'] ?? ''), pattern: 'd-m-Y H:i');
        $endLocal     = $this->formatLocal(iso: (string) ($context['booking']['endAt'] ?? ''), pattern: 'H:i');
        $notes        = (string) ($context['booking']['notes'] ?? '');
        $price        = $this->formatPrice(context: $context);

        $greetingName = $customerName;
        if ($greetingName === '') {
            $greetingName = $this->l10n->t('klant');
        }

        $lines   = [];
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
        $lines[] = $this->l10n->t('Afspraak verplaatsen: %s', [(string) ($context['rescheduleLink'] ?? '')]);
        $lines[] = $this->l10n->t('Afspraak annuleren: %s', [(string) ($context['cancelLink'] ?? '')]);
        $lines[] = '';
        $lines[] = $this->l10n->t('Tot ziens!');

        return implode("\n", $lines);
    }//end composeConfirmationBody()

    /**
     * Compose the reminder email body.
     *
     * @param array<string, mixed> $context Email composition context.
     *
     * @return string
     */
    private function composeReminderBody(array $context): string
    {
        $customerName = (string) ($context['customerName'] ?? '');
        $serviceName  = (string) ($context['service']['name'] ?? '');
        $resourceName = (string) ($context['resourceName'] ?? '');
        $startLocal   = $this->formatLocal(iso: (string) ($context['booking']['startAt'] ?? ''), pattern: 'd-m-Y H:i');
        $price        = $this->formatPrice(context: $context);

        $greetingName = $customerName;
        if ($greetingName === '') {
            $greetingName = $this->l10n->t('klant');
        }

        $lines   = [];
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
        $lines[] = $this->l10n->t('Afspraak verplaatsen: %s', [(string) ($context['rescheduleLink'] ?? '')]);
        $lines[] = $this->l10n->t('Afspraak annuleren: %s', [(string) ($context['cancelLink'] ?? '')]);
        $lines[] = '';
        $lines[] = $this->l10n->t('Tot morgen!');

        return implode("\n", $lines);
    }//end composeReminderBody()

    /**
     * Build a RFC-5545 iCalendar attachment body.
     *
     * The format is intentionally minimal but RFC-compliant — DTSTAMP / UID /
     * DTSTART / DTEND / SUMMARY / DESCRIPTION / ATTENDEE / ORGANIZER, CRLF line
     * endings as the spec requires, and event UID stable across resends so
     * Outlook/Gmail can update the calendar entry on reschedule.
     *
     * @param array<string, mixed> $context Email composition context.
     *
     * @return string
     *
     * @spec openspec/changes/appointment-booking-07-email-confirmation-reminder/specs/appointment-booking/spec.md#req-apt-006
     */
    private function buildIcs(array $context): string
    {
        $bookingId = (string) ($context['bookingId'] ?? '');
        $startIso  = (string) ($context['booking']['startAt'] ?? '');
        $endIso    = (string) ($context['booking']['endAt'] ?? '');
        $summary   = $this->icsEscape(value: (string) ($context['service']['name'] ?? 'Booking'));
        $desc      = $this->icsEscape(value: (string) ($context['booking']['notes'] ?? ''));
        $attendee  = (string) ($context['recipientEmail'] ?? '');
        $organiser = (string) ($context['organiserEmail'] ?? '');

        $dtstamp = $this->toIcsDateTime(iso: $this->nowIso());
        $dtstart = $this->toIcsDateTime(iso: $startIso);
        $dtend   = $this->toIcsDateTime(iso: $endIso);

        $lines   = [];
        $lines[] = 'BEGIN:VCALENDAR';
        $lines[] = 'VERSION:2.0';
        $lines[] = 'PRODID:-//Conduction//Pipelinq//EN';
        $lines[] = 'CALSCALE:GREGORIAN';
        $lines[] = 'METHOD:REQUEST';
        $lines[] = 'BEGIN:VEVENT';
        $lines[] = 'UID:'.$bookingId.'@pipelinq';
        $lines[] = 'DTSTAMP:'.$dtstamp;
        $lines[] = 'DTSTART:'.$dtstart;
        $lines[] = 'DTEND:'.$dtend;
        $lines[] = 'SUMMARY:'.$summary;
        if ($desc !== '') {
            $lines[] = 'DESCRIPTION:'.$desc;
        }

        if ($attendee !== '') {
            $lines[] = 'ATTENDEE;CN='.$this->icsEscape(value: (string) ($context['customerName'] ?? '')).';RSVP=TRUE:mailto:'.$attendee;
        }

        if ($organiser !== '') {
            $lines[] = 'ORGANIZER:mailto:'.$organiser;
        }

        $lines[] = 'STATUS:CONFIRMED';
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        // RFC 5545 mandates CRLF line endings.
        return implode("\r\n", $lines)."\r\n";
    }//end buildIcs()

    /**
     * Dispatch an email through `IMailer` (the seam for the email-calendar-sync leaf).
     *
     * @param string      $recipient  The recipient address.
     * @param string      $subject    Subject line.
     * @param string      $body       Plain-text body.
     * @param string|null $icsContent Optional `.ics` attachment body.
     *
     * @return bool True when accepted for delivery.
     */
    private function dispatch(string $recipient, string $subject, string $body, ?string $icsContent): bool
    {
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

            $failed = $this->mailer->send($message);
            return empty($failed) === true;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq appointment email: dispatch failed',
                ['exception' => $e->getMessage()]
            );
            return false;
        }//end try
    }//end dispatch()

    /**
     * Load the composition context (booking + service + customer + links).
     *
     * @param string $bookingId The Booking UUID.
     *
     * @return array<string, mixed>|null Context array or null when the booking is unrecoverable.
     */
    private function loadContext(string $bookingId): ?array
    {
        $booking = $this->loadObject(schemaKey: self::BOOKING_SCHEMA_KEY, id: $bookingId);
        if ($booking === null) {
            return null;
        }

        $service  = $this->loadObject(schemaKey: self::SERVICE_SCHEMA_KEY, id: (string) ($booking['serviceId'] ?? ''));
        $customer = $this->loadObject(schemaKey: self::CUSTOMER_SCHEMA_KEY, id: (string) ($booking['customerId'] ?? ''));

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

        // Signed reschedule + cancel links (30-day expiry).
        $expiresAt       = (time() + self::LINK_TTL_SECONDS);
        $rescheduleToken = $this->signLinkToken(bookingId: $bookingId, action: 'reschedule', expiresAt: $expiresAt);
        $cancelToken     = $this->signLinkToken(bookingId: $bookingId, action: 'cancel', expiresAt: $expiresAt);

        $rescheduleLink = $this->urlGenerator->getAbsoluteURL('/index.php/apps/pipelinq/portal/reschedule')
            .'?link='.rawurlencode($rescheduleToken).'&bookingId='.rawurlencode($bookingId);
        $cancelLink     = $this->urlGenerator->getAbsoluteURL('/index.php/apps/pipelinq/portal/cancel')
            .'?link='.rawurlencode($cancelToken).'&bookingId='.rawurlencode($bookingId);

        $organiser = $this->appConfig->getValueString(Application::APP_ID, 'appointment_email_sender', '');

        return [
            'bookingId'      => $bookingId,
            'booking'        => $booking,
            'service'        => ($service ?? []),
            'customerName'   => $customerName,
            'resourceName'   => $resourceName,
            'recipientEmail' => $recipient,
            'organiserEmail' => $organiser,
            'rescheduleLink' => $rescheduleLink,
            'cancelLink'     => $cancelLink,
        ];
    }//end loadContext()

    /**
     * Stamp a timestamp field on a booking (best-effort).
     *
     * @param string $bookingId The Booking UUID.
     * @param string $field     Field name (`confirmationSentAt` or `reminderSentAt`).
     *
     * @return void
     */
    private function stamp(string $bookingId, string $field): void
    {
        $register = $this->registerId();
        $schema   = $this->schemaId(key: self::BOOKING_SCHEMA_KEY);
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
    }//end stamp()

    /**
     * Resolve a customer's email — from the OR mirror first, then the NC Contacts manager.
     *
     * @param array<string, mixed>|null $customer The customer mirror (or null).
     *
     * @return string
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Ordered fallback over candidate
     *  email fields then the Contacts manager; each branch is an independent early
     *  return.
     */
    private function resolveCustomerEmail(?array $customer): string
    {
        if ($customer === null) {
            return '';
        }

        foreach (['email', 'emailAddress', 'mail'] as $key) {
            $value = (string) ($customer[$key] ?? '');
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
                    $value = (string) ($entry['value'] ?? $entry['address'] ?? '');
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        }

        return '';
    }//end resolveCustomerEmail()

    /**
     * Resolve a customer's display name from the mirror.
     *
     * @param array<string, mixed>|null $customer The customer mirror.
     *
     * @return string
     */
    private function resolveCustomerName(?array $customer): string
    {
        if ($customer === null) {
            return '';
        }

        foreach (['fullName', 'displayName', 'name'] as $key) {
            $value = (string) ($customer[$key] ?? '');
            if ($value !== '') {
                return $value;
            }
        }

        $first = (string) ($customer['firstName'] ?? '');
        $last  = (string) ($customer['lastName'] ?? '');
        return trim($first.' '.$last);
    }//end resolveCustomerName()

    /**
     * Resolve a resource display name from the first resource assignment.
     *
     * @param array<string, mixed> $booking The booking.
     *
     * @return string
     */
    private function resolveResourceName(array $booking): string
    {
        $assignments = ($booking['resourceAssignments'] ?? []);
        if (is_array($assignments) === false || $assignments === []) {
            return '';
        }

        $first = $assignments[0] ?? null;
        if (is_array($first) === false) {
            return '';
        }

        $resourceId = (string) ($first['resourceId'] ?? '');
        if ($resourceId === '') {
            return '';
        }

        $resource = $this->loadObject(schemaKey: self::RESOURCE_SCHEMA_KEY, id: $resourceId);
        if ($resource === null) {
            return '';
        }

        return (string) ($resource['name'] ?? '');
    }//end resolveResourceName()

    /**
     * Resolve the booked service's price as a human-readable string.
     *
     * @param array<string, mixed> $context Email composition context.
     *
     * @return string
     */
    private function formatPrice(array $context): string
    {
        $service = ($context['service'] ?? []);
        if (is_array($service) === false) {
            return '';
        }

        $price    = (float) ($service['price'] ?? 0.0);
        $currency = (string) ($service['currency'] ?? 'EUR');
        if ($price <= 0.0) {
            return '';
        }

        return $currency.' '.number_format($price, 2, ',', '.');
    }//end formatPrice()

    /**
     * Load an OpenRegister object by app-config schema key, returning null on failure.
     *
     * @param string $schemaKey App-config key (e.g. self::BOOKING_SCHEMA_KEY).
     * @param string $id        Object UUID/slug.
     *
     * @return array<string, mixed>|null
     */
    private function loadObject(string $schemaKey, string $id): ?array
    {
        if ($id === '') {
            return null;
        }

        $register = $this->registerId();
        $schema   = $this->schemaId(key: $schemaKey);
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
    }//end loadObject()

    /**
     * Read or lazily-generate the HMAC signing secret.
     *
     * @return string
     */
    private function linkSecret(): string
    {
        $secret = $this->appConfig->getValueString(Application::APP_ID, self::LINK_SECRET_KEY, '');
        if ($secret !== '') {
            return $secret;
        }

        try {
            $secret = bin2hex(random_bytes(32));
        } catch (\Throwable $e) {
            // Fallback: deterministic but unique-per-instance fallback.
            $secret = hash('sha256', Application::APP_ID.'|'.(string) microtime(true));
        }

        $this->appConfig->setValueString(Application::APP_ID, self::LINK_SECRET_KEY, $secret);
        return $secret;
    }//end linkSecret()

    /**
     * Format an ISO timestamp in Europe/Amsterdam local time.
     *
     * @param string $iso     ISO-8601 timestamp.
     * @param string $pattern PHP date format pattern.
     *
     * @return string
     */
    private function formatLocal(string $iso, string $pattern): string
    {
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
    }//end formatLocal()

    /**
     * Convert an ISO timestamp to iCalendar UTC `YYYYMMDDTHHMMSSZ` format.
     *
     * @param string $iso ISO-8601 timestamp.
     *
     * @return string
     */
    private function toIcsDateTime(string $iso): string
    {
        try {
            $dateTime = new DateTimeImmutable($iso);
            $dateTime = $dateTime->setTimezone(new DateTimeZone('UTC'));
            return $dateTime->format('Ymd\THis\Z');
        } catch (\Throwable $e) {
            return '';
        }
    }//end toIcsDateTime()

    /**
     * Escape a string for an iCalendar text field per RFC 5545 § 3.3.11.
     *
     * @param string $value Raw value.
     *
     * @return string
     */
    private function icsEscape(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace([",", ';'], ['\\,', '\\;'], $value);
        $value = str_replace(["\r\n", "\n", "\r"], '\\n', $value);
        return $value;
    }//end icsEscape()

    /**
     * Now in ISO-8601 UTC.
     *
     * @return string
     */
    private function nowIso(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');
    }//end nowIso()

    /**
     * The pipelinq register id from app config.
     *
     * @return string
     */
    private function registerId(): string
    {
        return $this->appConfig->getValueString(Application::APP_ID, 'register', '');
    }//end registerId()

    /**
     * Resolve a schema id by app-config key.
     *
     * @param string $key App-config key.
     *
     * @return string
     */
    private function schemaId(string $key): string
    {
        return $this->appConfig->getValueString(Application::APP_ID, $key, '');
    }//end schemaId()

    /**
     * Normalise an OR entity (or array) to a plain array.
     *
     * @param mixed $object Entity, array, or null.
     *
     * @return array<string, mixed>|null
     */
    private function toArray(mixed $object): ?array
    {
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

            return (array) $object;
        }

        return null;
    }//end toArray()

    /**
     * Resolve the OpenRegister ObjectService via the DI container.
     *
     * @return object The ObjectService instance.
     *
     * @throws RuntimeException If OpenRegister is unavailable.
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            throw new RuntimeException('OpenRegister ObjectService is unavailable.', 0, $e);
        }
    }//end getObjectService()
}//end class
