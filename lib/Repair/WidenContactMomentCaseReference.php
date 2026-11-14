<?php

/**
 * Pipelinq WidenContactMomentCaseReference.
 *
 * Turns the single case reference on an existing contact moment into the
 * one-element ordered set `one-contact-moment-on-several-cases` introduced,
 * and names that one case as the primary.
 *
 * The step is idempotent: a row that already carries a non-empty
 * `caseReferences` is left untouched, so a second run rewrites nothing. A
 * contact moment with no case at all is left alone as well: the set has a
 * minimum of one and this step is a migration, not a place to invent a case.
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
 * @spec openspec/specs/contactmomenten/spec.md#requirement-a-contact-moment-references-a-case-semantically-req-cmd-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\TicketServiceInterface;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repair step: widen one case reference into a one-element set.
 *
 * @spec openspec/specs/contactmomenten/spec.md#requirement-a-contact-moment-references-a-case-semantically-req-cmd-002
 */
class WidenContactMomentCaseReference implements IRepairStep {
	/**
	 * Upper bound on rows fetched in one pass.
	 *
	 * @var int
	 */
	private const BATCH_LIMIT = 10000;

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
	 * @spec openspec/specs/contactmomenten/spec.md#requirement-a-contact-moment-references-a-case-semantically-req-cmd-002
	 */
	public function getName(): string {
		return 'Widen a contact moment\'s single case reference into a one-element set';
	}//end getName()

	/**
	 * The fields to write on a stored contact moment, or null to leave it.
	 *
	 * Returning null on a row that already carries a set is what makes the
	 * step idempotent, and returning null on a row with no case at all is what
	 * keeps it a migration rather than a guess.
	 *
	 * @param array<string, mixed> $data The stored contact moment.
	 *
	 * @return array<string, mixed>|null The fields to write, or null.
	 *
	 * @spec openspec/specs/contactmomenten/spec.md#requirement-a-contact-moment-references-a-case-semantically-req-cmd-002
	 */
	public function widenedFields(array $data): ?array {
		$set = ($data['caseReferences'] ?? null);
		if (is_array($set) === true && $set !== []) {
			return null;
		}

		$single = trim((string)($data['caseReference'] ?? ''));
		if ($single === '') {
			return null;
		}

		return [
			'caseReferences' => [$single],
			'primaryCaseReference' => $single,
		];
	}//end widenedFields()

	/**
	 * Run the repair.
	 *
	 * @param IOutput $output Output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/contactmomenten/spec.md#requirement-a-contact-moment-references-a-case-semantically-req-cmd-002
	 */
	public function run(IOutput $output): void {
		if ($this->ticketService->isConfigured() === false) {
			$output->info('Ticket surface not configured — skipping the case reference widening.');
			return;
		}

		$objectService = $this->ticketService->getObjectService();
		$register = $this->ticketService->getRegisterId();
		$schema = $this->ticketService->getSchemaId();
		$actingAdmin = $this->actingAdmin();

		$rows = $this->contactMomentRows(objectService: $objectService, register: $register, schema: $schema);
		$save = $this->momentSaver(objectService: $objectService, register: $register, schema: $schema, actingAdmin: $actingAdmin);
		$counts = $this->widenRows(rows: $rows, save: $save);

		$output->info(sprintf('Contact moment case sets: %d widened, %d already a set.', $counts['widened'], $counts['untouched']));
	}//end run()

	/**
	 * Fetch every stored contact moment, with RBAC off.
	 *
	 * @param ObjectServiceInterface $objectService The OR object service.
	 * @param string $register The register id.
	 * @param string $schema The ticket schema id.
	 *
	 * @return array<mixed>
	 *
	 * @spec openspec/specs/contactmomenten/spec.md#requirement-a-contact-moment-references-a-case-semantically-req-cmd-002
	 */
	private function contactMomentRows(ObjectServiceInterface $objectService, string $register, string $schema): array {
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
	}//end contactMomentRows()

	/**
	 * Build the writer that saves one widened contact moment as the acting admin.
	 *
	 * @param ObjectServiceInterface $objectService The OR object service.
	 * @param string $register The register id.
	 * @param string $schema The ticket schema id.
	 * @param IUser|null $actingAdmin The admin to act as, or null when none exists.
	 *
	 * @return callable(array<string, mixed>): mixed
	 *
	 * @spec openspec/specs/contactmomenten/spec.md#requirement-a-contact-moment-references-a-case-semantically-req-cmd-002
	 */
	private function momentSaver(ObjectServiceInterface $objectService, string $register, string $schema, IUser|null $actingAdmin): callable {
		return static fn (array $data): mixed => $objectService->saveObject(
			object: $data,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: (string)($data['id'] ?? ''),
			_rbac: false,
			_multitenancy: false,
			currentUser: $actingAdmin,
		);
	}//end momentSaver()

	/**
	 * Widen every row and count the outcomes.
	 *
	 * @param array<mixed> $rows The stored contact moments.
	 * @param callable(array<string, mixed>): mixed $save The row writer.
	 *
	 * @return array{widened: int, untouched: int}
	 *
	 * @spec openspec/specs/contactmomenten/spec.md#requirement-a-contact-moment-references-a-case-semantically-req-cmd-002
	 */
	private function widenRows(array $rows, callable $save): array {
		$counts = ['widened' => 0, 'untouched' => 0];
		foreach ($rows as $row) {
			$outcome = $this->widenRow(row: $row, save: $save);
			if ($outcome !== null) {
				$counts[$outcome]++;
			}
		}

		return $counts;
	}//end widenRows()

	/**
	 * Widen one stored contact moment.
	 *
	 * @param mixed $row The row as returned by the object service.
	 * @param callable(array<string, mixed>): mixed $save The row writer.
	 *
	 * @return 'widened'|'untouched'|null The counter to raise, or null when neither applies.
	 *
	 * @spec openspec/specs/contactmomenten/spec.md#requirement-a-contact-moment-references-a-case-semantically-req-cmd-002
	 */
	private function widenRow(mixed $row, callable $save): string|null {
		$data = $this->toArray(row: $row);
		if ($data === null) {
			return null;
		}

		$fields = $this->widenedFields(data: $data);
		if ($fields === null) {
			return 'untouched';
		}

		$data = $this->ticketService->sanitizeForSave(payload: array_merge($data, $fields));

		return $this->saveMoment(data: $data, save: $save);
	}//end widenRow()

	/**
	 * Save one widened contact moment, logging a failure.
	 *
	 * @param array<string, mixed> $data The contact moment data.
	 * @param callable(array<string, mixed>): mixed $save The row writer.
	 *
	 * @return 'widened'|null
	 *
	 * @spec openspec/specs/contactmomenten/spec.md#requirement-a-contact-moment-references-a-case-semantically-req-cmd-002
	 */
	private function saveMoment(array $data, callable $save): string|null {
		try {
			$save($data);
			return 'widened';
		} catch (Throwable $e) {
			$this->logger->error(
				'WidenContactMomentCaseReference: failed to save contact moment',
				['uuid' => ($data['id'] ?? ''), 'exception' => $e->getMessage()]
			);
			return null;
		}//end try
	}//end saveMoment()

	/**
	 * Resolve an admin to act as while saving.
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
