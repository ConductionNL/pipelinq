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
 * @spec openspec/changes/contact-moments-on-pipelinq-schema/specs/contactmomenten/spec.md#requirement-direction-is-a-first-class-field-req-cmd-001
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
 * Repair step: give every existing contact moment a first-class direction.
 *
 * @spec openspec/changes/contact-moments-on-pipelinq-schema/specs/contactmomenten/spec.md#requirement-direction-is-a-first-class-field-req-cmd-001
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
	 * @param TicketService $ticketService Resolver for the unified `ticket` supertype.
	 * @param IGroupManager $groupManager Group manager, used to resolve an acting admin.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private TicketService $ticketService,
		private IGroupManager $groupManager,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the repair step name.
	 *
	 * @return string Name.
	 *
	 * @spec openspec/changes/contact-moments-on-pipelinq-schema/specs/contactmomenten/spec.md#requirement-direction-is-a-first-class-field-req-cmd-001
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
	 * @spec openspec/changes/contact-moments-on-pipelinq-schema/specs/contactmomenten/spec.md#requirement-direction-is-a-first-class-field-req-cmd-001
	 */
	public function directionFor(array $data): ?string {
		$existing = ($data['direction'] ?? null);
		if (is_string($existing) === true
			&& in_array($existing, TicketService::DIRECTIONS, true) === true
		) {
			return null;
		}

		$metadata = ($data['channelMetadata'] ?? []);
		if (is_object($metadata) === true) {
			$metadata = (array)$metadata;
		}

		if (is_array($metadata) === false) {
			return 'internal';
		}

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
	}//end directionFor()

	/**
	 * Run the repair.
	 *
	 * @param IOutput $output Output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/contact-moments-on-pipelinq-schema/specs/contactmomenten/spec.md#requirement-direction-is-a-first-class-field-req-cmd-001
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

		$filled = 0;
		$untouched = 0;

		// Read with RBAC off for the same reason NormaliseTicketTitle does: a
		// repair step has no session, so an RBAC-filtered read would migrate
		// only the rows 'Anonymous' may see and call that a complete run.
		$rows = $objectService->findAll(
			config: [
				'filters' => [
					'register' => $register,
					'schema' => $schema,
					'ticketType' => TicketService::TYPE_CONTACTMOMENT,
				],
				'limit' => self::BATCH_LIMIT,
			],
			_rbac: false,
			_multitenancy: false,
		);

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
				$filled++;
			} catch (Throwable $e) {
				$this->logger->error(
					'MigrateContactMomentDirection: failed to save contact moment',
					['uuid' => ($data['id'] ?? ''), 'exception' => $e->getMessage()]
				);
			}//end try
		}//end foreach

		$output->info(
			sprintf('Contact moment directions: %d filled, %d already set.', $filled, $untouched)
		);
	}//end run()

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
