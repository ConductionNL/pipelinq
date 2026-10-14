<?php

/**
 * The configured CRM group is granted wherever the staff group is.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Access;

use OCA\Pipelinq\Service\Access\RegisterAccessSync;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Pipelinq\Service\Access\RegisterAccessSync
 *
 * @spec openspec/changes/close-pipelinq-register-to-staff/specs/register-access/spec.md#requirement-the-configured-crm-group-is-granted-wherever-the-staff-group-is
 */
class RegisterAccessSyncTest extends TestCase {

	/**
	 * What the doubles were updated with, by name.
	 *
	 * @var array<string, array>
	 */
	public array $written = [];

	/**
	 * The CRM group joins every verb that grants sales, and no other verb.
	 *
	 * @return void
	 */
	public function testTheGroupJoinsEveryVerbThatGrantsTheStaff(): void {
		$block = [
			'create' => ['public', 'sales', 'user:pipelinq-system'],
			'read'   => ['sales', 'user:pipelinq-system'],
			'update' => ['pipelinq-portal-service'],
			'delete' => [],
		];

		$this->assertSame(
			[
				'create' => ['public', 'sales', 'user:pipelinq-system', 'zuiddrecht-kcc'],
				'read'   => ['sales', 'user:pipelinq-system', 'zuiddrecht-kcc'],
				'update' => ['pipelinq-portal-service'],
				'delete' => [],
			],
			RegisterAccessSync::grant(authorization: $block, group: 'zuiddrecht-kcc')
		);
	}//end testTheGroupJoinsEveryVerbThatGrantsTheStaff()

	/**
	 * A changed setting takes the previous group out and puts the new one in.
	 *
	 * @return void
	 */
	public function testAChangedSettingReplacesThePreviousGroup(): void {
		$block = ['read' => ['sales', 'kcc-oud']];

		$this->assertSame(['read' => ['sales', 'kcc-nieuw']], RegisterAccessSync::grant(authorization: $block, group: 'kcc-nieuw', previous: 'kcc-oud'));
		$this->assertSame(['read' => ['sales']], RegisterAccessSync::grant(authorization: $block, group: '', previous: 'kcc-oud'));
	}//end testAChangedSettingReplacesThePreviousGroup()

	/**
	 * A setting naming a broad pseudo-group never widens the register.
	 *
	 * @return void
	 */
	public function testABroadGroupIsNeverGranted(): void {
		foreach (['authenticated', 'public', 'admin', 'sales', ''] as $group) {
			$this->assertSame(['read' => ['sales']], RegisterAccessSync::grant(authorization: ['read' => ['sales']], group: $group), $group);
		}
	}//end testABroadGroupIsNeverGranted()

	/**
	 * The configured settings and the groups last applied, as the config double keeps them.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * A register or schema double that records what it was written with.
	 *
	 * @param string     $name          The name the write is recorded under.
	 * @param array|null $authorization The block.
	 * @param array      $schemas       The schema ids, for a register.
	 *
	 * @return object
	 */
	private function entity(string $name, ?array $authorization, array $schemas=[]): object {
		return new class($name, $authorization, $schemas, $this) {
			public function __construct(public string $name, public ?array $authorization, public array $schemas, public object $test) {
			}

			public function getAuthorization(): ?array {
				return $this->authorization;
			}

			public function setAuthorization(?array $authorization): void {
				$this->authorization = $authorization;
				$this->test->written[$this->name] = $authorization;
			}

			public function getSchemas(): array {
				return $this->schemas;
			}

			public function getSlug(): string {
				return (string)substr($this->name, (int)strpos($this->name, ':') + 1);
			}
		};
	}//end entity()

	/**
	 * The shipped blocks: three registers, the schemas the read group reads, and two it does not.
	 *
	 * @return array{0: array<string, object>, 1: array<int, object>}
	 */
	private function shipped(): array {
		$staff = ['create' => ['sales', 'user:pipelinq-system'], 'read' => ['sales', 'user:pipelinq-system'], 'update' => ['sales', 'user:pipelinq-system'], 'delete' => ['sales', 'user:pipelinq-system'], 'export' => ['sales', 'user:pipelinq-system']];
		$registers = [
			'pipelinq' => $this->entity('register:pipelinq', $staff, [1, 2, 3, 4, 5, 6]),
			'pipelinq-portal' => $this->entity('register:pipelinq-portal', $staff, []),
			'sla' => $this->entity('register:sla', $staff, []),
		];
		$ticket = $staff;
		$ticket['create'][] = 'pipelinq-messaging-service';
		$schemas = [
			1 => $this->entity('schema:client', $staff),
			2 => $this->entity('schema:ticket', $ticket),
			3 => $this->entity('schema:segment', null),
			4 => $this->entity('schema:crmPortalAccount', ['read' => [], 'create' => ['pipelinq-portal-service']]),
			5 => $this->entity('schema:contact', $staff),
			6 => $this->entity('schema:lead', ['create' => ['public', 'sales', 'user:pipelinq-system'], 'read' => ['sales', 'user:pipelinq-system']]),
		];

		return [$registers, $schemas];
	}//end shipped()

