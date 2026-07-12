<?php

/**
 * Pipelinq NormaliseTicketTitle.
 *
 * Repair step that unwraps `ticket.title` values stored as a translation map
 * back into the plain string the three source schemas used.
 *
 * unify-ticket-supertype briefly declared `ticket.title` as `translatable`,
 * which made OpenRegister wrap every title written by the migration into a
 * locale map (`{"nl": "Inlogproblemen na update"}`). None of the three source
 * schemas (`request.title`, `complaint.title`, `contactmoment.subject`) were
 * translatable, so this is a shape regression rather than a feature: the
 * lossless-migration contract says field data survives unchanged. The flag is
 * gone from the schema; this step repairs the rows it already produced.
 *
 * Idempotent: a title that is already a string is left untouched, so a re-run
 * is a no-op.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-lossless-migration-of-existing-records
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCA\Pipelinq\Service\TicketService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repair step: unwrap translation-map ticket titles into plain strings.
 *
 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-lossless-migration-of-existing-records
 */
class NormaliseTicketTitle implements IRepairStep
{
    /**
     * Preferred locale order when collapsing a translation map.
     *
     * The migration only ever wrote the instance locale, so in practice every
     * map has exactly one key; the ordered list is a deterministic tie-break
     * for the theoretical multi-locale row, and any other single key is taken
     * as-is rather than dropped.
     */
    private const LOCALE_PREFERENCE = ['nl', 'en'];

    /**
     * Upper bound on rows fetched per ticket subtype.
     *
     * @var int
     */
    private const BATCH_LIMIT = 10000;

    /**
     * Constructor.
     *
     * @param TicketService   $ticketService Resolver for the unified `ticket` supertype.
     * @param IGroupManager   $groupManager  Group manager, used to resolve an acting admin.
     * @param LoggerInterface $logger        PSR logger.
     */
    public function __construct(
        private TicketService $ticketService,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Resolve an admin to act as while saving.
     *
     * A repair step has no session, so OpenRegister's folder ACL check has no
     * acting user and denies the write for any ticket that owns a file folder
     * ("Access to folder 'N' is denied for the acting user"). Saving as an admin
     * gives that check someone to authorise, without loosening the check itself.
     *
     * @return IUser|null The first admin, or null when none exists.
     */
    private function actingAdmin(): ?IUser
    {
        $admins = $this->groupManager->get('admin')?->getUsers() ?? [];

        return (array_values($admins)[0] ?? null);
    }//end actingAdmin()

    /**
     * Get the repair step name.
     *
     * @return string Name.
     */
    public function getName(): string
    {
        return 'Normalise ticket titles stored as translation maps into plain strings';
    }//end getName()

    /**
     * Run the repair.
     *
     * @param IOutput $output Output.
     *
     * @return void
     *
     * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-lossless-migration-of-existing-records
     */
    public function run(IOutput $output): void
    {
        if ($this->ticketService->isConfigured() === false) {
            $output->info('Ticket surface not configured — skipping title normalisation.');
            return;
        }

        $repaired = 0;
        $skipped  = 0;

        $objectService = $this->ticketService->getObjectService();
        $register      = $this->ticketService->getRegisterId();
        $schema        = $this->ticketService->getSchemaId();
        $actingAdmin   = $this->actingAdmin();

        foreach (TicketService::TYPES as $ticketType) {
            // Read with RBAC off for the same reason we write with it off: on the
            // CLI there is no session, so an RBAC-filtered findAll returns only
            // the handful of objects 'Anonymous' may read and the repair would
            // silently normalise a fraction of the rows.
            $rows = $objectService->findAll(
                config: [
                    'filters' => [
                        'register'   => $register,
                        'schema'     => $schema,
                        'ticketType' => $ticketType,
                    ],
                    'limit'   => self::BATCH_LIMIT,
                ],
                _rbac: false,
                _multitenancy: false,
            );

            foreach ($rows as $row) {
                $data = $this->toArray(row: $row);
                if ($data === null) {
                    continue;
                }

                $title = ($data['title'] ?? null);
                if (is_array($title) === false) {
                    $skipped++;
                    continue;
                }

                $plain = $this->collapse(map: $title);
                if ($plain === null) {
                    $this->logger->warning(
                        'NormaliseTicketTitle: unusable title map, left untouched',
                        ['uuid' => ($data['id'] ?? '')]
                    );
                    continue;
                }

                $data['title'] = $plain;

                // Same seam the app's writes use: OpenRegister hands date-times
                // back as `Y-m-d H:i:s`, which fails its own `format: date-time`
                // on the way in. Without this the save is rejected and the title
                // never lands.
                $data = $this->ticketService->sanitizeForSave(payload: $data);

                try {
                    // Bypass RBAC here — and ONLY here: this is a system context
                    // reshaping data it already owns. TicketService::save() keeps
                    // RBAC on, because controllers call it.
                    $objectService->saveObject(
                        object: $data,
                        extend: [],
                        register: $register,
                        schema: $schema,
                        uuid: (string) ($data['id'] ?? ''),
                        _rbac: false,
                        _multitenancy: false,
                        currentUser: $actingAdmin,
                    );
                    $repaired++;
                } catch (Throwable $e) {
                    $this->logger->error(
                        'NormaliseTicketTitle: failed to save ticket',
                        ['uuid' => ($data['id'] ?? ''), 'exception' => $e->getMessage()]
                    );
                }
            }
        }

        $output->info(
            sprintf('Ticket titles normalised: %d rewritten, %d already plain.', $repaired, $skipped)
        );
    }//end run()

    /**
     * Coerce an OpenRegister row into a plain array.
     *
     * @param mixed $row The row as returned by the object service.
     *
     * @return array<string, mixed>|null The row data, or null when unusable.
     */
    private function toArray(mixed $row): ?array
    {
        if (is_array($row) === true) {
            return $row;
        }

        if (($row instanceof \JsonSerializable) === true) {
            $data = $row->jsonSerialize();
            if (is_array($data) === true) {
                return $data;
            }
        }

        return null;
    }//end toArray()

    /**
     * Collapse a translation map into the single string it wraps.
     *
     * @param array<string, mixed> $map The stored translation map.
     *
     * @return string|null The plain title, or null when no usable value exists.
     */
    private function collapse(array $map): ?string
    {
        foreach (self::LOCALE_PREFERENCE as $locale) {
            if (isset($map[$locale]) === true && is_string($map[$locale]) === true && $map[$locale] !== '') {
                return $map[$locale];
            }
        }

        foreach ($map as $value) {
            if (is_string($value) === true && $value !== '') {
                return $value;
            }
        }

        return null;
    }//end collapse()
}//end class
