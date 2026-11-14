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
 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-lossless-migration-of-existing-records
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
 * Repair step: unwrap translation-map ticket titles into plain strings.
 *
 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-lossless-migration-of-existing-records
 */
class NormaliseTicketTitle implements IRepairStep {
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
	 * Resolve an admin to act as while saving.
	 *
	 * A repair step has no session, so OpenRegister's folder ACL check has no
	 * acting user and denies the write for any ticket that owns a file folder
	 * ("Access to folder 'N' is denied for the acting user"). Saving as an admin
	 * gives that check someone to authorise, without loosening the check itself.
	 *
	 * @return IUser|null The first admin, or null when none exists.
	 */
	private function actingAdmin(): ?IUser {
		$admins = $this->groupManager->get('admin')?->getUsers() ?? [];

		return (array_values($admins)[0] ?? null);
	}//end actingAdmin()

	/**
	 * Get the repair step name.
	 *
	 * @return string Name.
	 * @spec openspec/specs/repair-steps/spec.md
	 */
	public function getName(): string {
		return 'Normalise ticket titles stored as translation maps into plain strings';
	}//end getName()

	/**
	 * Run the repair.
	 *
	 * @param IOutput $output Output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-lossless-migration-of-existing-records
	 */
	public function run(IOutput $output): void {
		if ($this->ticketService->isConfigured() === false) {
			$output->info('Ticket surface not configured — skipping title normalisation.');
			return;
		}

		[$fetchRows, $saveRow] = $this->repairSteps();
		$counts = ['repaired' => 0, 'skipped' => 0];

		foreach (TicketServiceInterface::TYPES as $ticketType) {
			$counts = $this->normaliseRows(rows: $fetchRows($ticketType), save: $saveRow, counts: $counts);
		}

		$output->info(
			sprintf('Ticket titles normalised: %d rewritten, %d already plain.', $counts['repaired'], $counts['skipped'])
		);
	}//end run()

	/**
	 * Build the row reader and row writer the repair runs with.
	 *
	 * @return array{0: callable(string): array<mixed>, 1: callable(array<string, mixed>): mixed}
	 *
	 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-lossless-migration-of-existing-records
	 */
	private function repairSteps(): array {
		$objectService = $this->ticketService->getObjectService();
		$register = $this->ticketService->getRegisterId();
		$schema = $this->ticketService->getSchemaId();
		$actingAdmin = $this->actingAdmin();

		return [
			$this->rowFetcher(objectService: $objectService, register: $register, schema: $schema),
			$this->titleSaver(objectService: $objectService, register: $register, schema: $schema, actingAdmin: $actingAdmin),
		];
	}//end repairSteps()

	/**
	 * Build the reader that fetches all rows of one ticket subtype.
	 *
	 * @param ObjectServiceInterface $objectService The OR object service.
	 * @param string $register The register id.
	 * @param string $schema The schema id.
	 *
	 * @return callable(string): array<mixed>
	 *
	 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-lossless-migration-of-existing-records
	 */
	private function rowFetcher(ObjectServiceInterface $objectService, string $register, string $schema): callable {
		// Read with RBAC off for the same reason we write with it off: on the
		// CLI there is no session, so an RBAC-filtered findAll returns only
		// the handful of objects 'Anonymous' may read and the repair would
		// silently normalise a fraction of the rows.
		return static fn (string $ticketType): array => $objectService->findAll(
			config: [
				'filters' => [
					'register' => $register,
					'schema' => $schema,
					'ticketType' => $ticketType,
				],
				'limit' => self::BATCH_LIMIT,
			],
			_rbac: false,
			_multitenancy: false,
		);
	}//end rowFetcher()

