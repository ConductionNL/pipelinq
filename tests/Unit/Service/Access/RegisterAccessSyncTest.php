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
	 * The sync writes the registers and every schema that changes, and leaves the rest.
	 *
	 * @return void
	 */
	public function testTheSyncWritesTheRegistersAndTheirSchemas(): void {
		$test = $this;
		$entity = static function (string $name, ?array $authorization, array $schemas=[]) use ($test): object {
			return new class($name, $authorization, $schemas, $test) {
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
			};
		};

		$staff = ['read' => ['sales', 'user:pipelinq-system']];
		$registers = [
			'pipelinq' => $entity('register:pipelinq', $staff, [1, 2, 3]),
			'pipelinq-portal' => $entity('register:pipelinq-portal', $staff, []),
			'sla' => $entity('register:sla', $staff, []),
		];
		$schemas = [
			1 => $entity('schema:ticket', ['read' => ['sales'], 'create' => ['sales', 'pipelinq-messaging-service']]),
			2 => $entity('schema:client', null),
			3 => $entity('schema:crmPortalAccount', ['read' => [], 'create' => ['pipelinq-portal-service']]),
		];
		$mapper = static fn (array $rows): object => new class($rows) {
			public function __construct(private array $rows) {
			}

			public function find(string|int $id, mixed ...$rest): object {
				if (isset($this->rows[$id]) === false) {
					throw new RuntimeException('absent');
				}

				return $this->rows[$id];
			}

			public function update(object $entity): object {
				return $entity;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => str_ends_with($id, 'RegisterMapper') ? $mapper($registers) : $mapper($schemas)
		);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('zuiddrecht-kcc');

		$count = (new RegisterAccessSync($config, $container, $this->createMock(LoggerInterface::class)))->sync();

		$this->assertSame(4, $count);
		$this->assertSame(['sales', 'user:pipelinq-system', 'zuiddrecht-kcc'], $this->written['register:sla']['read']);
		$this->assertSame(['sales', 'pipelinq-messaging-service', 'zuiddrecht-kcc'], $this->written['schema:ticket']['create']);
		$this->assertArrayNotHasKey('schema:client', $this->written, 'a schema without a block follows the register');
		$this->assertArrayNotHasKey('schema:crmPortalAccount', $this->written, 'the portal schemas grant no staff, so nothing joins them');
	}//end testTheSyncWritesTheRegistersAndTheirSchemas()

	/**
	 * Without OpenRegister the sync reports it and changes nothing.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheSyncReportsIt(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no openregister'));
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('zuiddrecht-kcc');

		$this->assertSame(-1, (new RegisterAccessSync($config, $container, $this->createMock(LoggerInterface::class)))->sync());
	}//end testWithoutOpenRegisterTheSyncReportsIt()
}//end class
