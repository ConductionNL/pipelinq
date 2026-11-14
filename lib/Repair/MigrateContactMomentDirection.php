<?php

/**
 * Pipelinq MigrateContactMomentDirection.
 *
 * Fills `ticket.direction` on contact moments written before the field
 * existed. Until `contact-moments-on-pipelinq-schema` the direction of a
 * contact moment lived inside the free-form `channelMetadata` envelope, under
 * either `direction` (English) or `richting` (Dutch), because the two source
 * schemas that were folded into `ticket` spelled it differently.
 *
 * The step is idempotent: a row that already carries `direction` is left
 * untouched, so a second run rewrites nothing. A row whose envelope says
 * nothing usable is filled with `internal`, which is the only direction that
 * asserts nothing about who reached out to whom.
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
 * @spec openspec/specs/contactmomenten/spec.md#requirement-direction-is-a-first-class-field-req-cmd-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCA\Pipelinq\Kernel\Contracts\TicketServiceInterface;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repair step: give every existing contact moment a first-class direction.
 *
 * @spec openspec/specs/contactmomenten/spec.md#requirement-direction-is-a-first-class-field-req-cmd-001
 */
class MigrateContactMomentDirection implements IRepairStep {
	/**
	 * Upper bound on rows fetched in one pass.
	 *
	 * @var int
	 */
	private const BATCH_LIMIT = 10000;

	/**
	 * The words the old envelope used, mapped onto the new enum.
	 *
	 * Both spellings are listed because both were written: `richting` by the
	 * Dutch KCC surface and `direction` by the API. Anything not listed here
	 * falls through to `internal` rather than guessing.
	 *
	 * @var array<string, string>
	 */
	private const VOCABULARY = [
		'inbound' => 'inbound',
		'inkomend' => 'inbound',
		'in' => 'inbound',
		'outbound' => 'outbound',
		'uitgaand' => 'outbound',
		'uit' => 'outbound',
		'internal' => 'internal',
		'intern' => 'internal',
	];

	/**
	 * Constructor.
	 *
	 * @param TicketServiceInterface $ticketService Resolver for the unified `ticket` supertype.
	 * @param IGroupManager $groupManager Group manager, used to resolve an acting admin.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private TicketServiceInterface $ticketService,
		private IGroupManager $groupManager,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the repair step name.
	 *
	 * @return string Name.
	 *
	 * @spec openspec/specs/contactmomenten/spec.md#requirement-direction-is-a-first-class-field-req-cmd-001
	 */
	public function getName(): string {
		return 'Fill the direction of contact moments written before the field existed';
	}//end getName()

	/**
	 * The direction a stored contact moment should carry.
	 *
	 * Returns null when the row already carries a usable direction, which is
	 * what makes the step idempotent: the caller writes nothing on null.
	 *
	 * @param array<string, mixed> $data The stored contact moment.
	 *
	 * @return string|null The direction to write, or null to leave the row alone.
	 *
	 * @spec openspec/specs/contactmomenten/spec.md#requirement-direction-is-a-first-class-field-req-cmd-001
	 */
	public function directionFor(array $data): ?string {
		if ($this->hasKnownDirection(data: $data) === true) {
			return null;
		}

		$metadata = ($data['channelMetadata'] ?? []);
		if (is_object($metadata) === true) {
			$metadata = (array)$metadata;
		}

		if (is_array($metadata) === false) {
			return 'internal';
		}

		return $this->directionFromMetadata(metadata: $metadata);
	}//end directionFor()

	/**
	 * Whether the row already carries one of the known directions.
	 *
	 * @param array<string, mixed> $data The contact moment data.
	 *
	 * @return bool True when the direction is already set to a known value.
	 */
	private function hasKnownDirection(array $data): bool {
		$existing = ($data['direction'] ?? null);
		return is_string($existing) === true
			&& in_array($existing, TicketServiceInterface::DIRECTIONS, true) === true;
	}//end hasKnownDirection()

	/**
	 * Map the first recognised `direction`/`richting` metadata value, else `internal`.
	 *
	 * @param array<string, mixed> $metadata The channel metadata.
	 *
	 * @return string The mapped direction.
	 */
	private function directionFromMetadata(array $metadata): string {
		foreach (['direction', 'richting'] as $key) {
			$value = ($metadata[$key] ?? null);
			if (is_string($value) === false) {
				continue;
			}

			$mapped = (self::VOCABULARY[strtolower(trim($value))] ?? null);
			if ($mapped !== null) {
				return $mapped;
			}
		}

		return 'internal';
	}//end directionFromMetadata()

