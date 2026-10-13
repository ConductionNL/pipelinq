<?php

/**
 * Pipelinq OpenMailService.
 *
 * What a mail client's side pane asks about the mail that is open: who the
 * sender is in pipelinq, and to log that mail as a contact moment. Every read
 * and write runs as the signed-in colleague, so they see and touch only what
 * they may.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\MailAddIn
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/contact-moments-mail-add-in/specs/mail-add-in/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\MailAddIn;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use InvalidArgumentException;
use OCA\Pipelinq\Service\CallerObjectReader;
use OCA\Pipelinq\Service\ContactSyncService;
use OCA\Pipelinq\Service\EmailMatchService;
use OCA\Pipelinq\Service\TicketService;
use OutOfBoundsException;

/**
 * Looks up a mail's sender and logs a mail as a contact moment.
 *
 * @spec openspec/changes/contact-moments-mail-add-in/specs/mail-add-in/spec.md
 */
class OpenMailService {
	/**
	 * How much of a mail's text is kept on the contact moment. A mail body
	 * is personal data, so only the opening is stored.
	 */
	public const PREVIEW_MAX_CHARS = 1000;

	/**
	 * The longest title the ticket schema takes.
	 */
	private const TITLE_MAX_CHARS = 255;

	/**
	 * The app config key of the schema behind each record type a mail can
	 * be matched to or logged on.
	 *
	 * @var array<string, string>
	 */
	private const SCHEMA_KEYS = [
		'contact' => 'contact_schema',
		'client' => 'client_schema',
	];

	/**
	 * Constructor.
	 *
	 * @param EmailMatchService $matcher Finds the records an address belongs to.
	 * @param CallerObjectReader $reader Reads a record as the signed-in user.
	 * @param TicketService $tickets Writes the contact moment.
	 * @param ContactSyncService $contactSync The contact-first create.
	 */
	public function __construct(
		private readonly EmailMatchService $matcher,
		private readonly CallerObjectReader $reader,
		private readonly TicketService $tickets,
		private readonly ContactSyncService $contactSync,
	) {
	}//end __construct()

	/**
	 * The records a sender's address belongs to, as far as the caller may
	 * see them, and an organisation to suggest when there are none.
	 *
	 * @param string $email The sender's address.
	 *
	 * @return array{matches: array<int, array<string, string>>, organisation: array<string, string>|null}
	 *
	 * @throws InvalidArgumentException When the address is not an email address.
	 *
	 * @spec openspec/changes/contact-moments-mail-add-in/specs/mail-add-in/spec.md#requirement-the-pane-shows-the-senders-record-or-adds-it-req-mai-002
	 */
	public function lookup(string $email): array {
		$email = strtolower(trim($email));
		if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			throw new InvalidArgumentException('email is not an email address');
		}

		$matches = [];
		foreach ($this->matcher->matchEmailToEntities(address: $email) as $match) {
			$summary = $this->summary(entityType: (string)$match['entityType'], entityId: (string)$match['entityId']);
			if ($summary !== null) {
				$matches[] = $summary;
			}
		}

		$organisation = null;
		if ($matches === []) {
			$found = $this->matcher->matchDomainToOrganization(domain: substr($email, (strrpos($email, '@') + 1)));
			if ($found !== null) {
				$organisation = $this->summary(entityType: 'client', entityId: (string)$found['entityId']);
			}
		}

