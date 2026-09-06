<?php

/**
 * Pipelinq References Audit Command
 *
 * Audits, and on request backfills, the cross-app uuid reference that links a
 * pipelinq satellite record to the record another app owns.
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

use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `occ pipelinq:references:audit [--write]`.
 *
 * WHY READ-ONLY BY DEFAULT. The consolidation gave `salesContract` a plain uuid
 * pointing at shillinq's `Contract`, and nothing ever populated it.
 * Filling it in is a cross-app write, and a WRONG cross-app link is worse than
 * an empty one: an empty reference is visibly absent, a wrong one silently
 * attributes one record to another. So the default run reports and changes
 * nothing, the write option fills in only an UNAMBIGUOUS single match on the
 * shared identity key, and anything else is named rather than guessed.
 *
 * The identity key is not a convenience. It is the same key that decided the
 * consolidation in the first place: two records carrying one `contractNumber` are
 * one thing, which is precisely why the two schemas were merged onto an owner.
 *
 * @spec openspec/specs/contract-renewal-tracking/spec.md
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment
 */
class ReferencesAuditCommand extends Command {
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
	private const REGISTER = 'pipelinq';

	/**
	 * The satellite schema this app owns.
	 *
	 * @var string
	 */
	private const SATELLITE_SCHEMA = 'salesContract';

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
	 * Wire collaborators.
	 *
	 * @param ContainerInterface $container Container, for the lazy ObjectService resolve.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Declare the command.
	 *
	 * @return void
	 */
	protected function configure(): void {
		$this->setName('pipelinq:references:audit')
			->setDescription(
				'Audit the salesContract to shillinq Contract uuid reference. Read-only unless the write option is given.'
			)
			->addOption(
				'write',
				null,
				InputOption::VALUE_NONE,
				'Fill in the reference where exactly one owner record shares the identity key.'
			);
	}//end configure()

