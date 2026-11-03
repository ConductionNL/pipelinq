<?php

/**
 * Golden test for the references audit command.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Command;

use OCA\Pipelinq\Command\ReferencesAuditCommand;
use OCA\Pipelinq\Tests\Unit\Support\ComparesGolden;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Runs pipelinq:references:audit over read-only and write runs: the exit code,
 * the whole console output and every call made to the object service (reads
 * with their page, patches with every argument), in order. Covers paging past
 * one batch, entity rows, unreadable rows, an unreadable owner register, an
 * unavailable object service and failing writes. Compared with
 * ReferencesAuditCommandGolden.json; set GOLDEN_RECORD=1 to rewrite it, then
 * run once more without it.
 *
 * @covers \OCA\Pipelinq\Command\ReferencesAuditCommand
 */
class ReferencesAuditCommandGoldenTest extends TestCase {
	use ComparesGolden;

	/**
	 * What the current case called on the object service, in order.
	 *
	 * @var array<int, mixed>
	 */
	public array $calls = [];

	/**
	 * Run every case and compare it with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			foreach ([false, true] as $write) {
				$actual[$name.($write === true ? ' (write)' : '')] = $this->runCase(case: $case, write: $write);
			}
		}

		$this->assertGolden(file: __DIR__.'/ReferencesAuditCommandGolden.json', actual: $actual);
	}//end testGolden()

	/**
	 * The cases: satellite and owner rows and what fails.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$owners = [
			['id' => 'o-1', 'contractNumber' => 'C-1'],
			['id' => 'o-2', 'contractNumber' => ' C-2 '],
			['id' => 'o-3a', 'contractNumber' => 'C-3'],
			['id' => 'o-3b', 'contractNumber' => 'C-3'],
			['id' => 'o-blank', 'contractNumber' => '  '],
			['contractNumber' => 'C-noid'],
		];
		$satellites = [
			['id' => 's-set', 'contract' => 'o-1', 'contractNumber' => 'C-1'],
			['id' => 's-dangling', 'contract' => 'o-gone'],
			['id' => 's-spaces', 'contract' => '  ', 'contractNumber' => 'C-1'],
			['id' => 's-backfill', 'contractNumber' => 'C-1'],
			['id' => 's-trim', 'contractNumber' => 'C-2'],
			['id' => 's-ambiguous', 'contractNumber' => 'C-3'],
			['id' => 's-unmatched', 'contractNumber' => 'C-9'],
			['id' => 's-nokey'],
			['contractNumber' => 'C-1'],
			['id' => 's-noid-owner', 'contractNumber' => 'C-noid'],
			[],
			'not a row',
		];
		$many = [];
		for ($i = 0; $i < 205; $i++) {
			$many[] = ['id' => 's-'.$i, 'contractNumber' => ($i % 2 === 0 ? 'C-1' : 'C-9')];
		}

		return [
			'mixed rows'                  => ['satellites' => $satellites, 'owners' => $owners],
			'entity rows'                 => ['satellites' => $satellites, 'owners' => $owners, 'entities' => true],
			'no satellites'               => ['satellites' => [], 'owners' => $owners],
			'no owners'                   => ['satellites' => $satellites, 'owners' => []],
			'owner register unreadable'   => ['satellites' => $satellites, 'owners' => $owners, 'ownerThrows' => true],
			'satellites unreadable'       => ['satellites' => $satellites, 'owners' => $owners, 'satelliteThrows' => true],
			'object service unavailable'  => ['satellites' => $satellites, 'owners' => $owners, 'noService' => true],
			'writes fail'                 => ['satellites' => $satellites, 'owners' => $owners, 'patchThrows' => true],
			'two pages'                   => ['satellites' => $many, 'owners' => $owners],
			'only dangling'               => ['satellites' => [['id' => 's-x', 'contract' => 'o-x']], 'owners' => $owners],
		];
	}//end cases()

	/**
	 * Run one case.
	 *
	 * @param array<string, mixed> $case  The case.
	 * @param bool                 $write Whether the write option is given.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(array $case, bool $write): array {
		$this->calls = [];
		$service = $this->objectService(case: $case);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($case, $service): object {
				if (($case['noService'] ?? false) === true) {
					throw new RuntimeException('OpenRegister is not installed');
				}

				return $service;
			}
		);

		$tester = new CommandTester(new ReferencesAuditCommand($container));
		$options = [];
		if ($write === true) {
			$options['--write'] = true;
		}

		$code = $tester->execute($options);
		return ['exit' => $code, 'output' => explode("\n", $tester->getDisplay()), 'calls' => $this->calls];
	}//end runCase()

	/**
	 * A fake object service over the case's rows, recording every call.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return object
	 */
	private function objectService(array $case): object {
		return new class($case, $this) {
			private string $schema = '';

			/**
			 * @param array<string, mixed>             $case The case.
			 * @param ReferencesAuditCommandGoldenTest $test The test, for call capture.
			 */
			public function __construct(private array $case, private ReferencesAuditCommandGoldenTest $test) {
			}

			/**
			 * @param string $register The register slug.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				$this->test->calls[] = ['setRegister' => $register];
				return $this;
			}

			/**
			 * @param string $schema The schema slug.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->test->calls[] = ['setSchema' => $schema];
				$this->schema = $schema;
				return $this;
			}

			/**
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $config): array {
				$this->test->calls[] = ['findAll' => $config];
				$owner = ($this->schema === 'Contract');
				if (($this->case[$owner === true ? 'ownerThrows' : 'satelliteThrows'] ?? false) === true) {
					throw new RuntimeException($owner === true ? 'shillinq is not installed' : 'no salesContract schema');
				}

				$rows = array_slice($this->case[$owner === true ? 'owners' : 'satellites'], (int)$config['offset'], (int)$config['limit']);
				if (($this->case['entities'] ?? false) === true) {
					return array_map([$this, 'entity'], $rows, array_keys($rows));
				}

				return $rows;
			}

			/**
			 * An entity-like row: payload behind jsonSerialize() or getObject(), or neither.
			 *
			 * @param mixed $row   The row.
			 * @param int   $index Its index.
			 *
			 * @return mixed
			 */
			public function entity(mixed $row, int $index): mixed {
				if (is_array($row) === false) {
					return $row;
				}

				if ($index % 3 === 0) {
					return new class($row) {
						/**
						 * @param array<string, mixed> $row The payload.
						 */
						public function __construct(private array $row) {
						}

						/**
						 * @return array<string, mixed>
						 */
						public function jsonSerialize(): array {
							return $this->row;
						}
					};
				}

				if ($index % 3 === 1) {
					return new class($row) {
						/**
						 * @param array<string, mixed> $row The payload.
						 */
						public function __construct(private array $row) {
						}

						/**
						 * @return string
						 */
						public function jsonSerialize(): string {
							return 'not an array';
						}

						/**
						 * @return array<string, mixed>
						 */
						public function getObject(): array {
							return $this->row;
						}
					};
				}

				return (object)$row;
			}

			/**
			 * @param string      $objectId      The object id.
			 * @param array       $data          The patch.
			 * @param string|null $register      The register.
			 * @param string|null $schema        The schema.
			 * @param bool        $_rbac         RBAC flag.
			 * @param bool        $_multitenancy Multitenancy flag.
			 *
			 * @return array<string, mixed>
			 */
			public function patchObject(string $objectId, array $data, ?string $register = null, ?string $schema = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->test->calls[] = ['patchObject' => [$objectId, $data, $register, $schema, $_rbac, $_multitenancy]];
				if (($this->case['patchThrows'] ?? false) === true) {
					throw new RuntimeException('locked: '.$objectId);
				}

				return $data;
			}
		};
	}//end objectService()
}//end class
