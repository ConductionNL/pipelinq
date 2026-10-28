<?php

/**
 * Pipelinq ActivityItemNormalizer.
 *
 * Turns a row of one timeline source (a contact moment ticket, a task, a mail
 * or calendar link, a note, a WhatsApp/SMS message) into the unified activity
 * item the timeline returns, with its author's display name.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/activity-timeline/spec.md#requirement-activity-events-must-cover-all-entity-types
 * @spec openspec/changes/client-activity-card/specs/activity-timeline/spec.md#requirement-the-timeline-names-authors-by-display-name
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTime;
use OCP\IDateTimeFormatter;
use OCP\IUserManager;
use Throwable;

/**
 * Normalises timeline source rows into activity items.
 *
 * @spec openspec/specs/activity-timeline/spec.md#requirement-activity-events-must-cover-all-entity-types
 */
class ActivityItemNormalizer {
	/**
	 * Constructor.
	 *
	 * @param IUserManager $userManager Resolves an author's display name.
	 * @param IDateTimeFormatter $dateTimeFormatter Writes a booking's time in the reader's language.
	 */
	public function __construct(
		private readonly IUserManager $userManager,
		private readonly IDateTimeFormatter $dateTimeFormatter,
	) {
	}//end __construct()

	/**
	 * Normalise a raw row into the unified activity item shape. Every item
	 * carries `userName`, its author's display name (the user id when the user
	 * is unknown).
	 *
	 * @param string $sourceType The internal source-type key (interaction|task|emailLink|calendarLink|note|message).
	 * @param array<string,mixed> $object The raw row.
	 * @param string $entityType The originating entityType for back-reference.
	 * @param string $entityId The originating entityId for back-reference.
	 *
	 * @return array<string,mixed>|null The normalised item, or null if it should be skipped.
	 *
	 * @spec openspec/changes/client-activity-card/specs/activity-timeline/spec.md#requirement-the-timeline-names-authors-by-display-name
	 */
	public function normalize(string $sourceType, array $object, string $entityType, string $entityId): ?array {
		$item = $this->normalizeBySource(sourceType: $sourceType, object: $object, entityType: $entityType, entityId: $entityId);
		if ($item === null) {
			return null;
		}

		$item['userName'] ??= $this->displayName(userId: $item['user']);
		return $item;
	}//end normalize()

	/**
	 * The display name of a user, the id itself when the user is unknown.
	 *
	 * @param string|null $userId The user id.
	 *
	 * @return string|null The display name, or null without a user.
	 */
	private function displayName(?string $userId): ?string {
		if ($userId === null) {
			return null;
		}

		return $this->userManager->get($userId)?->getDisplayName() ?? $userId;
	}//end displayName()

	/**
	 * Normalise a raw note (a comment on the record) into an activity item.
	 *
	 * @param array<string,mixed> $note The note as NotesService reads it.
	 * @param string $entityType The originating entityType.
	 * @param string $entityId The originating entityId.
	 *
	 * @return array<string,mixed> The activity item.
	 *
	 * @spec openspec/changes/client-activity-card/specs/activity-timeline/spec.md#requirement-the-timeline-lists-notes-and-messages
	 */
	private function normalizeNote(array $note, string $entityType, string $entityId): array {
		return [
			'type' => 'note',
			'id' => (string)($note['id'] ?? ''),
			'title' => (string)($note['message'] ?? ''),
			'description' => '',
			'date' => $this->stringOrNull(value: ($note['timestamp'] ?? null)),
			'user' => $this->stringOrNull(value: ($note['authorId'] ?? null)),
			'userName' => $this->stringOrNull(value: ($note['authorName'] ?? null)),
			'entityType' => $entityType,
			'entityId' => $entityId,
			'metadata' => [],
		];
	}//end normalizeNote()

	/**
	 * Normalise a raw WhatsApp/SMS message into an activity item.
	 *
	 * @param array<string,mixed> $message The channelMessage object.
	 * @param string $entityType The originating entityType.
	 * @param string $entityId The originating entityId.
	 *
	 * @return array<string,mixed> The activity item.
	 *
	 * @spec openspec/changes/client-activity-card/specs/activity-timeline/spec.md#requirement-the-timeline-lists-notes-and-messages
	 */
	private function normalizeMessage(array $message, string $entityType, string $entityId): array {
		return [
			'type' => 'message',
			'id' => (string)($message['id'] ?? $message['uuid'] ?? ''),
			'title' => (string)($message['body'] ?? ''),
			'description' => '',
			'date' => $this->stringOrNull(value: ($message['sentAt'] ?? null)),
			'user' => null,
			'entityType' => $entityType,
			'entityId' => $entityId,
			'metadata' => [
				'channel' => $this->stringOrNull(value: ($message['channel'] ?? null)),
				'direction' => $this->stringOrNull(value: ($message['direction'] ?? null)),
				'deliveryStatus' => $this->stringOrNull(value: ($message['deliveryStatus'] ?? null)),
			],
		];
	}//end normalizeMessage()

