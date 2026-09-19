<?php

/**
 * Ticket Service.
 *
 * Single resolution point for the unified `ticket` supertype
 * (unify-ticket-supertype). Every consumer that used to resolve one of the three
 * legacy schemas — `request`, `complaint`, `contactmoment` — now reads and writes
 * `ticket` through this service, narrowing to a subtype with the `ticketType`
 * discriminator instead of switching schema.
 *
 * FIELD RENAMES (legacy -> ticket). Consumers reading a migrated object MUST use
 * the ticket field names:
 *   request.requestedAt      -> occurredAt
 *   contactmoment.subject    -> title
 *   contactmoment.summary    -> description
 *   contactmoment.contactedAt-> occurredAt
 *   contactmoment.agent      -> assignee
 *   contactmoment.request    -> parentTicket
 *   complaint.assignedTo     -> assignee
 *   complaint.category       -> complaintCategory
 * All other field names are unchanged.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-ticket-supertype-schema
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Mcp\Attribute\McpTool;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Mcp\McpAnswer;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Resolver + read/write facade for the unified ticket schema.
 *
 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-ticket-supertype-schema
 */
class TicketService {
	/**
	 * Ticket subtype: an inbound request / demand (formerly the `request` schema).
	 *
	 * @var string
	 */
	public const TYPE_REQUEST = 'request';

	/**
	 * Ticket subtype: a complaint / klacht (formerly the `complaint` schema).
	 *
	 * @var string
	 */
	public const TYPE_COMPLAINT = 'complaint';

	/**
	 * Ticket subtype: a logged interaction (formerly the `contactmoment` schema).
	 *
	 * @var string
	 */
	public const TYPE_CONTACTMOMENT = 'interaction';

	/**
	 * Every ticket subtype, in discriminator order.
	 *
	 * @var array<int, string>
	 */
	public const TYPES = [
		self::TYPE_REQUEST,
		self::TYPE_COMPLAINT,
		self::TYPE_CONTACTMOMENT,
	];

	/**
	 * Every `format: date-time` property on the ticket schema.
	 *
	 * Kept in step with lib/Settings/register.d/99-unify-ticket-supertype.json;
	 * consumed by sanitizeForSave() to undo OpenRegister's read-side date format.
	 *
	 * @var array<int, string>
	 */
	public const DATE_TIME_FIELDS = [
		'occurredAt',
		'slaDeadline',
		'resolvedAt',
	];

	/**
	 * The directions a contact moment can run in.
	 *
	 * Kept in step with `direction` in
	 * lib/Settings/register.d/98-contactmoment-direction.json.
	 *
	 * @var array<int, string>
	 */
	public const DIRECTIONS = [
		'inbound',
		'outbound',
		'internal',
	];

	/**
	 * Properties that are required on one facet of the ticket supertype only.
	 *
	 * OpenRegister validates `required` per schema, and `ticket` holds three
	 * facets under one schema, so a schema-level `required: [direction]` would
	 * refuse every request and every complaint as well. The per-facet guard
	 * therefore lives on the write path, which is the only place that knows
	 * which facet it is writing.
	 *
	 * @var array<string, array<int, string>>
	 *
	 * @spec openspec/changes/contact-moments-on-pipelinq-schema/specs/contactmomenten/spec.md#requirement-direction-is-a-first-class-field-req-cmd-001
	 */
	public const FACET_REQUIRED = [
		self::TYPE_CONTACTMOMENT => ['direction'],
	];

	/**
	 * The words a user writes when they mean one of the ticket subtypes,
	 * bilingual NL/EN, keyed by the subtype they name. Consumed by
	 * detectTypeInText(); the subtype vocabulary lives with the subtype.
	 *
	 * @var array<string, string>
	 */
	private const TYPE_VOCABULARY = [
		self::TYPE_REQUEST => '/\b(request|requests|verzoek|verzoeken|aanvraag|aanvragen)\b/u',
		self::TYPE_CONTACTMOMENT => '/\b(contactmoment|contactmomenten|contact)\b/u',
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config.
	 * @param LoggerInterface $logger The logger.
	 * @param ObjectServiceInterface $objectService The OpenRegister object service.
	 * @param McpAnswer $mcp Shapes what the MCP tools on this service answer.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly McpAnswer $mcp,
	) {
	}//end __construct()

	/**
	 * Get the OpenRegister ObjectService.
	 *
	 * @return \OCA\OpenRegister\Contract\ObjectServiceInterface The object service.
	 *
	 * @throws RuntimeException If OpenRegister is not available.
	 *
	 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-ticket-supertype-schema
	 */
	public function getObjectService(): \OCA\OpenRegister\Contract\ObjectServiceInterface {
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable — phpstan reports it as a dead catch.
		return $this->objectService;
	}//end getObjectService()

