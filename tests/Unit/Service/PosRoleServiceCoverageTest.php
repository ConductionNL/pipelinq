<?php

/**
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Service\PosRoleService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Coverage tests for PosRoleService CRUD paths and error branches.
 *
 * @covers \OCA\Pipelinq\Service\PosRoleService
 * @uses   \OCA\Pipelinq\Util\CastingPayloadToArray
 * @uses   \OCA\Pipelinq\Util\EntityPayload
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 */
class PosRoleServiceCoverageTest extends TestCase {

	/**
	 * The app config mock.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The object service mock.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objectService;

	/**
	 * Set up the test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
	}//end setUp()

	/**
	 * Configure the app-config values returned for each key.
	 *
	 * @param array<string, string> $values Key => value map.
	 *
	 * @return void
	 */
	private function configure(array $values): void {
		$this->appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default) use ($values): string {
				return ($values[$key] ?? $default);
			}
		);
	}//end configure()

	/**
	 * Configure register plus both POS schemas.
	 *
	 * @return void
	 */
	private function configureAll(): void {
		$this->configure(['register' => 'reg-1', 'posRole_schema' => 'role-s', 'posStaff_schema' => 'staff-s']);
	}//end configureAll()

	/**
	 * Build the service under test.
	 *
	 * @return PosRoleService The service.
	 */
	private function service(): PosRoleService {
		return new PosRoleService($this->appConfig, $this->logger, $this->objectService);
	}//end service()

	/**
	 * Build an entity mock whose jsonSerialize returns the given payload.
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return ObjectEntity&MockObject The entity.
	 */
	private function entity(array $payload): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($payload);
		return $entity;
	}//end entity()

	/**
	 * listRoles queries the posRole schema and normalises every result to an array.
	 *
	 * @return void
	 */
	public function testListRolesQueriesSchemaAndNormalisesResults(): void {
		$this->configureAll();
		$this->objectService->expects($this->once())
			->method('findAll')
			->with(['filters' => ['register' => 'reg-1', 'schema' => 'role-s'], 'limit' => 1000])
			->willReturn([
				['id' => 'a', 'name' => 'Kassa'],
				$this->entity(['id' => 'b', 'name' => 'Manager']),
			]);

		$roles = $this->service()->listRoles();

		self::assertSame([['id' => 'a', 'name' => 'Kassa'], ['id' => 'b', 'name' => 'Manager']], $roles);
	}//end testListRolesQueriesSchemaAndNormalisesResults()

	/**
	 * listRoles returns an empty list when the store has no roles.
	 *
	 * @return void
	 */
	public function testListRolesReturnsEmptyListWhenNoRoles(): void {
		$this->configureAll();
		$this->objectService->method('findAll')->willReturn([]);

		self::assertSame([], $this->service()->listRoles());
	}//end testListRolesReturnsEmptyListWhenNoRoles()

	/**
	 * listRoles refuses when the register is not configured.
	 *
	 * @return void
	 */
	public function testListRolesThrowsWhenNotConfigured(): void {
		$this->configure(['posRole_schema' => 'role-s']);
		$this->objectService->expects($this->never())->method('findAll');

		$this->expectException(OCSNotFoundException::class);
		$this->expectExceptionMessage('POS register of schema is niet geconfigureerd.');
		$this->service()->listRoles();
	}//end testListRolesThrowsWhenNotConfigured()

	/**
	 * getRole returns the found role as an array, querying with register and schema.
	 *
	 * @return void
	 */
	public function testGetRoleReturnsFoundRole(): void {
		$this->configureAll();
		$this->objectService->expects($this->once())
			->method('find')
			->with('role-uuid', $this->anything(), $this->anything(), 'reg-1', 'role-s')
			->willReturn($this->entity(['id' => 'role-uuid', 'name' => 'Kassa']));

		self::assertSame(['id' => 'role-uuid', 'name' => 'Kassa'], $this->service()->getRole('role-uuid'));
	}//end testGetRoleReturnsFoundRole()

	/**
	 * getRole maps a throwing lookup to a not-found error.
	 *
	 * @return void
	 */
	public function testGetRoleThrowsNotFoundWhenLookupFails(): void {
		$this->configureAll();
		$this->objectService->method('find')->willThrowException(new RuntimeException('db down'));

		$this->expectException(OCSNotFoundException::class);
		$this->expectExceptionMessage('POS-rol niet gevonden.');
		$this->service()->getRole('missing');
	}//end testGetRoleThrowsNotFoundWhenLookupFails()

	/**
	 * getRole maps a null lookup result to a not-found error.
	 *
	 * @return void
	 */
	public function testGetRoleThrowsNotFoundWhenLookupReturnsNull(): void {
		$this->configureAll();
		$this->objectService->method('find')->willReturn(null);

		$this->expectException(OCSNotFoundException::class);
		$this->expectExceptionMessage('POS-rol niet gevonden.');
		$this->service()->getRole('missing');
	}//end testGetRoleThrowsNotFoundWhenLookupReturnsNull()

	/**
	 * saveRole rejects an empty (whitespace-only) name before touching storage.
	 *
	 * @return void
	 */
	public function testSaveRoleRejectsEmptyName(): void {
		$this->configureAll();
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage('Rolnaam is verplicht.');
		$this->service()->saveRole(['name' => '   ']);
	}//end testSaveRoleRejectsEmptyName()

	/**
	 * saveRole rejects a discount above 100.
	 *
	 * @return void
	 */
	public function testSaveRoleRejectsDiscountAboveHundred(): void {
		$this->configureAll();
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage('Maximale korting moet tussen 0 en 100 liggen.');
		$this->service()->saveRole(['name' => 'Kassa', 'maxDiscountPercent' => 101]);
	}//end testSaveRoleRejectsDiscountAboveHundred()

	/**
	 * saveRole rejects a negative discount.
	 *
	 * @return void
	 */
	public function testSaveRoleRejectsNegativeDiscount(): void {
		$this->configureAll();

		$this->expectException(OCSBadRequestException::class);
		$this->service()->saveRole(['name' => 'Kassa', 'maxDiscountPercent' => -1]);
	}//end testSaveRoleRejectsNegativeDiscount()

	/**
	 * saveRole with an id persists the normalised payload under that UUID.
	 *
	 * @return void
	 */
	public function testSaveRoleWithIdPersistsNormalisedPayload(): void {
		$this->configureAll();
		$expected = [
			'name' => 'Manager',
			'description' => 'Leidinggevende',
			'canVoid' => true,
			'maxDiscountPercent' => 100,
			'canRefund' => true,
			'canNoSale' => false,
		];
		$this->objectService->expects($this->once())
			->method('saveObject')
			->with($expected, [], 'reg-1', 'role-s', 'role-uuid')
			->willReturn($this->entity(['id' => 'role-uuid', 'name' => 'Manager']));

		$saved = $this->service()->saveRole(
			[
				'name' => '  Manager ',
				'description' => 'Leidinggevende',
				'canVoid' => 1,
				'maxDiscountPercent' => '100',
				'canRefund' => 'yes',
			],
			'role-uuid'
		);

		self::assertSame(['id' => 'role-uuid', 'name' => 'Manager'], $saved);
	}//end testSaveRoleWithIdPersistsNormalisedPayload()

	/**
	 * saveRole without an id applies defaults and generates a v4 UUID.
	 *
	 * @return void
	 */
	public function testSaveRoleWithoutIdGeneratesV4Uuid(): void {
		$this->configureAll();
		$captured = null;
		$this->objectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(
				function (array $object, ?array $extend, $register, $schema, ?string $uuid) use (&$captured) {
					$captured = [$object, $register, $schema, $uuid];
					return $this->entity(['id' => $uuid]);
				}
			);

		$saved = $this->service()->saveRole(['name' => 'Kassa']);

		self::assertSame(
			[
				'name' => 'Kassa',
				'description' => '',
				'canVoid' => false,
				'maxDiscountPercent' => 0,
				'canRefund' => false,
				'canNoSale' => false,
			],
			$captured[0]
		);
		self::assertSame('reg-1', $captured[1]);
		self::assertSame('role-s', $captured[2]);
		self::assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			(string)$captured[3]
		);
		self::assertSame(['id' => $captured[3]], $saved);
	}//end testSaveRoleWithoutIdGeneratesV4Uuid()

	/**
	 * deleteRole deletes an existing, unassigned role.
	 *
	 * @return void
	 */
	public function testDeleteRoleDeletesUnassignedRole(): void {
		$this->configureAll();
		$this->objectService->method('find')->willReturn($this->entity(['id' => 'role-uuid']));
		$this->objectService->method('findAll')->willReturn([['isActive' => false, 'posRole' => 'role-uuid']]);
		$this->objectService->expects($this->once())
			->method('deleteObject')
			->with('role-uuid', 'reg-1', 'role-s')
			->willReturn(true);
		$this->logger->expects($this->never())->method('warning');

		$this->service()->deleteRole('role-uuid');
	}//end testDeleteRoleDeletesUnassignedRole()

	/**
	 * deleteRole logs and maps a failing delete to a not-found error.
	 *
	 * @return void
	 */
	public function testDeleteRoleMapsDeleteFailureToNotFound(): void {
		$this->configureAll();
		$this->objectService->method('find')->willReturn($this->entity(['id' => 'role-uuid']));
		$this->objectService->method('findAll')->willReturn([]);
		$this->objectService->method('deleteObject')->willThrowException(new RuntimeException('locked'));
		$this->logger->expects($this->once())
			->method('warning')
			->with('Pipelinq: failed to delete posRole', ['exception' => 'locked']);

		$this->expectException(OCSNotFoundException::class);
		$this->expectExceptionMessage('POS-rol kon niet worden verwijderd.');
		$this->service()->deleteRole('role-uuid');
	}//end testDeleteRoleMapsDeleteFailureToNotFound()

	/**
	 * deleteRole refuses while active staff (missing isActive counts as active) hold the role.
	 *
	 * @return void
	 */
	public function testDeleteRoleRefusesWhileAssignedToActiveStaff(): void {
		$this->configureAll();
		$this->objectService->method('find')->willReturn($this->entity(['id' => 'role-uuid']));
		$this->objectService->method('findAll')->willReturn(
			[
				['posRole' => 'role-uuid'],
				['posRole' => 'role-uuid', 'isActive' => true],
				['posRole' => 'role-uuid', 'isActive' => false],
			]
		);
		$this->objectService->expects($this->never())->method('deleteObject');

		$this->expectException(OCSForbiddenException::class);
		$this->expectExceptionMessage('Deze rol is nog toegewezen aan 2 actieve medewerker(s).');
		$this->service()->deleteRole('role-uuid');
	}//end testDeleteRoleRefusesWhileAssignedToActiveStaff()

	/**
	 * countActiveStaffForRole returns 0 when the posStaff schema is not configured.
	 *
	 * @return void
	 */
	public function testCountActiveStaffReturnsZeroWhenStaffSchemaMissing(): void {
		$this->configure(['register' => 'reg-1', 'posRole_schema' => 'role-s']);
		$this->objectService->expects($this->never())->method('findAll');

		self::assertSame(0, $this->service()->countActiveStaffForRole('role-uuid'));
	}//end testCountActiveStaffReturnsZeroWhenStaffSchemaMissing()

	/**
	 * countActiveStaffForRole returns 0 when the store lookup throws.
	 *
	 * @return void
	 */
	public function testCountActiveStaffReturnsZeroWhenLookupThrows(): void {
		$this->configureAll();
		$this->objectService->expects($this->once())
			->method('findAll')
			->with(['filters' => ['register' => 'reg-1', 'schema' => 'staff-s', 'posRole' => 'role-uuid']])
			->willThrowException(new RuntimeException('db down'));

		self::assertSame(0, $this->service()->countActiveStaffForRole('role-uuid'));
	}//end testCountActiveStaffReturnsZeroWhenLookupThrows()
}//end class
