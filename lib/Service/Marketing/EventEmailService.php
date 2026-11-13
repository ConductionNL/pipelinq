<?php

/**
 * Pipelinq EventEmailService.
 *
 * The mails a person gets for a campaign event: a confirmation with a calendar
 * file when they have a place, a waiting list notice without one when they do
 * not, a mail with the calendar file when a place comes free for them, a notice
 * when registration has closed, and a reminder the day before with the join
 * link of an online event. Every mail to a registered or waiting person carries
 * a signed link to cancel.
 *
 * The cancel link is signed like the appointment links
 * (`hash_hmac('sha256', "<id>.<action>.<expiresAt>", secret)`), with its own
 * action name and its own per-instance secret, and it expires when the event
 * starts. Verifying it needs no stored state.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-attendees-get-a-confirmation-a-reminder-and-a-way-to-cancel-req-mev-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Compose and send the mails of a campaign event.
 *
 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-attendees-get-a-confirmation-a-reminder-and-a-way-to-cancel-req-mev-003
 */
class EventEmailService {

	/**
	 * The signed-link action name of a cancel link.
	 */
	public const CANCEL_ACTION = 'event-cancel';

	/**
	 * App-config key of the signing secret.
	 */
	private const SECRET_KEY = 'event_link_secret';

	/**
	 * App-config key of an optional sender address.
	 */
	private const SENDER_KEY = 'event_email_sender';

	/**
	 * The public cancel page route.
	 */
	private const CANCEL_ROUTE = 'pipelinq.eventCancel.show';

