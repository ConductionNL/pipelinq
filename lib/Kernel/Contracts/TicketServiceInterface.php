<?php

/**
 * Contract of TicketService
 *
 * The public surface of TicketService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

use InvalidArgumentException;
use RuntimeException;

/**
 * Contract of TicketService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TicketServiceInterface {

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
	 * Ticket subtype: a report about the public space (melding), such as loose
	 * paving or a broken street light.
	 *
	 * @var string
	 */
	public const TYPE_MELDING = 'melding';

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
		self::TYPE_MELDING,
		self::TYPE_CONTACTMOMENT,
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
	 * Find tickets of one subtype.
	 *
	 * Returns [] (never throws) when the schema is unprovisioned or OpenRegister
	 * is unavailable, so callers can degrade to an empty surface.
	 *
	 * @param string $ticketType One of the TYPE_* constants.
	 * @param array<string, mixed> $extraFilters Additional OR filters merged in.
	 * @param int $limit Max rows.
	 * @param array<string, string> $sort Order, as `[field => asc|desc]`. Empty keeps the store's own order.
	 *
	 * @return array<int, mixed> The matching ticket rows.
	 *
	 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-unified-tickets-workspace
	 */
	public function findByType(string $ticketType, array $extraFilters = [], int $limit = 10000, array $sort = []): array;

	/**
	 * Get the OpenRegister ObjectService.
	 *
	 * @return \OCA\OpenRegister\Contract\ObjectServiceInterface The object service.
	 *
	 * @throws RuntimeException If OpenRegister is not available.
	 *
	 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-ticket-supertype-schema
	 */
	public function getObjectService(): \OCA\OpenRegister\Contract\ObjectServiceInterface;

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
	 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-ticket-supertype-schema
	 */
	public function getRegisterId(): string;

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
	 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-ticket-supertype-schema
	 */
	public function getSchemaId(): string;

	/**
	 * Whether the register + ticket schema are both provisioned.
	 *
	 * @return bool True when the ticket surface is usable.
	 *
	 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-ticket-supertype-schema
	 */
	public function isConfigured(): bool;

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
	 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
	 */
	public function sanitizeForSave(array $payload): array;

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
	 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
	 */
	public function save(string $ticketType, array $payload, ?string $uuid = null): object;
}//end interface