	/**
	 * Run the audit.
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 when no reference dangles, 1 when at least one does.
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$write = (bool)$input->getOption('write');

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$satellites = $this->readAll($objectService, self::REGISTER, self::SATELLITE_SCHEMA);
		} catch (Throwable $e) {
			$output->writeln('<error>could not read '.self::SATELLITE_SCHEMA.': '.$e->getMessage().'</error>');
			return 1;
		}

		[$owners, $ownerIds] = $this->ownerIndex($objectService, $output);
		$counts = $this->auditRows($objectService, $satellites, $owners, $ownerIds, $write, $output);
		$this->report($output, $counts, $write);

		if ($counts['dangling'] > 0) {
			return 1;
		}

		return 0;
	}//end execute()

	/**
	 * Classify every satellite row, writing the unambiguous ones when allowed.
	 *
	 * EXTRACTED FROM execute() alongside classifyRow(), because phpmd measured
	 * the original at cyclomatic complexity 22 against a threshold of 10 here.
	 * Splitting the loop out is not cosmetic: execute() now reads as "read,
	 * index, audit, report", and each of those four is separately testable.
	 *
	 * @param mixed $objectService The OpenRegister object service.
	 * @param array<int, mixed> $satellites The satellite rows.
	 * @param array<string, array<int, string>> $owners Identity key to owner ids.
	 * @param array<string, bool> $ownerIds Every known owner id.
	 * @param bool $write Whether this run may write.
	 * @param OutputInterface $output Console output.
	 *
	 * @return array<string, int> The per-outcome tally.
	 */
	private function auditRows(
		mixed $objectService,
		array $satellites,
		array $owners,
		array $ownerIds,
		bool $write,
		OutputInterface $output,
	): array {
		$counts = ['set' => 0, 'dangling' => 0, 'backfillable' => 0, 'ambiguous' => 0, 'unmatched' => 0, 'written' => 0];

		foreach ($satellites as $raw) {
			$row = $this->payload($raw);
			if ($row === []) {
				continue;
			}

			$verdict = $this->classifyRow($row, $owners, $ownerIds);
			$counts[$verdict['outcome']]++;

			if ($verdict['message'] !== '') {
				$output->writeln($verdict['message']);
			}

			if ($verdict['outcome'] !== 'backfillable' || $write === false) {
				continue;
			}

			if ($this->backfill($objectService, $verdict['id'], $verdict['owner'], $output) === true) {
				$counts['written']++;
			}
		}//end foreach

		return $counts;
	}//end auditRows()

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
	 * @param array<string, array<int, string>> $owners Identity key to owner ids.
	 * @param array<string, bool> $ownerIds Every known owner id.
	 *
	 * @return array{outcome: string, id: string, owner: string, message: string} The verdict.
	 */
	private function classifyRow(array $row, array $owners, array $ownerIds): array {
		$id = (string)($row['id'] ?? '');
		$reference = trim((string)($row[self::REFERENCE_PROPERTY] ?? ''));
		$identity = trim((string)($row[self::IDENTITY_KEY] ?? ''));

		if ($reference !== '') {
			// An empty owner index means the owner could not be read at all.
			// That is reported as presence, never as a dangling link, or an
			// outage would look like data loss.
			if ($ownerIds === [] || isset($ownerIds[$reference]) === true) {
				return ['outcome' => 'set', 'id' => $id, 'owner' => '', 'message' => ''];
			}

			return [
				'outcome' => 'dangling',
				'id' => $id,
				'owner' => '',
				'message' => '  <error>dangling</error>  '.$id.' -> '.$reference,
			];
		}

		$candidates = ($owners[$identity] ?? []);
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
	 * @param mixed $objectService The OpenRegister object service.
	 * @param string $id The satellite row's id.
	 * @param string $owner The owner id to record.
	 * @param OutputInterface $output Console output.
	 *
	 * @return bool True when the write landed.
	 */
	private function backfill(mixed $objectService, string $id, string $owner, OutputInterface $output): bool {
		try {
			// Patch, NOT saveObject/updateObject. Those two are PUT-semantic: a
			// property absent from the payload is written as null, so a
			// one-field update through them quietly clears every field the
			// read did not return.
			$objectService->patchObject(
				objectId: $id,
				data: [self::REFERENCE_PROPERTY => $owner],
				register: self::REGISTER,
				schema: self::SATELLITE_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
			return true;
		} catch (Throwable $e) {
			$output->writeln('  <error>write failed</error> '.$id.': '.$e->getMessage());
			return false;
		}
	}//end backfill()

	/**
	 * Index every owner record by the identity key both sides carry.
	 *
	 * @param mixed $objectService The OpenRegister object service.
	 * @param OutputInterface $output Console output.
	 *
	 * @return array{0: array<string, array<int, string>>, 1: array<string, bool>} The index and the id set.
	 */
	private function ownerIndex(mixed $objectService, OutputInterface $output): array {
		$owners = [];

		try {
			foreach ($this->readAll($objectService, self::OWNER_REGISTER, self::OWNER_SCHEMA) as $row) {
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
			$output->writeln(
				'<comment>shillinq is not readable ('.$e->getMessage().'). '
				.'Nothing can be resolved or backfilled; reporting presence only.</comment>'
			);
			return [[], []];
		}//end try

		$ownerIds = [];
		foreach ($owners as $ids) {
			foreach ($ids as $id) {
				$ownerIds[$id] = true;
			}
		}

		return [$owners, $ownerIds];
	}//end ownerIndex()

	/**
	 * Print the one-line summary, and the nudge toward the write option.
	 *
	 * @param OutputInterface $output Console output.
	 * @param array<string, int> $counts The per-outcome tally.
	 * @param bool $write Whether this run was allowed to write.
	 *
	 * @return void
	 */
	private function report(OutputInterface $output, array $counts, bool $write): void {
		$output->writeln('');
		$output->writeln(
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
			$output->writeln('Re-run with the write option to fill in the '.$counts['backfillable'].' unambiguous match(es).');
		}
	}//end report()

	/**
	 * Read every row of a schema in explicit limit/offset pages.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, mixed> Every row.
	 */
	private function readAll(object $objectService, string $register, string $schema): array {
		$rows = [];
		$offset = 0;

		while (true) {
			$page = $objectService
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
