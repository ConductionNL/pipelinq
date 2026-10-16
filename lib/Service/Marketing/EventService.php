<?php

/**
 * Pipelinq EventService.
 *
 * The two actions on an event page: a reminder to everyone with a place,
 * and checking a registration in at the door.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/public-events/specs/public-events/spec.md#requirement-a-reminder-goes-to-everyone-with-a-place
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

use OCA\Pipelinq\Service\EmailFallbackSender;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * EventService: the reminder and the door check-in of a public event.
 *
 * @spec openspec/changes/public-events/specs/public-events/spec.md#requirement-a-reminder-goes-to-everyone-with-a-place
 */
class EventService {

	/**
	 * The event schema slug.
	 *
	 * @var string
	 */
	public const EVENT_SCHEMA = 'event';

	/**
	 * The registration schema slug.
	 *
	 * @var string
	 */
	public const REGISTRATION_SCHEMA = 'eventRegistration';

	/**
	 * Constructor.
	 *
	 * @param ListObjectStore     $store  Register-scoped object plumbing.
	 * @param EmailFallbackSender $mail   The service mail path, which asks integriq before each send.
	 * @param ITimeFactory        $time   Clock.
	 * @param IL10N               $l10n   Translations for the reminder text.
	 * @param LoggerInterface     $logger Logger.
	 *
	 * @spec openspec/changes/public-events/specs/public-events/spec.md#requirement-a-reminder-goes-to-everyone-with-a-place
	 */
	public function __construct(
		private ListObjectStore $store,
		private EmailFallbackSender $mail,
		private ITimeFactory $time,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Mail a reminder to every confirmed registration of an event.
	 *
	 * @param string $eventId Event UUID.
	 *
	 * @return array{sent:int,refused:int,skipped:int,failed:int}|null The tally, or null when the event does not exist.
	 *
	 * @spec openspec/changes/public-events/specs/public-events/spec.md#requirement-a-reminder-goes-to-everyone-with-a-place
	 */
	public function sendReminder(string $eventId): ?array {
		$eventSchema = $this->store->schemaSlug('event_schema', self::EVENT_SCHEMA);
		$event = $this->store->find(schemaSlug: $eventSchema, id: $eventId);
		if ($event === null) {
			return null;
		}

		$tally = ['sent' => 0, 'refused' => 0, 'skipped' => 0, 'failed' => 0];
		$message = $this->reminderFor(event: $event);
		foreach ($this->registrationsOf(eventId: $eventId) as $registration) {
			if ((string)($registration['status'] ?? '') !== 'confirmed') {
				continue;
			}

			$address = trim((string)($registration['email'] ?? ''));
			if ($address === '') {
				$tally['skipped']++;
				continue;
			}

			$tally[$this->deliver(message: $message, address: $address)]++;
		}

		$event['reminderSentAt'] = date('c', $this->time->getTime());
		$this->store->save(schemaSlug: $eventSchema, payload: $event, id: $eventId);

		return $tally;
	}//end sendReminder()

	/**
	 * Check a registration in at the door.
	 *
	 * @param string $registrationId Registration UUID.
	 *
	 * @return array{outcome:string,registration?:array<string,mixed>} `checked_in`, `already`, `cancelled` or `not_found`.
	 *
	 * @spec openspec/changes/public-events/specs/public-events/spec.md#requirement-a-registration-is-checked-in-at-the-door
	 */
	public function checkIn(string $registrationId): array {
		$schema = $this->store->schemaSlug('eventRegistration_schema', self::REGISTRATION_SCHEMA);
		$registration = $this->store->find(schemaSlug: $schema, id: $registrationId);
		if ($registration === null) {
			return ['outcome' => 'not_found'];
		}

		if ((string)($registration['status'] ?? '') === 'cancelled') {
			return ['outcome' => 'cancelled', 'registration' => $registration];
		}

		if ((string)($registration['checkedInAt'] ?? '') !== '') {
			return ['outcome' => 'already', 'registration' => $registration];
		}

		$registration['checkedInAt'] = date('c', $this->time->getTime());
		$saved = $this->store->save(schemaSlug: $schema, payload: $registration, id: $registrationId);

		return ['outcome' => 'checked_in', 'registration' => ($saved ?? $registration)];
	}//end checkIn()

	/**
	 * The registrations that point at one event.
	 *
	 * @param string $eventId Event UUID.
	 *
	 * @return array<int, array<string, mixed>> The registrations.
	 */
	private function registrationsOf(string $eventId): array {
		$schema = $this->store->schemaSlug('eventRegistration_schema', self::REGISTRATION_SCHEMA);
		$found = [];
		foreach ($this->store->findAll(schemaSlug: $schema, filters: ['eventId' => $eventId]) as $registration) {
			if ((string)($registration['eventId'] ?? '') === $eventId) {
				$found[] = $registration;
			}
		}

		return $found;
	}//end registrationsOf()

	/**
	 * The reminder mail for one event.
	 *
	 * @param array<string, mixed> $event The event.
	 *
	 * @return array{subject:string,body:string,category:string} The message.
	 */
	private function reminderFor(array $event): array {
		$name = (string)($event['name'] ?? '');
		$when = (string)($event['startsAt'] ?? '');
		$timestamp = strtotime($when);
		if ($timestamp !== false) {
			$when = date('d-m-Y H:i', $timestamp);
		}

		$where = (string)($event['location'] ?? '');
		$body = $this->l10n->t('You are registered for %1$s on %2$s at %3$s. We look forward to seeing you.', [$name, $when, $where]);

		return [
			'subject'  => $this->l10n->t('Reminder: %s', [$name]),
			'body'     => '<p>' . htmlspecialchars($body, ENT_XHTML, 'UTF-8') . '</p>',
			'category' => 'service',
		];
	}//end reminderFor()

	/**
	 * Send one reminder and say how it went.
	 *
	 * @param array<string, string> $message The message.
	 * @param string                $address The recipient.
	 *
	 * @return string `sent`, `refused` or `failed`.
	 */
	private function deliver(array $message, string $address): string {
		try {
			if ($this->mail->send(message: $message, toEmail: $address, appendNotice: false) === true) {
				return 'sent';
			}
		} catch (RuntimeException $e) {
			$this->logger->warning('Event reminder not sent.', ['exception' => $e->getMessage()]);
			return 'failed';
		}

		if ($this->mail->lastRefusal() !== null) {
			return 'refused';
		}

		return 'failed';
	}//end deliver()
}//end class