	/**
	 * Build the writer that saves one normalised row as the acting admin.
	 *
	 * @param ObjectServiceInterface $objectService The OR object service.
	 * @param string $register The register id.
	 * @param string $schema The schema id.
	 * @param IUser|null $actingAdmin The admin to act as, or null when none exists.
	 *
	 * @return callable(array<string, mixed>): mixed
	 *
	 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-lossless-migration-of-existing-records
	 */
	private function titleSaver(ObjectServiceInterface $objectService, string $register, string $schema, IUser|null $actingAdmin): callable {
		// Bypass RBAC here — and ONLY here: this is a system context
		// reshaping data it already owns. TicketService::save() keeps
		// RBAC on, because controllers call it.
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
	}//end titleSaver()

	/**
	 * Normalise every row of one subtype and add the outcomes to the counts.
	 *
	 * @param array<mixed> $rows The fetched rows.
	 * @param callable(array<string, mixed>): mixed $save The row writer.
	 * @param array{repaired: int, skipped: int} $counts The running counts.
	 *
	 * @return array{repaired: int, skipped: int}
	 *
	 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-lossless-migration-of-existing-records
	 */
	private function normaliseRows(array $rows, callable $save, array $counts): array {
		foreach ($rows as $row) {
			$outcome = $this->normaliseRow(row: $row, save: $save);
			if ($outcome !== null) {
				$counts[$outcome]++;
			}
		}

		return $counts;
	}//end normaliseRows()

	/**
	 * Normalise one row's title.
	 *
	 * @param mixed $row The row as returned by the object service.
	 * @param callable(array<string, mixed>): mixed $save The row writer.
	 *
	 * @return 'repaired'|'skipped'|null The counter to raise, or null when neither applies.
	 *
	 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-lossless-migration-of-existing-records
	 */
	private function normaliseRow(mixed $row, callable $save): string|null {
		$data = $this->toArray(row: $row);
		if ($data === null) {
			return null;
		}

		$title = ($data['title'] ?? null);
		if (is_array($title) === false) {
			return 'skipped';
		}

		return $this->rewriteTitle(data: $data, title: $title, save: $save);
	}//end normaliseRow()

	/**
	 * Collapse a row's translation-map title and save the row.
	 *
	 * @param array<string, mixed> $data The row data.
	 * @param array<string, mixed> $title The stored translation map.
	 * @param callable(array<string, mixed>): mixed $save The row writer.
	 *
	 * @return 'repaired'|null 'repaired' when saved, null when left untouched or the save failed.
	 *
	 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-lossless-migration-of-existing-records
	 */
	private function rewriteTitle(array $data, array $title, callable $save): string|null {
		$plain = $this->collapse(map: $title);
		if ($plain === null) {
			$this->logger->warning('NormaliseTicketTitle: unusable title map, left untouched', ['uuid' => ($data['id'] ?? '')]);
			return null;
		}

		$data['title'] = $plain;

		// Same seam the app's writes use: OpenRegister hands date-times
		// back as `Y-m-d H:i:s`, which fails its own `format: date-time`
		// on the way in. Without this the save is rejected and the title
		// never lands.
		$data = $this->ticketService->sanitizeForSave(payload: $data);

		return $this->saveRow(data: $data, save: $save);
	}//end rewriteTitle()

	/**
	 * Save one normalised row, logging a failure.
	 *
	 * @param array<string, mixed> $data The row data.
	 * @param callable(array<string, mixed>): mixed $save The row writer.
	 *
	 * @return 'repaired'|null
	 *
	 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-lossless-migration-of-existing-records
	 */
	private function saveRow(array $data, callable $save): string|null {
		try {
			$save($data);
			return 'repaired';
		} catch (Throwable $e) {
			$this->logger->error(
				'NormaliseTicketTitle: failed to save ticket',
				['uuid' => ($data['id'] ?? ''), 'exception' => $e->getMessage()]
			);
			return null;
		}//end try
	}//end saveRow()

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

	/**
	 * Collapse a translation map into the single string it wraps.
	 *
	 * @param array<string, mixed> $map The stored translation map.
	 *
	 * @return string|null The plain title, or null when no usable value exists.
	 */
	private function collapse(array $map): ?string {
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
