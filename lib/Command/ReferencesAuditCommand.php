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
		} catch (Throwable $e) {
			$output->writeln('<error>OpenRegister is not available: '.$e->getMessage().'</error>');
			return 1;
		}

		try {
			$satellites = $this->readAll($objectService, self::REGISTER, self::SATELLITE_SCHEMA);
		} catch (Throwable $e) {
			$output->writeln('<error>could not read '.self::SATELLITE_SCHEMA.': '.$e->getMessage().'</error>');
			return 1;
		}

		$owners = [];
		$ownerReadable = true;
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
			$ownerReadable = false;
		}//end try

		$ownerIds = [];
		foreach ($owners as $ids) {
			foreach ($ids as $id) {
				$ownerIds[$id] = true;
			}
		}

		$counts = ['set' => 0, 'dangling' => 0, 'backfillable' => 0, 'ambiguous' => 0, 'unmatched' => 0, 'written' => 0];

		foreach ($satellites as $raw) {
			$row = $this->payload($raw);
			if ($row === []) {
				continue;
			}

			$id = (string)($row['id'] ?? '');
			$reference = trim((string)($row[self::REFERENCE_PROPERTY] ?? ''));
			$identity = trim((string)($row[self::IDENTITY_KEY] ?? ''));

			if ($reference !== '') {
				if ($ownerReadable === false || isset($ownerIds[$reference]) === true) {
					$counts['set']++;
					continue;
				}

				$counts['dangling']++;
				$output->writeln('  <error>dangling</error>  '.$id.' -> '.$reference);
				continue;
			}

			$candidates = ($owners[$identity] ?? []);
			if ($identity === '' || $candidates === []) {
				$counts['unmatched']++;
				continue;
			}

			if (count($candidates) > 1) {
				$counts['ambiguous']++;
				$output->writeln(
					'  <comment>ambiguous</comment> '.$id.' '.self::IDENTITY_KEY.'='.$identity
					.' matches '.count($candidates).' owners'
				);
				continue;
			}

			$counts['backfillable']++;
			if ($write === false) {
				continue;
			}

			try {
				// Patch, NOT saveObject/updateObject. Those two are
				// PUT-semantic: a property absent from the payload is written
				// as null, so a one-field update through them quietly clears
				// every field the read did not return.
				$objectService->patchObject(
					objectId: $id,
					data: [self::REFERENCE_PROPERTY => $candidates[0]],
					register: self::REGISTER,
					schema: self::SATELLITE_SCHEMA,
					_rbac: false,
					_multitenancy: false
				);
				$counts['written']++;
			} catch (Throwable $e) {
				$output->writeln('  <error>write failed</error> '.$id.': '.$e->getMessage());
			}
		}//end foreach

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

		if ($counts['dangling'] > 0) {
			return 1;
		}

		return 0;
	}//end execute()

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
