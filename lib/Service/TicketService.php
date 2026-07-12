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
use Exception;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Resolver + read/write facade for the unified ticket schema.
 *
 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-ticket-supertype-schema
 */
class TicketService
{
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
     * Constructor.
     *
     * @param ContainerInterface $container The DI container (OpenRegister ObjectService).
     * @param IAppConfig         $appConfig The app config.
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the OpenRegister ObjectService.
     *
     * @return \OCA\OpenRegister\Service\ObjectService The object service.
     *
     * @throws RuntimeException If OpenRegister is not available.
     */
    public function getObjectService(): \OCA\OpenRegister\Service\ObjectService
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Exception $e) {
            throw new RuntimeException('OpenRegister service is not available.');
        }
    }//end getObjectService()

    /**
     * The pipelinq register id.
     *
     * @return string The register id ('' when unconfigured).
     */
    public function getRegisterId(): string
    {
        return $this->appConfig->getValueString(Application::APP_ID, 'register', '');
    }//end getRegisterId()

    /**
     * The unified ticket schema id.
     *
     * @return string The schema id ('' when unconfigured).
     */
    public function getSchemaId(): string
    {
        return $this->appConfig->getValueString(Application::APP_ID, 'ticket_schema', '');
    }//end getSchemaId()

    /**
     * Whether the register + ticket schema are both provisioned.
     *
     * @return bool True when the ticket surface is usable.
     */
    public function isConfigured(): bool
    {
        return $this->getRegisterId() !== '' && $this->getSchemaId() !== '';
    }//end isConfigured()

    /**
     * Find tickets of one subtype.
     *
     * Returns [] (never throws) when the schema is unprovisioned or OpenRegister
     * is unavailable, so callers can degrade to an empty surface.
     *
     * @param string               $ticketType    One of the TYPE_* constants.
     * @param array<string, mixed> $extraFilters  Additional OR filters merged in.
     * @param int                  $limit         Max rows.
     *
     * @return array<int, mixed> The matching ticket rows.
     */
    public function findByType(string $ticketType, array $extraFilters=[], int $limit=10000): array
    {
        if ($this->isConfigured() === false) {
            return [];
        }

        $filters = array_merge(
            [
                'register'   => $this->getRegisterId(),
                'schema'     => $this->getSchemaId(),
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
     * @param string               $ticketType One of the TYPE_* constants.
     * @param array<string, mixed> $payload    The ticket fields.
     * @param string|null          $uuid       Existing ticket uuid, or null to create.
     *
     * @return \OCA\OpenRegister\Db\ObjectEntity The saved ticket.
     *
     * @throws RuntimeException If the ticket surface is unconfigured.
     */
    public function save(string $ticketType, array $payload, ?string $uuid=null): object
    {
        if ($this->isConfigured() === false) {
            throw new RuntimeException('Ticket register or schema not configured.');
        }

        $payload                = $this->sanitizeForSave(payload: $payload);
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
     */
    public function sanitizeForSave(array $payload): array
    {
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
}//end class