	/**
	 * Constructor.
	 *
	 * @param IMailer         $mailer    Sends the mail.
	 * @param IL10N           $l10n      Translates the mail text.
	 * @param IAppConfig      $appConfig Holds the signing secret and the sender.
	 * @param IURLGenerator   $urls      Builds the cancel link.
	 * @param ITimeFactory    $time      The clock.
	 * @param LoggerInterface $logger    The logger.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-attendees-get-a-confirmation-a-reminder-and-a-way-to-cancel-req-mev-003
	 */
	public function __construct(
		private IMailer $mailer,
		private IL10N $l10n,
		private IAppConfig $appConfig,
		private IURLGenerator $urls,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Confirm a registration: a place gets a calendar file, the waiting list does not.
	 *
	 * @param array<string, mixed> $event        The event.
	 * @param array<string, mixed> $registration The registration.
	 *
	 * @return bool True when the mail was accepted for delivery.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-attendees-get-a-confirmation-a-reminder-and-a-way-to-cancel-req-mev-003
	 */
	public function sendConfirmation(array $event, array $registration): bool {
		$title = $this->title(event: $event);
		if ((string)($registration['status'] ?? '') === 'waitlist') {
			$body = $this->l10n->t(
				'Thank you for registering for %1$s on %2$s. The event is full, so you are on the waiting list at place %3$s. '
				. 'We will mail you as soon as a place comes free.',
				[$title, $this->when(event: $event), (string)($registration['waitlistPosition'] ?? '')]
			);
			return $this->dispatch(
				registration: $registration,
				subject: $this->l10n->t('You are on the waiting list for %s', [$title]),
				body: $this->withCancelLink(body: $body, event: $event, registration: $registration),
				ics: null
			);
		}

		$body = $this->l10n->t('You are registered for %1$s on %2$s.', [$title, $this->when(event: $event)]);
		return $this->dispatch(
			registration: $registration,
			subject: $this->l10n->t('Your registration for %s', [$title]),
			body: $this->withCancelLink(body: $this->withPlace(body: $body, event: $event), event: $event, registration: $registration),
			ics: $this->ics(event: $event, registration: $registration)
		);
	}//end sendConfirmation()

	/**
	 * Tell someone who was waiting that they have a place now.
	 *
	 * @param array<string, mixed> $event        The event.
	 * @param array<string, mixed> $registration The registration that moved up.
	 *
	 * @return bool True when the mail was accepted for delivery.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-attendees-get-a-confirmation-a-reminder-and-a-way-to-cancel-req-mev-003
	 */
	public function sendPlaceFreed(array $event, array $registration): bool {
		$title = $this->title(event: $event);
		$body = $this->l10n->t(
			'Good news: a place has come free at %1$s on %2$s, and it is yours.',
			[$title, $this->when(event: $event)]
		);
		return $this->dispatch(
			registration: $registration,
			subject: $this->l10n->t('You have a place at %s', [$title]),
			body: $this->withCancelLink(body: $this->withPlace(body: $body, event: $event), event: $event, registration: $registration),
			ics: $this->ics(event: $event, registration: $registration)
		);
	}//end sendPlaceFreed()

	/**
	 * Tell someone who signed up that registration has closed.
	 *
	 * @param array<string, mixed> $event  The event.
	 * @param array<string, mixed> $person `name` and `email`.
	 *
	 * @return bool True when the mail was accepted for delivery.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-a-landing-page-sign-up-registers-for-the-event-req-mev-002
	 */
	public function sendClosed(array $event, array $person): bool {
		$title = $this->title(event: $event);
		return $this->dispatch(
			registration: $person,
			subject: $this->l10n->t('Registration for %s has closed', [$title]),
			body: $this->l10n->t('Thank you for your interest in %s. Registration has closed, so we could not register you.', [$title]),
			ics: null
		);
	}//end sendClosed()

	/**
	 * Remind a registered person the day before, with the join link of an online event.
	 *
	 * @param array<string, mixed> $event        The event.
	 * @param array<string, mixed> $registration The registration.
	 *
	 * @return bool True when the mail was accepted for delivery.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-attendees-get-a-confirmation-a-reminder-and-a-way-to-cancel-req-mev-003
	 */
	public function sendReminder(array $event, array $registration): bool {
		$title = $this->title(event: $event);
		$body = $this->l10n->t('A reminder: %1$s is on %2$s.', [$title, $this->when(event: $event)]);
		return $this->dispatch(
			registration: $registration,
			subject: $this->l10n->t('Reminder: %s', [$title]),
			body: $this->withCancelLink(body: $this->withPlace(body: $body, event: $event), event: $event, registration: $registration),
			ics: null
		);
	}//end sendReminder()

	/**
	 * The signed cancel token for a registration.
	 *
	 * @param string $registrationId The registration id.
	 * @param int    $expiresAt      Unix time the token stops working.
	 *
	 * @return string `<id>.<action>.<expiresAt>.<signature>`.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-attendees-get-a-confirmation-a-reminder-and-a-way-to-cancel-req-mev-003
	 */
	public function cancelToken(string $registrationId, int $expiresAt): string {
		$payload = $registrationId . '.' . self::CANCEL_ACTION . '.' . $expiresAt;
		return $payload . '.' . hash_hmac('sha256', $payload, $this->secret());
	}//end cancelToken()

	/**
	 * The registration id a cancel token was signed for, when it is genuine and unexpired.
	 *
	 * @param string $token The token.
	 *
	 * @return string|null The registration id, or null.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-attendees-get-a-confirmation-a-reminder-and-a-way-to-cancel-req-mev-003
	 */
	public function verifyCancelToken(string $token): ?string {
		$parts = explode('.', trim($token));
		if (count($parts) !== 4) {
			return null;
		}

		[$id, $action, $expiresAt, $signature] = $parts;
		if ($id === '' || $action !== self::CANCEL_ACTION || ctype_digit($expiresAt) === false) {
			return null;
		}

		$expected = hash_hmac('sha256', $id . '.' . $action . '.' . $expiresAt, $this->secret());
		if (hash_equals($expected, $signature) === false) {
			return null;
		}

		if ((int)$expiresAt <= $this->time->getTime()) {
			return null;
		}

		return $id;
	}//end verifyCancelToken()

	/**
	 * The absolute cancel link of a registration, valid until the event starts.
	 *
	 * @param array<string, mixed> $event        The event.
	 * @param array<string, mixed> $registration The registration.
	 *
	 * @return string The link, or '' when the registration has no id.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-attendees-get-a-confirmation-a-reminder-and-a-way-to-cancel-req-mev-003
	 */
	public function cancelUrl(array $event, array $registration): string {
		$id = (string)($registration['uuid'] ?? $registration['id'] ?? '');
		if ($id === '') {
			return '';
		}

		$expiresAt = strtotime((string)($event['startAt'] ?? ''));
		if ($expiresAt === false) {
			$expiresAt = ($this->time->getTime() + (30 * 86400));
		}

		return $this->urls->linkToRouteAbsolute(self::CANCEL_ROUTE, ['token' => $this->cancelToken(registrationId: $id, expiresAt: $expiresAt)]);
	}//end cancelUrl()

	/**
	 * The RFC 5545 calendar file of an event, for one attendee.
	 *
	 * @param array<string, mixed> $event        The event.
	 * @param array<string, mixed> $registration The registration.
	 *
	 * @return string The `.ics` body, CRLF line endings.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-attendees-get-a-confirmation-a-reminder-and-a-way-to-cancel-req-mev-003
	 */
	public function ics(array $event, array $registration): string {
		$eventId = (string)($event['uuid'] ?? $event['id'] ?? '');
		$start = (string)($event['startAt'] ?? '');
		$end = (string)($event['endAt'] ?? '');
		if ($end === '') {
			$end = $start;
		}

		$lines = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Conduction//Pipelinq//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:REQUEST',
			'BEGIN:VEVENT',
			'UID:event-' . $eventId . '-' . (string)($registration['uuid'] ?? $registration['id'] ?? '') . '@pipelinq',
			'DTSTAMP:' . gmdate('Ymd\THis\Z', $this->time->getTime()),
			'DTSTART:' . $this->icsTime(iso: $start),
			'DTEND:' . $this->icsTime(iso: $end),
			'SUMMARY:' . $this->icsEscape(value: $this->title(event: $event)),
		];

		$location = $this->location(event: $event);
		if ($location !== '') {
			$lines[] = 'LOCATION:' . $this->icsEscape(value: $location);
		}

		$email = trim((string)($registration['email'] ?? ''));
		if ($email !== '') {
			$lines[] = 'ATTENDEE;CN=' . $this->icsEscape(value: (string)($registration['name'] ?? '')) . ';RSVP=FALSE:mailto:' . $email;
		}

		$lines[] = 'STATUS:CONFIRMED';
		$lines[] = 'END:VEVENT';
		$lines[] = 'END:VCALENDAR';

		return implode("\r\n", $lines) . "\r\n";
	}//end ics()