	/**
	 * Normalise an appointment booking into a calendar activity. It is dated
	 * the day it was booked; its title is the booking's subject (else its
	 * service's name) and the appointment's time.
	 *
	 * @param array<string,mixed> $booking The appointmentBooking object, with `serviceName`.
	 * @param string $entityType The originating entityType.
	 * @param string $entityId The originating entityId.
	 *
	 * @return array<string,mixed> The activity item.
	 *
	 * @spec openspec/changes/client-activity-card/specs/activity-timeline/spec.md#requirement-the-timeline-lists-bookings-and-linked-mail
	 */
	private function normalizeBooking(array $booking, string $entityType, string $entityId): array {
		$subject = $this->stringOrNull(value: ($booking['subject'] ?? $booking['title'] ?? $booking['serviceName'] ?? null));
		$startAt = $this->stringOrNull(value: ($booking['startAt'] ?? null));
		$bookedAt = $this->stringOrNull(value: ($booking['statusHistory'][0]['changedAt'] ?? null)) ?? $startAt;
		$bookedOn = null;
		if ($bookedAt !== null) {
			$bookedOn = substr($bookedAt, 0, 10);
		}

		return [
			'type' => 'calendar',
			'id' => (string)($booking['id'] ?? $booking['uuid'] ?? ''),
			'title' => implode(', ', array_filter([$subject, $this->formatMoment(moment: $startAt)])),
			'description' => '',
			'date' => $bookedOn,
			'user' => null,
			'entityType' => $entityType,
			'entityId' => $entityId,
			'metadata' => [
				'startAt' => $startAt,
				'status' => $this->stringOrNull(value: ($booking['status'] ?? null)),
			],
		];
	}//end normalizeBooking()

	/**
	 * A moment as date and time in the reader's language, or null.
	 *
	 * @param string|null $moment An ISO 8601 moment.
	 *
	 * @return string|null The written moment.
	 */
	private function formatMoment(?string $moment): ?string {
		if ($moment === null) {
			return null;
		}

		try {
			return $this->dateTimeFormatter->formatDateTime(new DateTime($moment), 'long', 'short');
		} catch (Throwable) {
			return $moment;
		}
	}//end formatMoment()

	/**
	 * Normalise a raw object of one source into the unified activity item shape.
	 *
	 * @param string $sourceType The internal source-type key.
	 * @param array<string,mixed> $object The raw object array.
	 * @param string $entityType The originating entityType for back-reference.
	 * @param string $entityId The originating entityId for back-reference.
	 *
	 * @return array<string,mixed>|null The normalised item, or null if it should be skipped.
	 */
	private function normalizeBySource(string $sourceType, array $object, string $entityType, string $entityId): ?array {
		return match ($sourceType) {
			'interaction' => $this->normalizeInteraction(object: $object, entityType: $entityType, entityId: $entityId),
			'task' => $this->normalizeTask(object: $object, entityType: $entityType, entityId: $entityId),
			'emailLink' => $this->normalizeEmailLink(object: $object, entityType: $entityType, entityId: $entityId),
			'calendarLink' => $this->normalizeCalendarLink(object: $object, entityType: $entityType, entityId: $entityId),
			'note' => $this->normalizeNote(note: $object, entityType: $entityType, entityId: $entityId),
			'message' => $this->normalizeMessage(message: $object, entityType: $entityType, entityId: $entityId),
			'booking' => $this->normalizeBooking(booking: $object, entityType: $entityType, entityId: $entityId),
			'linkedEmail' => $this->normalizeLinkedEmail(object: $object, entityType: $entityType, entityId: $entityId),
			default => null,
		};
	}//end normalizeBySource()

	/**
	 * Normalise a contact moment ticket (a worklog when its channel is worklog) into an activity item.
	 *
	 * @param array<string,mixed> $object The raw row.
	 * @param string $entityType The originating entityType.
	 * @param string $entityId The originating entityId.
	 *
	 * @return array<string,mixed> The activity item.
	 */
	private function normalizeInteraction(array $object, string $entityType, string $entityId): array {
		$id = (string)($object['id'] ?? $object['uuid'] ?? '');

		// Ticket field names (unify-ticket-supertype): subject -> title,
		// summary -> description, contactedAt -> occurredAt, agent -> assignee.
		$channel = (string)($object['channel'] ?? '');
		$type = 'interaction';
		if ($channel === 'worklog') {
			$type = 'worklog';
		}
		return [
			'type' => $type,
			'id' => $id,
			'title' => (string)($object['title'] ?? $object['description'] ?? ''),
			'description' => (string)($object['description'] ?? ''),
			'date' => $this->stringOrNull(value: ($object['occurredAt'] ?? null)),
			'user' => $this->stringOrNull(value: ($object['assignee'] ?? null)),
			'entityType' => $entityType,
			'entityId' => $entityId,
			'metadata' => [
				'channel' => $channel,
				'direction' => $this->stringOrNull(value: ($object['direction'] ?? null)),
				'duration' => $this->stringOrNull(value: ($object['duration'] ?? null)),
				'outcome' => $this->stringOrNull(value: ($object['outcome'] ?? null)),
			],
		];
	}//end normalizeInteraction()