	/**
	 * Run the repair.
	 *
	 * @param IOutput $output Output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/contactmomenten/spec.md#requirement-direction-is-a-first-class-field-req-cmd-001
	 */
	public function run(IOutput $output): void {
		if ($this->ticketService->isConfigured() === false) {
			$output->info('Ticket surface not configured — skipping contact moment direction migration.');
			return;
		}

		$objectService = $this->ticketService->getObjectService();
		$register = $this->ticketService->getRegisterId();
		$schema = $this->ticketService->getSchemaId();
		$actingAdmin = $this->actingAdmin();

		$rows = $this->readContactMoments(objectService: $objectService, register: $register, schema: $schema);
		[$filled, $untouched] = $this->migrateRows(rows: $rows, target: [$objectService, $register, $schema, $actingAdmin]);

		$output->info(
			sprintf('Contact moment directions: %d filled, %d already set.', $filled, $untouched)
		);
	}//end run()

	/**
	 * Read one batch of contact moments with RBAC and multitenancy off.
	 *
	 * Read with RBAC off for the same reason NormaliseTicketTitle does: a
	 * repair step has no session, so an RBAC-filtered read would migrate
	 * only the rows 'Anonymous' may see and call that a complete run.
	 *
	 * @param mixed $objectService The OpenRegister ObjectService.
	 * @param mixed $register The ticket register id.
	 * @param mixed $schema The ticket schema id.
	 *
	 * @return iterable<mixed> The rows.
	 */
	private function readContactMoments($objectService, $register, $schema): iterable {
		return $objectService->findAll(
			config: [
				'filters' => [
					'register' => $register,
					'schema' => $schema,
					'ticketType' => TicketServiceInterface::TYPE_CONTACTMOMENT,
				],
				'limit' => self::BATCH_LIMIT,
			],
			_rbac: false,
			_multitenancy: false,
		);
	}//end readContactMoments()

	/**
	 * Fill the direction on every row that lacks one.
	 *
	 * @param iterable<mixed> $rows The contact moment rows.
	 * @param array{0: mixed, 1: mixed, 2: mixed, 3: IUser|null} $target The ObjectService, register, schema and acting admin.
	 *
	 * @return array{0: int, 1: int} The filled and already-set counts.
	 */
	private function migrateRows(iterable $rows, array $target): array {
		$filled = 0;
		$untouched = 0;
		foreach ($rows as $row) {
			$data = $this->toArray(row: $row);
			if ($data === null) {
				continue;
			}

			$direction = $this->directionFor(data: $data);
			if ($direction === null) {
				$untouched++;
				continue;
			}

			$data['direction'] = $direction;
			$data = $this->ticketService->sanitizeForSave(payload: $data);
			$filled += $this->saveMigrated(target: $target, data: $data);
		}

		return [$filled, $untouched];
	}//end migrateRows()

	/**
	 * Save one migrated contact moment as the acting admin; 1 when saved, 0 when it failed (logged).
	 *
	 * @param array{0: mixed, 1: mixed, 2: mixed, 3: IUser|null} $target The ObjectService, register, schema and acting admin.
	 * @param array<string, mixed> $data The migrated contact moment.
	 *
	 * @return int The number saved.
	 */
	private function saveMigrated(array $target, array $data): int {
		[$objectService, $register, $schema, $actingAdmin] = $target;
		try {
			$objectService->saveObject(
				object: $data,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: (string)($data['id'] ?? ''),
				_rbac: false,
				_multitenancy: false,
				currentUser: $actingAdmin,
			);
			return 1;
		} catch (Throwable $e) {
			$this->logger->error(
				'MigrateContactMomentDirection: failed to save contact moment',
				['uuid' => ($data['id'] ?? ''), 'exception' => $e->getMessage()]
			);
			return 0;
		}//end try
	}//end saveMigrated()

	/**
	 * Resolve an admin to act as while saving.
	 *
	 * A repair step has no session, so OpenRegister's folder ACL check has no
	 * acting user and denies the write for any ticket that owns a file folder.
	 *
	 * @return IUser|null The first admin, or null when none exists.
	 */
	private function actingAdmin(): ?IUser {
		$admins = $this->groupManager->get('admin')?->getUsers() ?? [];

		return (array_values($admins)[0] ?? null);
	}//end actingAdmin()

	/**
	 * Coerce an OpenRegister row into a plain array.
	 *
	 * @param mixed $row The row as returned by the object service.
	 *
	 * @return array<string, mixed>|null The row data, or null when unusable.
	 */
	private function toArray(mixed $row): ?array {
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
}//end class