		return ['matches' => $matches, 'organisation' => $organisation];
	}//end lookup()

	/**
	 * Add a mail's sender as a contact person, the Nextcloud contact first.
	 *
	 * A client named with the sender must be one the caller may read.
	 *
	 * @param string $name The sender's name.
	 * @param string $email The sender's address.
	 * @param string $client The id of the client they belong to, or ''.
	 *
	 * @return array<string, mixed> The created contact.
	 *
	 * @throws InvalidArgumentException When the name or the address is missing or malformed.
	 * @throws OutOfBoundsException When the caller cannot read the client.
	 *
	 * @spec openspec/changes/contact-moments-mail-add-in/specs/mail-add-in/spec.md#requirement-the-pane-shows-the-senders-record-or-adds-it-req-mai-002
	 */
	public function addSender(string $name, string $email, string $client = ''): array {
		$name = trim($name);
		$email = strtolower(trim($email));
		if ($name === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			throw new InvalidArgumentException('A name and an email address are required.');
		}

		$form = ['name' => $name, 'email' => $email];
		$client = trim($client);
		if ($client !== '') {
			if ($this->reader->read(id: $client, schemaKey: self::SCHEMA_KEYS['client']) === null) {
				throw new OutOfBoundsException('client not found');
			}

			$form['client'] = $client;
		}

		return $this->contactSync->createWithContact(objectType: 'contact', form: $form);
	}//end addSender()

	/**
	 * Log a mail as an inbound contact moment on a contact or a client.
	 *
	 * The target is read as the caller first: a record they may not see is
	 * a record they may not log on.
	 *
	 * @param string $entityType `contact` or `client`.
	 * @param string $entityId The record's id.
	 * @param string $subject The mail's subject.
	 * @param string $sentAt When the mail was sent, any ISO 8601 instant.
	 * @param string $preview The opening of the mail's text.
	 *
	 * @return array<string, string> The contact moment's id and the fields written.
	 *
	 * @throws InvalidArgumentException When a field is missing or malformed.
	 * @throws OutOfBoundsException When the caller cannot read the target record.
	 *
	 * @spec openspec/changes/contact-moments-mail-add-in/specs/mail-add-in/spec.md#requirement-a-mail-can-be-logged-as-a-contact-moment-req-mai-003
	 */
	public function logMail(string $entityType, string $entityId, string $subject, string $sentAt, string $preview): array {
		$payload = $this->contactMomentFor(entityType: $entityType, entityId: $entityId);

		$subject = trim($subject);
		if ($subject === '') {
			throw new InvalidArgumentException('subject is required');
		}

		$sentAt = trim($sentAt);
		if ($sentAt === '') {
			throw new InvalidArgumentException('sentAt is required');
		}

		try {
			$instant = new DateTimeImmutable($sentAt);
		} catch (Exception $e) {
			throw new InvalidArgumentException('sentAt is not a date and time');
		}

		$payload['title'] = mb_substr($subject, 0, self::TITLE_MAX_CHARS);
		$payload['channel'] = 'email';
		$payload['direction'] = 'inbound';
		$payload['occurredAt'] = $instant->format(DateTimeInterface::ATOM);
		$preview = trim($preview);
		if ($preview !== '') {
			$payload['description'] = mb_substr($preview, 0, self::PREVIEW_MAX_CHARS);
		}

		$saved = $this->tickets->save(ticketType: TicketService::TYPE_CONTACTMOMENT, payload: $payload);

		$id = '';
		if (method_exists($saved, 'getUuid') === true) {
			$id = (string)$saved->getUuid();
		}

		return (['id' => $id] + $payload);
	}//end logMail()

	/**
	 * The start of a contact moment on a record the caller may read.
	 *
	 * A contact person's moment also names their client, so it shows on
	 * both pages.
	 *
	 * @param string $entityType `contact` or `client`.
	 * @param string $entityId The record's id.
	 *
	 * @return array<string, string> The `contact` and `client` links.
	 *
	 * @throws InvalidArgumentException When the type or id is not usable.
	 * @throws OutOfBoundsException When the caller cannot read the record.
	 */
	private function contactMomentFor(string $entityType, string $entityId): array {
		$entityId = trim($entityId);
		if (isset(self::SCHEMA_KEYS[$entityType]) === false || $entityId === '') {
			throw new InvalidArgumentException('entityType must be contact or client, with an entityId');
		}

		$record = $this->reader->read(id: $entityId, schemaKey: self::SCHEMA_KEYS[$entityType]);
		if ($record === null) {
			throw new OutOfBoundsException('record not found');
		}

		if ($entityType === 'client') {
			return ['client' => $entityId];
		}

		$links = ['contact' => $entityId];
		$client = ($record['client'] ?? null);
		if (is_string($client) === true && $client !== '') {
			$links['client'] = $client;
		}

		return $links;
	}//end contactMomentFor()

	/**
	 * What the pane shows of one record, or null when the caller may not
	 * read it.
	 *
	 * @param string $entityType `contact` or `client`.
	 * @param string $entityId The record's id.
	 *
	 * @return array<string, string>|null The type, id, name and address, and a contact's client.
	 */
	private function summary(string $entityType, string $entityId): ?array {
		if (isset(self::SCHEMA_KEYS[$entityType]) === false) {
			return null;
		}

		$record = $this->reader->read(id: $entityId, schemaKey: self::SCHEMA_KEYS[$entityType]);
		if ($record === null) {
			return null;
		}

		$summary = [
			'entityType' => $entityType,
			'entityId' => $entityId,
			'name' => (string)($record['name'] ?? ''),
			'email' => (string)($record['email'] ?? ''),
		];

		// A contact person is shown with the client they belong to, when the
		// caller may read that client too.
		$clientId = ($record['client'] ?? null);
		if ($entityType === 'contact' && is_string($clientId) === true && $clientId !== '') {
			$client = $this->reader->read(id: $clientId, schemaKey: self::SCHEMA_KEYS['client']);
			if ($client !== null) {
				$summary['clientId'] = $clientId;
				$summary['clientName'] = (string)($client['name'] ?? '');
			}
		}

		return $summary;
	}//end summary()
}//end class