	/**
	 * Add where the event is (or its join link) to a body.
	 *
	 * @param string               $body  The body.
	 * @param array<string, mixed> $event The event.
	 *
	 * @return string The body.
	 */
	private function withPlace(string $body, array $event): string {
		$joinUrl = trim((string)($event['joinUrl'] ?? ''));
		if (($event['online'] ?? false) === true && $joinUrl !== '') {
			return $body . "\n\n" . $this->l10n->t('Join online: %s', [$joinUrl]);
		}

		$location = $this->location(event: $event);
		if ($location !== '') {
			return $body . "\n\n" . $this->l10n->t('Location: %s', [$location]);
		}

		return $body;
	}//end withPlace()

	/**
	 * Add the cancel link to a body.
	 *
	 * @param string               $body         The body.
	 * @param array<string, mixed> $event        The event.
	 * @param array<string, mixed> $registration The registration.
	 *
	 * @return string The body.
	 */
	private function withCancelLink(string $body, array $event, array $registration): string {
		$url = $this->cancelUrl(event: $event, registration: $registration);
		if ($url === '') {
			return $body;
		}

		return $body . "\n\n" . $this->l10n->t('Can you not come? Cancel your registration: %s', [$url]);
	}//end withCancelLink()

	/**
	 * Send one mail.
	 *
	 * @param array<string, mixed> $registration Who it goes to (`email`).
	 * @param string               $subject      The subject.
	 * @param string               $body         The plain-text body.
	 * @param string|null          $ics          The calendar file, or null.
	 *
	 * @return bool True when the mail was accepted for delivery.
	 */
	private function dispatch(array $registration, string $subject, string $body, ?string $ics): bool {
		$address = trim((string)($registration['email'] ?? ''));
		if ($address === '' || $this->mailer->validateMailAddress($address) === false) {
			return false;
		}

		try {
			$message = $this->mailer->createMessage();
			$message->setTo([$address]);
			$message->setSubject($subject);
			$message->setPlainBody($body);
			$message->setHtmlBody(nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')));
			if ($ics !== null) {
				$message->attach($this->mailer->createAttachment($ics, 'event.ics', 'text/calendar; method=REQUEST; charset=UTF-8'));
			}

			$sender = $this->appConfig->getValueString(Application::APP_ID, self::SENDER_KEY, '');
			if ($sender !== '' && $this->mailer->validateMailAddress($sender) === true) {
				$message->setFrom([$sender]);
			}

			return empty($this->mailer->send($message)) === true;
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: an event mail could not be sent',
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end dispatch()

	/**
	 * The signing secret, created on first use.
	 *
	 * @return string The secret.
	 */
	private function secret(): string {
		$secret = $this->appConfig->getValueString(Application::APP_ID, self::SECRET_KEY, '');
		if ($secret !== '') {
			return $secret;
		}

		$secret = bin2hex(random_bytes(32));
		$this->appConfig->setValueString(Application::APP_ID, self::SECRET_KEY, $secret, sensitive: true);
		return $secret;
	}//end secret()

	/**
	 * The event title.
	 *
	 * @param array<string, mixed> $event The event.
	 *
	 * @return string The title.
	 */
	private function title(array $event): string {
		return trim((string)($event['title'] ?? ''));
	}//end title()

	/**
	 * The venue and room.
	 *
	 * @param array<string, mixed> $event The event.
	 *
	 * @return string The location, or ''.
	 */
	private function location(array $event): string {
		$parts = array_filter(
			[trim((string)($event['venue'] ?? '')), trim((string)($event['room'] ?? ''))],
			static fn (string $part): bool => $part !== ''
		);
		return implode(', ', $parts);
	}//end location()

	/**
	 * When the event starts, in Dutch local time.
	 *
	 * @param array<string, mixed> $event The event.
	 *
	 * @return string The date and time, or the raw value when unreadable.
	 */
	private function when(array $event): string {
		$start = trim((string)($event['startAt'] ?? ''));
		if ($start === '') {
			return '';
		}

		try {
			return (new DateTimeImmutable($start))->setTimezone(new DateTimeZone('Europe/Amsterdam'))->format('d-m-Y H:i');
		} catch (Throwable $e) {
			return $start;
		}
	}//end when()

	/**
	 * An ISO time as iCalendar UTC.
	 *
	 * @param string $iso The time.
	 *
	 * @return string `YYYYMMDDTHHMMSSZ`, or '' when unreadable.
	 */
	private function icsTime(string $iso): string {
		try {
			return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
		} catch (Throwable $e) {
			return '';
		}
	}//end icsTime()

	/**
	 * Escape an iCalendar text value (RFC 5545 3.3.11).
	 *
	 * @param string $value The value.
	 *
	 * @return string The escaped value.
	 */
	private function icsEscape(string $value): string {
		return str_replace(['\\', ',', ';', "\r\n", "\n", "\r"], ['\\\\', '\\,', '\\;', '\\n', '\\n', '\\n'], $value);
	}//end icsEscape()
}//end class
