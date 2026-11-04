<?php

/**
 * Pipelinq References Audit Run
 *
 * One run of the references audit: the owner index it builds, the
 * classification of each satellite row and the writes it may make.
 *
 * @category Command
 * @package  OCA\Pipelinq\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Command;

use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Holds the object service, the console output and the owner index for one
 * run of {@see ReferencesAuditCommand}, so each step reads them from the run
 * instead of taking them as arguments.
 *
 * @spec openspec/specs/contract-renewal-tracking/spec.md
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment
 */
class ReferencesAuditRun {
	/**
	 * How many rows to read per page. Never "unlimited".
	 *
	 * @var int
	 */
	private const READ_BATCH_SIZE = 200;

	/**
	 * This app's own register slug.
	 *
	 * @var string
	 */
	public const REGISTER = 'pipelinq';

	/**
	 * The satellite schema this app owns.
	 *
	 * @var string
	 */
	public const SATELLITE_SCHEMA = 'salesContract';

	/**
	 * The property holding the owner's uuid.
	 *
	 * @var string
	 */
	private const REFERENCE_PROPERTY = 'contract';

	/**
	 * The owner's register slug.
	 *
	 * @var string
	 */
	private const OWNER_REGISTER = 'shillinq';

	/**
	 * The owner's schema.
	 *
	 * @var string
	 */
	private const OWNER_SCHEMA = 'Contract';

	/**
	 * The identity key both sides carry.
	 *
	 * @var string
	 */
	private const IDENTITY_KEY = 'contractNumber';

	/**
	 * Identity key to owner ids.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $owners = [];

	/**
	 * Every known owner id.
	 *
	 * @var array<string, bool>
	 */
	private array $ownerIds = [];

	/**
	 * Constructor.
	 *
	 * @param object          $objectService The OpenRegister object service.
	 * @param OutputInterface $output        Console output.
	 */
	public function __construct(
		private readonly object $objectService,
		private readonly OutputInterface $output,
	) {
	}//end __construct()

	/**
	 * Index every owner record by the identity key both sides carry.
	 *
	 * An owner register that cannot be read leaves the index empty, which
	 * classifyRow() reports as presence only.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/contract-renewal-tracking/spec.md
	 */
	public function indexOwners(): void {
		$owners = [];

		try {
			foreach ($this->readAll(self::OWNER_REGISTER, self::OWNER_SCHEMA) as $row) {
				$owner = $this->payload($row);
				$key = trim((string)($owner[self::IDENTITY_KEY] ?? ''));
				if ($key === '') {
					continue;
				}

				$owners[$key][] = (string)($owner['id'] ?? '');
			}
		} catch (Throwable $e) {
			// An absent shillinq is a normal state, not a fault. Say so
			// plainly: with no owner register there is nothing to resolve
			// against, and reporting every reference as dangling would be a lie.
			$this->output->writeln(
				'<comment>shillinq is not readable ('.$e->getMessage().'). '
				.'Nothing can be resolved or backfilled; reporting presence only.</comment>'
			);
			return;
		}//end try

		$this->owners = $owners;
		foreach ($owners as $ids) {
			foreach ($ids as $id) {
				$this->ownerIds[$id] = true;
			}
		}
	}//end indexOwners()

	/**
	 * Classify every satellite row, writing the unambiguous ones when allowed.
	 *
	 * @param array<int, mixed> $satellites The satellite rows.
	 * @param bool              $write      Whether this run may write.
	 *
	 * @return array<string, int> The per-outcome tally.
	 *
	 * @spec openspec/specs/contract-renewal-tracking/spec.md
	 */
	public function audit(array $satellites, bool $write): array {
		$counts = ['set' => 0, 'dangling' => 0, 'backfillable' => 0, 'ambiguous' => 0, 'unmatched' => 0, 'written' => 0];

		foreach ($satellites as $raw) {
			$row = $this->payload($raw);
			if ($row === []) {
				continue;
			}

			$verdict = $this->classifyRow($row);
			$counts[$verdict['outcome']]++;

			if ($verdict['message'] !== '') {
				$this->output->writeln($verdict['message']);
			}

			if ($verdict['outcome'] !== 'backfillable' || $write === false) {
				continue;
			}

			if ($this->backfill($verdict['id'], $verdict['owner']) === true) {
				$counts['written']++;
			}
		}//end foreach

		return $counts;
	}//end audit()

