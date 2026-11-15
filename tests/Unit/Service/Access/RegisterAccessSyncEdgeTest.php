<?php

/**
 * The register access sync's edge cases: absent registers and schemas, failed writes, and blocks it leaves alone.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Access
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/close-pipelinq-register-to-staff/specs/register-access/spec.md#requirement-the-configured-crm-group-is-granted-wherever-the-staff-group-is
 * @spec openspec/changes/close-pipelinq-register-to-staff/specs/register-access/spec.md#requirement-a-configured-read-group-reads-the-parties-and-contact-moments-and-writes-nothing
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Access;

use OCA\Pipelinq\Service\Access\RegisterAccessSync;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Pipelinq\Service\Access\RegisterAccessSync
 *
 * @spec openspec/changes/close-pipelinq-register-to-staff/specs/register-access/spec.md#requirement-the-configured-crm-group-is-granted-wherever-the-staff-group-is
 */
class RegisterAccessSyncEdgeTest extends TestCase {

	/**
	 * The app config the sync reads and writes.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * The entities the mappers were asked to update, by name.
	 *
	 * @var array<int, string>
	 */
	public array $updated = [];

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * A fresh logger per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger  = $this->createMock(LoggerInterface::class);
		$this->config  = [];
		$this->updated = [];
	}//end setUp()

	/**
	 * A register or schema double.
	 *
	 * @param string $name          The slug.
	 * @param mixed  $authorization The block.
	 * @param array  $schemas       The schema ids, for a register.
	 *
	 * @return object
	 */
	private function entity(string $name, mixed $authorization, array $schemas=[]): object {
		return new class($name, $authorization, $schemas) {
			public function __construct(public string $name, public mixed $authorization, public array $schemas) {
			}

			public function getAuthorization(): mixed {
				return $this->authorization;
			}

			public function setAuthorization(?array $authorization): void {
				$this->authorization = $authorization;
			}

			public function getSchemas(): array {
				return $this->schemas;
			}

			public function getSlug(): string {
				return $this->name;
			}
		};
	}//end entity()

	/**
	 * The sync over mapper doubles.
	 *
	 * @param array<string, object> $registers The registers by slug.
	 * @param array<int, object>    $schemas   The schemas by id.
	 * @param array<int, string>    $failing   Names whose update throws.
	 *
	 * @return RegisterAccessSync
	 */
	private function sync(array $registers, array $schemas, array $failing=[]): RegisterAccessSync {
		$mapper = fn (array $rows): object => new class($rows, $failing, $this) {
			public function __construct(private array $rows, private array $failing, private object $test) {
			}

			public function find(string|int $id, mixed ...$rest): object {
				if (isset($this->rows[$id]) === false) {
					throw new RuntimeException('absent');
				}

				return $this->rows[$id];
			}

			public function update(object $entity): object {
				if (in_array($entity->name, $this->failing, true) === true) {
					throw new RuntimeException('database gone');
				}

				$this->test->updated[] = $entity->name;
				return $entity;
			}
		};
		$registerMapper = $mapper($registers);
		$schemaMapper   = $mapper($schemas);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => str_ends_with($id, 'RegisterMapper') ? $registerMapper : $schemaMapper
		);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default=''): string => ($this->config[$key] ?? $default));
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->config[$key] = $value;
			return true;
		});

		return new RegisterAccessSync($config, $container, $this->logger);
	}//end sync()

	/**
	 * A register or schema OpenRegister does not hold is skipped, and the others are still written.
	 *
	 * @return void
	 */
	public function testAnAbsentRegisterOrSchemaIsSkipped(): void {
		$registers = ['sla' => $this->entity('sla', ['read' => ['sales']], [7, 8])];
		$schemas   = [8 => $this->entity('client', ['read' => ['sales']])];
		$this->config = ['crm_group' => 'kcc'];

		$this->assertSame(2, $this->sync($registers, $schemas)->sync());
		$this->assertSame(['sla', 'client'], $this->updated);
		$this->assertSame('kcc', $this->config['crm_group_applied']);
		$this->assertSame('', $this->config['crm_read_group_applied']);
	}//end testAnAbsentRegisterOrSchemaIsSkipped()

	/**
	 * A failed write is logged and not counted, and the rest of the sync goes on.
	 *
	 * @return void
	 */
	public function testAFailedWriteIsLoggedAndNotCounted(): void {
		$registers = ['pipelinq' => $this->entity('pipelinq', ['read' => ['sales']], [1])];
		$schemas   = [1 => $this->entity('ticket', ['read' => ['sales']])];
		$this->config = ['crm_group' => 'kcc'];

		$this->logger->expects($this->once())->method('warning')->with(
			'Pipelinq: the CRM groups could not be written',
			['error' => 'database gone']
		);

		$this->assertSame(1, $this->sync($registers, $schemas, ['pipelinq'])->sync());
		$this->assertSame(['ticket'], $this->updated);
	}//end testAFailedWriteIsLoggedAndNotCounted()

	/**
	 * An entity without a block, or with an empty one, is never written.
	 *
	 * @return void
	 */
	public function testAnEntityWithoutABlockIsNeverWritten(): void {
		$registers = ['pipelinq' => $this->entity('pipelinq', [], [1, 2])];
		$schemas   = [1 => $this->entity('client', null), 2 => $this->entity('contact', 'open')];
		$this->config = ['crm_group' => 'kcc', 'crm_read_group' => 'lezers'];

		$this->assertSame(0, $this->sync($registers, $schemas)->sync());
		$this->assertSame([], $this->updated);
		$this->assertSame('lezers', $this->config['crm_read_group_applied'], 'the settings count as applied even when nothing changed');
	}//end testAnEntityWithoutABlockIsNeverWritten()

	/**
	 * Settings are trimmed before they are compared and granted.
	 *
	 * @return void
	 */
	public function testSettingsAreTrimmed(): void {
		$client = $this->entity('client', ['read' => ['sales'], 'update' => ['sales']]);
		$this->config = ['crm_read_group' => "  lezers \n", 'crm_read_group_applied' => 'lezers'];
		$sync = $this->sync(['pipelinq' => $this->entity('pipelinq', ['read' => ['sales']], [1])], [1 => $client]);

		$this->assertSame(0, $sync->sync(), 'the trimmed setting matches what was applied');

		$this->assertSame(1, $sync->sync(force: true));
		$this->assertSame(['read' => ['sales', 'lezers'], 'update' => ['sales']], $client->getAuthorization());
	}//end testSettingsAreTrimmed()

	/**
	 * The CRM group leaves verbs that are not lists, or do not grant the staff, exactly as they are.
	 *
	 * @return void
	 */
	public function testGrantLeavesVerbsWithoutTheStaffAlone(): void {
		$block = ['read' => ['sales', 'old'], 'create' => ['public', 'old'], 'delete' => 'sales', 'export' => []];

		$this->assertSame(
			['read' => ['sales', 'new'], 'create' => ['public', 'old'], 'delete' => 'sales', 'export' => []],
			RegisterAccessSync::grant(authorization: $block, group: 'new', previous: 'old')
		);
	}//end testGrantLeavesVerbsWithoutTheStaffAlone()

	/**
	 * A group that stays the same is neither removed nor added twice; a reserved previous group is never taken out.
	 *
	 * @return void
	 */
	public function testGrantKeepsAnUnchangedGroupOnce(): void {
		$block = ['read' => ['sales', 'kcc'], 'update' => ['sales']];
		$this->assertSame(
			['read' => ['sales', 'kcc'], 'update' => ['sales', 'kcc']],
			RegisterAccessSync::grant(authorization: $block, group: 'kcc', previous: 'kcc')
		);

		$this->assertSame(
			['read' => ['sales', 'admin', 'kcc']],
			RegisterAccessSync::grant(authorization: ['read' => ['sales', 'admin']], group: 'kcc', previous: 'admin')
		);
	}//end testGrantKeepsAnUnchangedGroupOnce()

	/**
	 * The read group last applied is taken out of every verb, write verbs too, and the keys are re-indexed.
	 *
	 * @return void
	 */
	public function testGrantReadTakesThePreviousGroupOutOfEveryVerb(): void {
		$block = ['read' => ['old', 'sales'], 'update' => ['sales', 'old'], 'create' => ['old'], 'delete' => 'old'];

		$this->assertSame(
			['read' => ['sales', 'new'], 'update' => ['sales'], 'create' => [], 'delete' => 'old'],
			RegisterAccessSync::grantRead(authorization: $block, group: 'new', previous: 'old')
		);
	}//end testGrantReadTakesThePreviousGroupOutOfEveryVerb()

	/**
	 * Export is granted with read, but only where the staff export.
	 *
	 * @return void
	 */
	public function testGrantReadGrantsExportOnlyWhereTheStaffExport(): void {
		$this->assertSame(
			['read' => ['sales', 'lezers'], 'export' => ['sales', 'lezers']],
			RegisterAccessSync::grantRead(authorization: ['read' => ['sales'], 'export' => ['sales']], group: 'lezers')
		);
		$this->assertSame(
			['read' => ['sales', 'lezers'], 'export' => ['user:pipelinq-system']],
			RegisterAccessSync::grantRead(
				authorization: ['read' => ['sales', 'lezers'], 'export' => ['user:pipelinq-system']],
				group: 'lezers',
				previous: 'lezers'
			)
		);
	}//end testGrantReadGrantsExportOnlyWhereTheStaffExport()
}//end class