	/**
	 * The pipelinq register id.
	 *
	 * Fails closed: '' means "unconfigured". Callers must gate on
	 * {@see isConfigured()} and refuse the OpenRegister call — an empty
	 * register must never be handed to OpenRegister, because ObjectService
	 * skips setRegister() for an empty value and the query then silently
	 * inherits whatever register context an earlier call in the same request
	 * left on the shared service instance. The empty case is logged so an
	 * unprovisioned instance is visible rather than silent.
	 *
	 * @return string The register id ('' when unconfigured).
	 *
	 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-ticket-supertype-schema
	 */
	public function getRegisterId(): string {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($registerId === '') {
			$this->logger->warning(
				'Pipelinq: app-config "register" is not configured; ticket reads/writes are refused, not run unscoped'
			);
		}

		return $registerId;
	}//end getRegisterId()

	/**
	 * The unified ticket schema id.
	 *
	 * Fails closed: '' means "unconfigured". Callers must gate on
	 * {@see isConfigured()} and refuse the OpenRegister call — an empty schema
	 * must never be handed to OpenRegister, because ObjectService skips
	 * setSchema() for an empty value and the query then silently inherits
	 * whatever schema context an earlier call in the same request left on the
	 * shared service instance. The empty case is logged so an unprovisioned
	 * instance is visible rather than silent.
	 *
	 * @return string The schema id ('' when unconfigured).
	 *
	 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-ticket-supertype-schema
	 */
	public function getSchemaId(): string {
		$schemaId = $this->appConfig->getValueString(Application::APP_ID, 'ticket_schema', '');
		if ($schemaId === '') {
			$this->logger->warning(
				'Pipelinq: app-config "ticket_schema" is not configured; ticket reads/writes are refused, not run unscoped'
			);
		}

		return $schemaId;
	}//end getSchemaId()

	/**
	 * Whether the register + ticket schema are both provisioned.
	 *
	 * @return bool True when the ticket surface is usable.
	 *
	 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-ticket-supertype-schema
	 */
	public function isConfigured(): bool {
		return $this->getRegisterId() !== '' && $this->getSchemaId() !== '';
	}//end isConfigured()

	/**
	 * Recognise which ticket subtype a piece of free text is about.
	 *
	 * The subtype vocabulary belongs with the subtypes themselves, so callers
	 * that read natural language (Navi) do not each carry their own copy of the
	 * NL/EN words for "request" and "interaction".
	 *
	 * @param string $text Free text, e.g. a natural-language query.
	 *
	 * @return string|null One of the TYPE_* constants, or null when the text
	 *                     names no subtype.
	 *
	 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-ticket-supertype-schema
	 */
	public function detectTypeInText(string $text): ?string {
		$lower = mb_strtolower($text);
		foreach (self::TYPE_VOCABULARY as $ticketType => $pattern) {
			if (preg_match($pattern, $lower) === 1) {
				return $ticketType;
			}
		}

		return null;
	}//end detectTypeInText()