	/**
	 * The real sync over the doubles.
	 *
	 * @param array<string, object> $registers The registers by slug.
	 * @param array<int, object>    $schemas   The schemas by id.
	 * @param int|null              $reads     Counts the mapper reads.
	 *
	 * @return RegisterAccessSync
	 */
	private function sync(array $registers, array $schemas, ?int &$reads=null): RegisterAccessSync {
		$reads  = 0;
		$mapper = static fn (array $rows, ?int &$reads): object => new class($rows, $reads) {
			public function __construct(private array $rows, private ?int &$reads) {
			}

			public function find(string|int $id, mixed ...$rest): object {
				$this->reads++;
				if (isset($this->rows[$id]) === false) {
					throw new RuntimeException('absent');
				}

				return $this->rows[$id];
			}

			public function update(object $entity): object {
				return $entity;
			}
		};
		$registerMapper = $mapper($registers, $reads);
		$schemaMapper   = $mapper($schemas, $reads);

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

		return new RegisterAccessSync($config, $container, $this->createMock(LoggerInterface::class));
	}//end sync()

	/**
	 * Whether OpenRegister grants a caller in these groups the verb, by its rule semantics.
	 *
	 * A non-empty block that does not list the verb, or lists none of the
	 * caller's groups, refuses it; `authenticated` admits any signed-in caller.
	 * Administrators and object owners, who bypass, are not modelled.
	 *
	 * @param array<string, mixed> $authorization The block OpenRegister reads.
	 * @param string               $uid           The caller.
	 * @param array<string>        $groups        The caller's groups.
	 * @param string               $verb          The verb.
	 *
	 * @return bool
	 */
	private static function permits(array $authorization, string $uid, array $groups, string $verb): bool {
		foreach ((array)($authorization[$verb] ?? []) as $rule) {
			if ($rule === 'authenticated' || $rule === 'user:'.$uid || in_array($rule, $groups, true) === true) {
				return true;
			}
		}

		return false;
	}//end permits()

	/**
	 * The sync writes the registers and every schema that changes, and leaves the rest.
	 *
	 * @return void
	 */
	public function testTheSyncWritesTheRegistersAndTheirSchemas(): void {
		[$registers, $schemas] = $this->shipped();
		$this->config = ['crm_group' => 'zuiddrecht-kcc', 'crm_read_group' => 'dossiq-medewerkers'];

		$count = $this->sync($registers, $schemas)->sync();

		$this->assertSame(7, $count, 'three registers, client, ticket, contact and lead');
		$this->assertSame(['sales', 'user:pipelinq-system', 'zuiddrecht-kcc'], $this->written['register:sla']['read']);
		$this->assertSame(['sales', 'user:pipelinq-system', 'pipelinq-messaging-service', 'zuiddrecht-kcc'], $this->written['schema:ticket']['create']);
		foreach (['schema:client', 'schema:contact', 'schema:ticket'] as $name) {
			$this->assertSame(['sales', 'user:pipelinq-system', 'dossiq-medewerkers', 'zuiddrecht-kcc'], $this->written[$name]['read'], $name);
			$this->assertSame(['sales', 'user:pipelinq-system', 'dossiq-medewerkers', 'zuiddrecht-kcc'], $this->written[$name]['export'], $name);
		}

		foreach (['register:pipelinq', 'register:pipelinq-portal', 'register:sla', 'schema:lead'] as $name) {
			$this->assertNotContains('dossiq-medewerkers', array_merge(...array_values($this->written[$name])), $name.' never names the read group');
		}

		$this->assertArrayNotHasKey('schema:segment', $this->written, 'a schema without a block follows the register');
		$this->assertArrayNotHasKey('schema:crmPortalAccount', $this->written, 'the portal schemas grant no staff, so nothing joins them');
		$this->assertSame('dossiq-medewerkers', $this->config['crm_read_group_applied']);
		$this->assertSame('zuiddrecht-kcc', $this->config['crm_group_applied']);
	}//end testTheSyncWritesTheRegistersAndTheirSchemas()