	/**
	 * Decide what one satellite row is, against the index of owners.
	 *
	 * EXTRACTED FROM execute(), which phpmd measured at cyclomatic complexity
	 * 22 against a threshold of 15. The branching was never incidental: this is
	 * a five-way classification, and naming it is what lets execute() read as
	 * "classify, report, maybe write". The ORDER the cases are tested in
	 * matters and is unchanged: a row carrying a reference is never a backfill
	 * candidate, regardless of what its identity key would have matched.
	 *
	 * @param array<string, mixed> $row One satellite row's payload.
	 *
	 * @return array{outcome: string, id: string, owner: string, message: string} The verdict.
	 */
	private function classifyRow(array $row): array {
		$id = (string)($row['id'] ?? '');
		$reference = trim((string)($row[self::REFERENCE_PROPERTY] ?? ''));
		$identity = trim((string)($row[self::IDENTITY_KEY] ?? ''));

		if ($reference !== '') {
			// An empty owner index means the owner could not be read at all.
			// That is reported as presence, never as a dangling link, or an
			// outage would look like data loss.
			if ($this->ownerIds === [] || isset($this->ownerIds[$reference]) === true) {
				return ['outcome' => 'set', 'id' => $id, 'owner' => '', 'message' => ''];
			}

			return [
				'outcome' => 'dangling',
				'id' => $id,
				'owner' => '',
				'message' => '  <error>dangling</error>  '.$id.' -> '.$reference,
			];
		}

		$candidates = ($this->owners[$identity] ?? []);
		if ($identity === '' || $candidates === []) {
			return ['outcome' => 'unmatched', 'id' => $id, 'owner' => '', 'message' => ''];
		}

		if (count($candidates) > 1) {
			return [
				'outcome' => 'ambiguous',
				'id' => $id,
				'owner' => '',
				'message' => '  <comment>ambiguous</comment> '.$id.' '.self::IDENTITY_KEY.'='.$identity
					.' matches '.count($candidates).' owners',
			];
		}

		return ['outcome' => 'backfillable', 'id' => $id, 'owner' => $candidates[0], 'message' => ''];
	}//end classifyRow()

	/**
	 * Write one unambiguous owner reference onto a satellite row.
	 *
	 * @param string $id The satellite row's id.
	 * @param string $owner The owner id to record.
	 *
	 * @return bool True when the write landed.
	 */
	private function backfill(string $id, string $owner): bool {
		try {
			// Patch, NOT saveObject/updateObject. Those two are PUT-semantic: a
			// property absent from the payload is written as null, so a
			// one-field update through them quietly clears every field the
			// read did not return.
			$this->objectService->patchObject(
				objectId: $id,
				data: [self::REFERENCE_PROPERTY => $owner],
				register: self::REGISTER,
				schema: self::SATELLITE_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
			return true;
		} catch (Throwable $e) {
			$this->output->writeln('  <error>write failed</error> '.$id.': '.$e->getMessage());
			return false;
		}
	}//end backfill()

	/**
	 * Print the one-line summary, and the nudge toward the write option.
	 *
	 * @param array<string, int> $counts The per-outcome tally.
	 * @param bool $write Whether this run was allowed to write.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/contract-renewal-tracking/spec.md
	 */
	public function report(array $counts, bool $write): void {
		$this->output->writeln('');
		$this->output->writeln(
			sprintf(
				'%s.%s -> %s.%s via %s: %d set, %d dangling, %d backfillable, %d ambiguous, %d unmatched, %d written',
				self::SATELLITE_SCHEMA,
				self::REFERENCE_PROPERTY,
				self::OWNER_REGISTER,
				self::OWNER_SCHEMA,
				self::IDENTITY_KEY,
				$counts['set'],
				$counts['dangling'],
				$counts['backfillable'],
				$counts['ambiguous'],
				$counts['unmatched'],
				$counts['written']
			)
		);

		if ($write === false && $counts['backfillable'] > 0) {
			$this->output->writeln('Re-run with the write option to fill in the '.$counts['backfillable'].' unambiguous match(es).');
		}
	}//end report()

	/**
	 * Read every row of a schema in explicit limit/offset pages.
	 *
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, mixed> Every row.
	 *
	 * @spec openspec/specs/contract-renewal-tracking/spec.md
	 */
	public function readAll(string $register, string $schema): array {
		$rows = [];
		$offset = 0;

		while (true) {
			$page = $this->objectService
				->setRegister($register)
				->setSchema($schema)
				->findAll(['limit' => self::READ_BATCH_SIZE, 'offset' => $offset]);

			if (is_array($page) === false || $page === []) {
				break;
			}

			foreach ($page as $row) {
				$rows[] = $row;
			}

			if (count($page) < self::READ_BATCH_SIZE) {
				break;
			}

			$offset += self::READ_BATCH_SIZE;
		}//end while

		return $rows;
	}//end readAll()

	/**
	 * Resolve one findAll() row to its schema payload.
	 *
	 * OpenRegister yields ObjectEntity instances whose payload lives behind
	 * jsonSerialize()/getObject(). A blind array cast yields mangled keys and
	 * loses every field, so a caller that casts reads garbage and silently
	 * mis-maps every row.
	 *
	 * @param mixed $row One result row.
	 *
	 * @return array<string, mixed> The payload, empty when unusable.
	 */
	private function payload(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === false) {
			return [];
		}

		foreach (['jsonSerialize', 'getObject'] as $method) {
			try {
				$out = $row->$method();
				if (is_array($out) === true) {
					return $out;
				}
			} catch (Throwable $e) {
				continue;
			}
		}

		return [];
	}//end payload()
}//end class