	/**
	 * Find tickets of one subtype.
	 *
	 * Returns [] (never throws) when the schema is unprovisioned or OpenRegister
	 * is unavailable, so callers can degrade to an empty surface.
	 *
	 * @param string $ticketType One of the TYPE_* constants.
	 * @param array<string, mixed> $extraFilters Additional OR filters merged in.
	 * @param int $limit Max rows.
	 *
	 * @return array<int, mixed> The matching ticket rows.
	 *
	 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-unified-tickets-workspace
	 */
	public function findByType(string $ticketType, array $extraFilters = [], int $limit = 10000): array {
		if ($this->isConfigured() === false) {
			return [];
		}

		$filters = array_merge(
			[
				'register' => $this->getRegisterId(),
				'schema' => $this->getSchemaId(),
				'ticketType' => $ticketType,
			],
			$extraFilters
		);

		try {
			return $this->getObjectService()->findAll(['filters' => $filters, 'limit' => $limit]);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'TicketService.findByType failed',
				['ticketType' => $ticketType, 'exception' => $e->getMessage()]
			);
			return [];
		}
	}//end findByType()

	/**
	 * Create or update a ticket of one subtype.
	 *
	 * The `ticketType` discriminator is always forced onto the payload so a
	 * caller can never write an untyped ticket.
	 *
	 * @param string $ticketType One of the TYPE_* constants.
	 * @param array<string, mixed> $payload The ticket fields.
	 * @param string|null $uuid Existing ticket uuid, or null to create.
	 *
	 * @return \OCA\OpenRegister\Contract\ObjectEntityInterface The saved ticket.
	 *   ObjectServiceInterface::saveObject() is declared against the CONTRACT,
	 *   not the concrete Db\ObjectEntity — narrowing it here was a phpstan error
	 *   and would have broken any alternative entity implementation.
	 *
	 * @throws RuntimeException If the ticket surface is unconfigured.
	 * @throws InvalidArgumentException When assertFacetFields() refuses the
	 *   payload: a required facet field is missing, the direction is not one
	 *   this app offers, or the case set holds a duplicate. Declared here
	 *   because callers catch it to answer 400, and without the tag static
	 *   analysis reads that catch as dead.
	 *
	 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
	 */
	public function save(string $ticketType, array $payload, ?string $uuid = null): object {
		if ($this->isConfigured() === false) {
			throw new RuntimeException('Ticket register or schema not configured.');
		}

		$payload = $this->sanitizeForSave(payload: $payload);

		$this->assertFacetFields(ticketType: $ticketType, payload: $payload);

		$payload['ticketType'] = $ticketType;

		return $this->getObjectService()->saveObject(
			object: $payload,
			register: $this->getRegisterId(),
			schema: $this->getSchemaId(),
			uuid: $uuid,
		);
	}//end save()

	/**
	 * Refuse a write that omits a property its facet requires.
	 *
	 * Only fields listed in FACET_REQUIRED for the facet being written are
	 * checked, and the refusal names the property, so a caller reads what it
	 * left out rather than a generic validation failure. An update that does
	 * not carry the field at all is treated the same as a create that omits
	 * it: every ticket write in this app is a full-object write.
	 *
	 * @param string $ticketType One of the TYPE_* constants.
	 * @param array<string, mixed> $payload The ticket fields.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a required facet field is missing
	 *   or carries a value the schema does not offer.
	 *
	 * @spec openspec/changes/contact-moments-on-pipelinq-schema/specs/contactmomenten/spec.md#requirement-direction-is-a-first-class-field-req-cmd-001
	 */
	public function assertFacetFields(string $ticketType, array $payload): void {
		foreach ((self::FACET_REQUIRED[$ticketType] ?? []) as $field) {
			$value = ($payload[$field] ?? null);
			if (is_string($value) === false || trim($value) === '') {
				throw new InvalidArgumentException(
					"A {$ticketType} ticket requires {$field}."
				);
			}
		}

		$direction = ($payload['direction'] ?? null);
		if (is_string($direction) === true && $direction !== ''
			&& in_array($direction, self::DIRECTIONS, true) === false
		) {
			throw new InvalidArgumentException(
				'direction must be one of ' . implode(', ', self::DIRECTIONS) . "; got {$direction}."
			);
		}

		$this->assertCaseSet(payload: $payload);
	}//end assertFacetFields()

	/**
	 * Refuse a case set that is not a set, or a primary outside it.
	 *
	 * Checked on EVERY write rather than only in the filing acts, because an
	 * import, an API call and a flow write through here too, and a duplicate
	 * that lands silently is a set that has stopped being one.
	 *
	 * @param array<string, mixed> $payload The ticket fields.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the set holds a duplicate, or the
	 *   primary is not a member of it.
	 *
	 * @spec openspec/changes/one-contact-moment-on-several-cases/specs/contactmomenten/spec.md#requirement-one-reference-is-the-primary-one-and-it-is-named-req-cms-002
	 */
	private function assertCaseSet(array $payload): void {
		$set = ($payload['caseReferences'] ?? null);
		if (is_array($set) === false || $set === []) {
			return;
		}

		$seen = [];
		foreach ($set as $reference) {
			$reference = trim((string)$reference);
			if ($reference === '') {
				continue;
			}

			if (in_array($reference, $seen, true) === true) {
				throw new InvalidArgumentException(
					"caseReferences already holds {$reference}; a contact moment is filed on a case once."
				);
			}

			$seen[] = $reference;
		}

		$primary = trim((string)($payload['primaryCaseReference'] ?? ''));
		if ($primary !== '' && in_array($primary, $seen, true) === false) {
			throw new InvalidArgumentException(
				"primaryCaseReference {$primary} is not one of the cases this contact moment is filed on."
			);
		}
	}//end assertCaseSet()

	/**
	 * Repair OpenRegister's read-side artefacts before a write.
	 *
	 * A read-modify-write against OpenRegister re-validates the WHOLE object,
	 * but its read side hands back `format: date-time` fields as `Y-m-d H:i:s`
	 * (space, no `T`) — which then fails that same format on the way back in
	 * ("Property 'occurredAt' should match format 'date-time'"). Every ticket
	 * write therefore funnels through here.
	 *
	 * Only reshapes values that already parse as an instant; anything that does
	 * not is passed through untouched so genuinely invalid input still fails
	 * validation instead of being silently masked.
	 *
	 * @param array<string, mixed> $payload The ticket fields.
	 *
	 * @return array<string, mixed> The payload with date-times in ISO-8601.
	 *
	 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
	 */
	public function sanitizeForSave(array $payload): array {
		foreach (self::DATE_TIME_FIELDS as $field) {
			$value = ($payload[$field] ?? null);
			if (is_string($value) === false || $value === '') {
				continue;
			}

			try {
				$payload[$field] = (new DateTimeImmutable($value))->format('c');
			} catch (Exception $e) {
				// Not an instant — leave it for schema validation to reject.
				continue;
			}
		}

		return $payload;
	}//end sanitizeForSave()

	/**
	 * Log a client interaction as a contactmoment (client, channel and title
	 * are required; outcome and notes are optional).
	 *
	 * Validates the required arguments, then writes through
	 * save(TYPE_CONTACTMOMENT, ...) so the `ticketType` discriminator is
	 * forced and date-time fields are normalised (sanitizeForSave()).
	 * Migrated out of `OCA\Pipelinq\Mcp\PipelinqToolProvider` (deleted) by
	 * `plq-mcp-provider-surgery`; annotated `#[McpTool]` (OpenRegister
	 * ADR-063 chain 3/3, PR #363) so OpenRegister's AttributeToolScanner can
	 * discover it via `OCA\Pipelinq\Mcp\PipelinqScannableServices`.
	 *
	 * @param string $client The client UUID this interaction is with.
	 * @param string $channel The interaction channel (e.g. telefoon, email, balie, chat).
	 * @param string $title A short summary of the interaction.
	 * @param string $direction Which way the contact ran: inbound, outbound or internal.
	 * @param string|null $outcome Optional outcome (e.g. afgehandeld, doorverbonden, terugbelverzoek).
	 * @param string|null $notes Optional free-text notes about the interaction.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/crm-mcp-tool-surface/spec.md
	 *   (Requirement: MCP provider exposes RBAC-guarded CRM write tools)
	 */
	#[McpTool(
		name: 'logContactmoment',
		subject: 'contactMoment',
		action: 'create',
		description: 'Log a client interaction as a contactmoment (client, channel, title and direction are required; outcome and notes are optional).',
		readOnlyHint: false,
		destructiveHint: false,
		idempotentHint: false,
		scope: 'create'
	)]
	public function logContactmoment(
		string $client,
		string $channel,
		string $title,
		string $direction = '',
		?string $outcome = null,
		?string $notes = null,
	): array {
		$client = trim($client);
		if ($client === '') {
			return $this->mcp->error(code: 'invalid_arguments', message: 'Required argument client is missing.');
		}

		$channel = trim($channel);
		if ($channel === '') {
			return $this->mcp->error(code: 'invalid_arguments', message: 'Required argument channel is missing.');
		}

		$title = trim($title);
		if ($title === '') {
			return $this->mcp->error(code: 'invalid_arguments', message: 'Required argument title is missing.');
		}

		// Direction is required on a contactmoment (REQ-CMD-001) and is
		// deliberately NOT defaulted: a tool that guessed `inbound` would
		// assert who reached out to whom, silently and often wrongly. The
		// parameter is optional in the SIGNATURE only, so an existing caller
		// gets a named refusal instead of a TypeError.
		$direction = trim($direction);
		if ($direction === '') {
			return $this->mcp->error(
				code: 'invalid_arguments',
				message: 'Required argument direction is missing.'
			);
		}

		if (in_array($direction, self::DIRECTIONS, true) === false) {
			return $this->mcp->error(
				code: 'invalid_arguments',
				message: 'Argument direction must be one of ' . implode(', ', self::DIRECTIONS) . '.'
			);
		}

		if ($this->isConfigured() === false) {
			return $this->mcp->error(
				code: 'not_configured',
				message: 'Pipelinq is not fully configured: the OpenRegister register or ticket schema is missing.'
			);
		}

		$payload = [
			'client' => $client,
			'channel' => $channel,
			'title' => $title,
			'direction' => $direction,
			'occurredAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
		];

		if ($outcome !== null && $outcome !== '') {
			$payload['outcome'] = $outcome;
		}

		if ($notes !== null && $notes !== '') {
			$payload['description'] = $notes;
		}

		try {
			$saved = $this->save(ticketType: self::TYPE_CONTACTMOMENT, payload: $payload);
		} catch (\Exception $e) {
			return $this->mcp->fromException(operation: 'log contactmoment', exception: $e);
		}

		$data = $this->mcp->toArray(item: $saved);

		return [
			'ticketId' => (string)($data['id'] ?? $data['uuid'] ?? ''),
		];

	}//end logContactmoment()

}//end class