	/**
	 * A member of the read group reads a client and cannot create, update or delete one.
	 *
	 * @return void
	 */
	public function testAReadGroupMemberReadsAClientAndCannotUpdateIt(): void {
		[$registers, $schemas] = $this->shipped();
		$this->config = ['crm_read_group' => 'dossiq-medewerkers'];

		$this->sync($registers, $schemas)->sync();
		$client = $schemas[1]->getAuthorization();
		$handler = ['uid' => 'behandelaar-1', 'groups' => ['dossiq-medewerkers', 'zuiddrecht-medewerkers']];

		$this->assertTrue(self::permits($client, $handler['uid'], $handler['groups'], 'read'));
		foreach (['create', 'update', 'delete'] as $verb) {
			$this->assertFalse(self::permits($client, $handler['uid'], $handler['groups'], $verb), $verb);
		}

		$this->assertFalse(self::permits($registers['pipelinq']->getAuthorization(), $handler['uid'], $handler['groups'], 'read'), 'a schema that follows the register stays closed to the handler');
		$this->assertFalse(self::permits($client, 'inwoner-1', ['inwoners'], 'read'), 'a resident reads nothing');
		$this->assertTrue(self::permits($client, 'kcc-1', ['sales'], 'update'), 'the staff still write');
	}//end testAReadGroupMemberReadsAClientAndCannotUpdateIt()

	/**
	 * A read group changed straight in app config is taken out on the next sync, and the new one granted.
	 *
	 * @return void
	 */
	public function testAChangedReadGroupIsTakenOutWhicheverWayItChanged(): void {
		[$registers, $schemas] = $this->shipped();
		$this->config = ['crm_read_group' => 'dossiq-medewerkers'];
		$sync = $this->sync($registers, $schemas);
		$sync->sync();

		// An example set, or occ config:app:set, writes the key directly.
		$this->config['crm_read_group'] = 'zaakbehandelaars';
		$sync->sync();

		$client = $schemas[1]->getAuthorization();
		$this->assertSame(['sales', 'user:pipelinq-system', 'zaakbehandelaars'], $client['read']);
		$this->assertNotContains('dossiq-medewerkers', array_merge(...array_values($client)));

		$this->config['crm_read_group'] = '';
		$sync->sync();
		$this->assertSame(['sales', 'user:pipelinq-system'], $schemas[1]->getAuthorization()['read']);
	}//end testAChangedReadGroupIsTakenOutWhicheverWayItChanged()

	/**
	 * While the settings match what was applied, the sync reads nothing; after an import it rewrites.
	 *
	 * @return void
	 */
	public function testNothingChangedReadsNothing(): void {
		[$registers, $schemas] = $this->shipped();
		$this->config = ['crm_read_group' => 'dossiq-medewerkers', 'crm_read_group_applied' => 'dossiq-medewerkers'];
		$reads = 0;
		$sync = $this->sync($registers, $schemas, $reads);

		$this->assertSame(0, $sync->sync());
		$this->assertSame(0, $reads);

		$this->assertSame(3, $sync->sync(force: true), 'an import wrote the file blocks back: client, contact and ticket get the group again');
		$this->assertGreaterThan(0, $reads, 'the counter counts: a forced sync reads the registers and schemas');
	}//end testNothingChangedReadsNothing()

	/**
	 * A broad or reserved read group is never granted.
	 *
	 * @return void
	 */
	public function testABroadReadGroupIsNeverGranted(): void {
		foreach (['authenticated', 'public', 'admin', 'sales', ''] as $group) {
			$block = ['read' => ['sales'], 'update' => ['sales']];
			$this->assertSame($block, RegisterAccessSync::grantRead(authorization: $block, group: $group), $group);
		}

		$this->assertSame(
			['read' => ['sales', 'lezers'], 'update' => ['sales'], 'create' => []],
			RegisterAccessSync::grantRead(authorization: ['read' => ['sales'], 'update' => ['sales'], 'create' => []], group: 'lezers'),
			'read only, and never a verb the staff are not granted'
		);
	}//end testABroadReadGroupIsNeverGranted()

	/**
	 * Without OpenRegister the sync reports it and records nothing as applied.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheSyncReportsIt(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no openregister'));
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default=''): string => ($key === 'crm_group' ? 'zuiddrecht-kcc' : $default));
		$config->expects($this->never())->method('setValueString');

		$this->assertSame(-1, (new RegisterAccessSync($config, $container, $this->createMock(LoggerInterface::class)))->sync());
	}//end testWithoutOpenRegisterTheSyncReportsIt()
}//end class
