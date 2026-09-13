<?php

/**
 * ReferencesAuditCommand Unit Tests
 *
 * @category Tests
 * @package  OCA\Pipelinq\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Command;

use OCA\Pipelinq\Command\ReferencesAuditCommand;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The audit reports, and only ever writes an unambiguous match.
 *
 * A wrong cross-app link is worse than an empty one: an empty reference is
 * visibly absent, a wrong one silently attributes one record to another. Every
 * test below pins one refusal.
 *
 * @covers \OCA\Pipelinq\Command\ReferencesAuditCommand
 */
class ReferencesAuditCommandTest extends TestCase {
	/**
	 * Writes the fake ObjectService received, so a test can assert that a
	 * read-only run wrote nothing at all.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $writes = [];

	/**
	 * Build the command over a fake ObjectService.
	 *
	 * @param array<int, array<string, mixed>> $satellites Satellite rows.
	 * @param array<int, array<string, mixed>> $owners Owner rows.
	 * @param bool $ownerThrows Whether the owner register is unreadable.
	 *
	 * @return CommandTester The tester.
	 */
	private function tester(array $satellites, array $owners, bool $ownerThrows = false): CommandTester {
		$test = $this;
		$objectService = new class($satellites, $owners, $ownerThrows, $test) {
			private string $schema = '';

			/**
			 * @param array<int, array<string, mixed>> $satellites Satellite rows.
			 * @param array<int, array<string, mixed>> $owners Owner rows.
			 * @param bool $ownerThrows Whether reading owners throws.
			 * @param ReferencesAuditCommandTest $test The test, for write capture.
			 */
			public function __construct(
				private array $satellites,
				private array $owners,
				private bool $ownerThrows,
				private ReferencesAuditCommandTest $test,
			) {
			}

			/**
			 * @param string $register The register slug.
			 *
			 * @return self Fluent.
			 */
			public function setRegister(string $register): self {
				return $this;
			}

			/**
			 * @param string $schema The schema slug.
			 *
			 * @return self Fluent.
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function findAll(array $config): array {
				if (($config['offset'] ?? 0) > 0) {
					return [];
				}

				if ($this->schema === 'Contract') {
					if ($this->ownerThrows === true) {
						throw new RuntimeException('shillinq is not installed');
					}

					return $this->owners;
				}

				return $this->satellites;
			}

			/**
			 * @param string $objectId The object id.
			 * @param array<string, mixed> $data The patch.
			 * @param string|null $register The register.
			 * @param string|null $schema The schema.
			 * @param bool $_rbac RBAC flag.
			 * @param bool $_multitenancy Multitenancy flag.
			 *
			 * @return array<string, mixed> The patch, echoed.
			 */
			public function patchObject(
				string $objectId,
				array $data,
				?string $register = null,
				?string $schema = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				$this->test->writes[] = ['id' => $objectId, 'data' => $data];
				return $data;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		return new CommandTester(new ReferencesAuditCommand($container));
	}

	/**
	 * A read-only run reports the match and writes nothing.
	 *
	 * @return void
	 */
	public function testADryRunReportsAndWritesNothing(): void {
		$tester = $this->tester(
			[['id' => 's1', 'contractNumber' => 'K1', 'contract' => '']],
			[['id' => 'o1', 'contractNumber' => 'K1']]
		);

		$tester->execute([]);

		self::assertSame([], $this->writes, 'a run without the write option must change nothing');
		self::assertStringContainsString('1 backfillable', $tester->getDisplay());
		self::assertSame(0, $tester->getStatusCode());
	}

	/**
	 * An unambiguous match is filled in, and only the reference is written.
	 *
	 * @return void
	 */
	public function testAnUnambiguousMatchIsBackfilled(): void {
		$tester = $this->tester(
			[['id' => 's1', 'contractNumber' => 'K1', 'contract' => '', 'name' => 'keep me']],
			[['id' => 'o1', 'contractNumber' => 'K1']]
		);

		$tester->execute(['--write' => true]);

		self::assertCount(1, $this->writes);
		self::assertSame('s1', $this->writes[0]['id']);
		self::assertSame(
			['contract' => 'o1'],
			$this->writes[0]['data'],
			'only the reference may be written: a full-object write would null every field the read did not return'
		);
	}

	/**
	 * Two owners sharing the identity key is refused, never guessed.
	 *
	 * @return void
	 */
	public function testAnAmbiguousMatchIsRefused(): void {
		$tester = $this->tester(
			[['id' => 's1', 'contractNumber' => 'K1', 'contract' => '']],
			[['id' => 'o1', 'contractNumber' => 'K1'], ['id' => 'o2', 'contractNumber' => 'K1']]
		);

		$tester->execute(['--write' => true]);

		self::assertSame([], $this->writes);
		self::assertStringContainsString('ambiguous', $tester->getDisplay());
	}

	/**
	 * A satellite with no identity key cannot be matched, and is not guessed
	 * from anything else.
	 *
	 * @return void
	 */
	public function testARowWithNoIdentityKeyIsUnmatched(): void {
		$tester = $this->tester(
			[['id' => 's1', 'contractNumber' => null, 'contract' => '']],
			[['id' => 'o1', 'contractNumber' => 'K1']]
		);

		$tester->execute(['--write' => true]);

		self::assertSame([], $this->writes);
		self::assertStringContainsString('1 unmatched', $tester->getDisplay());
	}

	/**
	 * A reference pointing at nothing is reported, and fails the command.
	 *
	 * @return void
	 */
	public function testADanglingReferenceFailsTheCommand(): void {
		$tester = $this->tester(
			[['id' => 's1', 'contractNumber' => 'K1', 'contract' => 'gone']],
			[['id' => 'o1', 'contractNumber' => 'K1']]
		);

		$tester->execute([]);

		self::assertStringContainsString('dangling', $tester->getDisplay());
		self::assertSame(1, $tester->getStatusCode(), 'a dangling cross-app link is a defect, not a note');
	}

	/**
	 * With the owner absent nothing resolves, and every reference is NOT
	 * therefore called dangling.
	 *
	 * @return void
	 */
	public function testAnUnreadableOwnerRegisterDoesNotInventDanglingLinks(): void {
		$tester = $this->tester(
			[['id' => 's1', 'contractNumber' => 'K1', 'contract' => 'o1']],
			[],
			true
		);

		$tester->execute([]);

		self::assertSame(0, $tester->getStatusCode());
		self::assertStringContainsString('1 set', $tester->getDisplay());
	}
}//end class
