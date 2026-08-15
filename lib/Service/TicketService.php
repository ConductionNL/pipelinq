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
use OCA\OpenRegister\Mcp\Attribute\McpTool;
use OCA\Pipelinq\AppInfo\Application;
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
	public const TYPE_CONTACTMOMENT = 'contactmoment';

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
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
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
	 * NL/EN words for "request" and "contactmoment".
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
	 * @return \OCA\OpenRegister\Db\ObjectEntity The saved ticket.
	 *
	 * @throws RuntimeException If the ticket surface is unconfigured.
	 *
	 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
	 */
	public function save(string $ticketType, array $payload, ?string $uuid = null): object {
		if ($this->isConfigured() === false) {
			throw new RuntimeException('Ticket register or schema not configured.');
		}

		$payload = $this->sanitizeForSave(payload: $payload);

		$payload['ticketType'] = $ticketType;

		return $this->getObjectService()->saveObject(
			object: $payload,
			register: $this->getRegisterId(),
			schema: $this->getSchemaId(),
			uuid: $uuid,
		);
	}//end save()

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
		description: 'Log a client interaction as a contactmoment (client, channel and title are required; outcome and notes are optional).',
		readOnlyHint: false,
		destructiveHint: false,
		idempotentHint: false,
		scope: 'create'
	)]
	public function logContactmoment(
		string $client,
		string $channel,
		string $title,
		?string $outcome = null,
		?string $notes = null,
	): array {
		$client = trim($client);
		if ($client === '') {
			return $this->mcpErrorEnvelope(code: 'invalid_arguments', message: 'Required argument client is missing.');
		}

		$channel = trim($channel);
		if ($channel === '') {
			return $this->mcpErrorEnvelope(code: 'invalid_arguments', message: 'Required argument channel is missing.');
		}

		$title = trim($title);
		if ($title === '') {
			return $this->mcpErrorEnvelope(code: 'invalid_arguments', message: 'Required argument title is missing.');
		}

		if ($this->isConfigured() === false) {
			return $this->mcpErrorEnvelope(
				code: 'not_configured',
				message: 'Pipelinq is not fully configured: the OpenRegister register or ticket schema is missing.'
			);
		}

		$payload = [
			'client' => $client,
			'channel' => $channel,
			'title' => $title,
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
			return $this->mapMcpServiceException(operation: 'log contactmoment', exception: $e);
		}

		$data = $this->mcpToArray(item: $saved);

		return [
			'ticketId' => (string)($data['id'] ?? $data['uuid'] ?? ''),
		];

	}//end logContactmoment()

	/**
	 * Map an exception raised by OpenRegister into a structured MCP error envelope.
	 *
	 * OpenRegister's PermissionHandler raises a plain exception whose message
	 * mentions "permission" when the caller is not authorised; we surface that as
	 * `forbidden`. Everything else is an `internal_error` (logged for the operator).
	 *
	 * @param string $operation Short label of the failed operation (for the log).
	 * @param \Exception $exception The caught exception.
	 *
	 * @return array<string, mixed>
	 */
	private function mapMcpServiceException(string $operation, \Exception $exception): array {
		$message = $exception->getMessage();

		if (stripos($message, 'permission') !== false || stripos($message, 'not authoriz') !== false) {
			return $this->mcpErrorEnvelope(code: 'forbidden', message: 'You are not allowed to access this resource.');
		}

		$this->logger->error(
			"Pipelinq MCP: failed to {$operation}",
			['exception' => $message]
		);

		return $this->mcpErrorEnvelope(
			code: 'internal_error',
			message: "Failed to {$operation}. See server log for details."
		);

	}//end mapMcpServiceException()

	/**
	 * Build a structured MCP error envelope.
	 *
	 * @param string $code Machine-readable error code.
	 * @param string $message Human-readable message for the LLM.
	 *
	 * @return array<string, mixed>
	 */
	private function mcpErrorEnvelope(string $code, string $message): array {
		return [
			'error' => [
				'code' => $code,
				'message' => $message,
			],
		];

	}//end mcpErrorEnvelope()

	/**
	 * Normalise an OpenRegister object to a plain PHP array.
	 *
	 * @param mixed $item Raw item from ObjectService.
	 *
	 * @return array<string, mixed>
	 */
	private function mcpToArray(mixed $item): array {
		if (is_array(value: $item) === true) {
			return $item;
		}

		if (is_object(value: $item) === true && method_exists($item, 'getObject') === true) {
			return $item->getObject();
		}

		if (is_object(value: $item) === true && method_exists($item, 'jsonSerialize') === true) {
			return $item->jsonSerialize();
		}

		return (array)$item;
	}//end mcpToArray()
}//end class