	/**
	 * Normalise a CRM task into an activity item.
	 *
	 * @param array<string,mixed> $object The raw row.
	 * @param string $entityType The originating entityType.
	 * @param string $entityId The originating entityId.
	 *
	 * @return array<string,mixed> The activity item.
	 */
	private function normalizeTask(array $object, string $entityType, string $entityId): array {
		$id = (string)($object['id'] ?? $object['uuid'] ?? '');

		return [
			'type' => 'task',
			'id' => $id,
			'title' => (string)($object['subject'] ?? $object['title'] ?? ''),
			'description' => (string)($object['description'] ?? ''),
			'date' => $this->stringOrNull(value: ($object['deadline'] ?? $object['createdAt'] ?? null)),
			'user' => $this->stringOrNull(value: ($object['assigneeUserId'] ?? null)),
			'entityType' => $entityType,
			'entityId' => $entityId,
			'metadata' => [
				'status' => $this->stringOrNull(value: ($object['status'] ?? null)),
				'priority' => $this->stringOrNull(value: ($object['priority'] ?? null)),
			],
		];
	}//end normalizeTask()

	/**
	 * Normalise an email link row into an activity item.
	 *
	 * @param array<string,mixed> $object The raw row.
	 * @param string $entityType The originating entityType.
	 * @param string $entityId The originating entityId.
	 *
	 * @return array<string,mixed> The activity item.
	 */
	private function normalizeEmailLink(array $object, string $entityType, string $entityId): array {
		$id = (string)($object['id'] ?? $object['uuid'] ?? '');

		return [
			'type' => 'email',
			'id' => $id,
			'title' => (string)($object['subject'] ?? ''),
			'description' => (string)($object['sender'] ?? ''),
			'date' => $this->stringOrNull(value: ($object['date'] ?? null)),
			'user' => null,
			'entityType' => $entityType,
			'entityId' => $entityId,
			'metadata' => [
				'direction' => $this->stringOrNull(value: ($object['direction'] ?? null)),
				'messageId' => $this->stringOrNull(value: ($object['messageId'] ?? null)),
			],
		];
	}//end normalizeEmailLink()

	/**
	 * Normalise a calendar link row into an activity item.
	 *
	 * @param array<string,mixed> $object The raw row.
	 * @param string $entityType The originating entityType.
	 * @param string $entityId The originating entityId.
	 *
	 * @return array<string,mixed> The activity item.
	 */
	private function normalizeCalendarLink(array $object, string $entityType, string $entityId): array {
		$id = (string)($object['id'] ?? $object['uuid'] ?? '');

		return [
			'type' => 'calendar',
			'id' => $id,
			'title' => (string)($object['title'] ?? $object['subject'] ?? ''),
			'description' => (string)($object['notes'] ?? $object['description'] ?? ''),
			'date' => $this->stringOrNull(value: ($object['startDate'] ?? null)),
			'user' => null,
			'entityType' => $entityType,
			'entityId' => $entityId,
			'metadata' => [
				'endDate' => $this->stringOrNull(value: ($object['endDate'] ?? null)),
				'location' => $this->stringOrNull(value: ($object['location'] ?? null)),
			],
		];
	}//end normalizeCalendarLink()

	/**
	 * Normalise an email linked through OpenRegister's reader into an activity item.
	 *
	 * @param array<string,mixed> $object The raw row.
	 * @param string $entityType The originating entityType.
	 * @param string $entityId The originating entityId.
	 *
	 * @return array<string,mixed> The activity item.
	 */
	private function normalizeLinkedEmail(array $object, string $entityType, string $entityId): array {
		return [
			'type' => 'email',
			'id' => (string)($object['id'] ?? ''),
			'title' => (string)($object['subject'] ?? ''),
			'description' => (string)($object['sender'] ?? ''),
			'date' => $this->stringOrNull(value: ($object['mailDate'] ?? $object['linkedAt'] ?? null)),
			'user' => $this->stringOrNull(value: ($object['linkedBy'] ?? null)),
			'entityType' => $entityType,
			'entityId' => $entityId,
			'metadata' => [
				'mailAccountId' => $object['mailAccountId'] ?? null,
				'mailMessageId' => $object['mailMessageId'] ?? null,
			],
		];
	}//end normalizeLinkedEmail()

	/**
	 * Coerce a value to a string, or return null when empty/null.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null The string value or null.
	 */
	private function stringOrNull(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		$stringValue = '';
		if (is_scalar($value) === true) {
			$stringValue = (string)$value;
		}

		if ($stringValue === '') {
			return null;
		}

		return $stringValue;
	}//end stringOrNull()
}//end class
