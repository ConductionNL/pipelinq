<?php

/**
 * Unit tests for PosRoleService.
 *
 * Covers the maxDiscountPercent bound, the required-name validation, the
 * create/update scoping (update of a foreign id resolves to a 404), and the
 * delete-while-assigned guard that blocks removing a role still referenced by a
 * staff member. A fake ObjectService backs an in-memory store.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\PosRoleService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * In-memory ObjectService used by both role and staff service tests.
 */
class PosFakeObjectService
{
    /** @var array<string, array<string, array<string, mixed>>> */
    public array $store = [];

    /** @var int */
    private int $seq = 0;

    /**
     * Find a single object by id within a schema.
     *
     * @param string $id       The object id.
     * @param string $register The register id.
     * @param string $schema   The schema id.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id, string $register, string $schema): ?array
    {
        return $this->store[$schema][$id] ?? null;
    }

    /**
     * Find all objects in a schema.
     *
     * @param array<string, mixed> $config The OR query config.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAll(array $config): array
    {
        $schema = (string) ($config['filters']['schema'] ?? '');
        return array_values($this->store[$schema] ?? []);
    }

    /**
     * Save (create or update) an object.
     *
     * @param array<string, mixed> $object   The object data.
     * @param array<string, mixed> $extend   Unused.
     * @param string               $register The register id.
     * @param string               $schema   The schema id.
     * @param string|null          $uuid     The id to update, or null to create.
     *
     * @return array<string, mixed>
     */
    public function saveObject(array $object, array $extend, string $register, string $schema, ?string $uuid=null): array
    {
        if ($uuid === null || $uuid === '') {
            $this->seq++;
            $uuid = 'id-'.$this->seq;
        }

        $object['id'] = $uuid;
        $this->store[$schema][$uuid] = $object;

        return $object;
    }

    /**
     * Delete an object by id.
     *
     * @param string      $uuid     The id.
     * @param string|null $register The register id.
     * @param string|null $schema   The schema id.
     *
     * @return bool
     */
    public function deleteObject(string $uuid, ?string $register=null, ?string $schema=null): bool
    {
        unset($this->store[(string) $schema][$uuid]);
        return true;
    }

    /**
     * Seed an object directly into the store.
     *
     * @param string               $schema The schema id.
     * @param string               $id     The object id.
     * @param array<string, mixed> $data   The object data.
     *
     * @return void
     */
    public function seed(string $schema, string $id, array $data): void
    {
        $data['id'] = $id;
        $this->store[$schema][$id] = $data;
    }
}

/**
 * Tests for PosRoleService.
 */
class PosRoleServiceTest extends TestCase
{
    private PosFakeObjectService $objects;

    private PosRoleService $service;

    /**
     * Schema id used for posRole in the fake store.
     *
     * @var string
     */
    private const ROLE_SCHEMA = 'schema-role';

    /**
     * Schema id used for posStaff in the fake store.
     *
     * @var string
     */
    private const STAFF_SCHEMA = 'schema-staff';

    /**
     * Set up the service with fakes.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->objects = new PosFakeObjectService();

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($this->objects);

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            function (string $app, string $key): string {
                return match ($key) {
                    'register'        => 'reg-1',
                    'posRole_schema'  => self::ROLE_SCHEMA,
                    'posStaff_schema' => self::STAFF_SCHEMA,
                    default           => '',
                };
            }
        );

        $logger        = $this->createMock(LoggerInterface::class);
        $this->service = new PosRoleService($container, $appConfig, $logger);
    }//end setUp()

    /**
     * A role within the discount bound is created.
     *
     * @return void
     */
    public function testSaveRoleWithinBound(): void
    {
        $saved = $this->service->saveRole([
            'name'               => 'Supervisor',
            'maxDiscountPercent' => 15,
            'canVoid'            => false,
            'canRefund'          => true,
            'canNoSale'          => true,
        ]);

        $this->assertSame('Supervisor', $saved['name']);
        $this->assertSame(15, $saved['maxDiscountPercent']);
        $this->assertTrue($saved['canRefund']);
        $this->assertArrayHasKey('id', $saved);
    }//end testSaveRoleWithinBound()

    /**
     * A discount above 100 is rejected.
     *
     * @return void
     */
    public function testSaveRoleRejectsDiscountAbove100(): void
    {
        $this->expectException(OCSBadRequestException::class);
        $this->service->saveRole(['name' => 'Bad', 'maxDiscountPercent' => 110]);
    }//end testSaveRoleRejectsDiscountAbove100()

    /**
     * A negative discount is rejected.
     *
     * @return void
     */
    public function testSaveRoleRejectsNegativeDiscount(): void
    {
        $this->expectException(OCSBadRequestException::class);
        $this->service->saveRole(['name' => 'Bad', 'maxDiscountPercent' => -1]);
    }//end testSaveRoleRejectsNegativeDiscount()

    /**
     * An empty name is rejected.
     *
     * @return void
     */
    public function testSaveRoleRejectsEmptyName(): void
    {
        $this->expectException(OCSBadRequestException::class);
        $this->service->saveRole(['name' => '   ', 'maxDiscountPercent' => 10]);
    }//end testSaveRoleRejectsEmptyName()

    /**
     * Updating an unknown id resolves to a 404 (write scoping).
     *
     * @return void
     */
    public function testSaveRoleUpdateUnknownIdThrowsNotFound(): void
    {
        $this->expectException(OCSNotFoundException::class);
        $this->service->saveRole(['name' => 'X', 'maxDiscountPercent' => 0], 'ghost');
    }//end testSaveRoleUpdateUnknownIdThrowsNotFound()

    /**
     * A role assigned to a staff member cannot be deleted.
     *
     * @return void
     */
    public function testDeleteRoleBlockedWhenAssigned(): void
    {
        $this->objects->seed(self::ROLE_SCHEMA, 'role-1', ['name' => 'Kassa']);
        $this->objects->seed(self::STAFF_SCHEMA, 'staff-1', ['displayName' => 'Anna', 'posRole' => 'role-1']);

        $this->expectException(OCSBadRequestException::class);
        $this->service->deleteRole('role-1');
    }//end testDeleteRoleBlockedWhenAssigned()

    /**
     * An unassigned role can be deleted.
     *
     * @return void
     */
    public function testDeleteRoleAllowedWhenUnassigned(): void
    {
        $this->objects->seed(self::ROLE_SCHEMA, 'role-2', ['name' => 'Manager']);

        $this->service->deleteRole('role-2');

        $this->assertArrayNotHasKey('role-2', ($this->objects->store[self::ROLE_SCHEMA] ?? []));
    }//end testDeleteRoleAllowedWhenUnassigned()

    /**
     * Counting staff with a role reflects the assignments.
     *
     * @return void
     */
    public function testCountStaffWithRole(): void
    {
        $this->objects->seed(self::STAFF_SCHEMA, 's1', ['posRole' => 'role-9']);
        $this->objects->seed(self::STAFF_SCHEMA, 's2', ['posRole' => 'role-9']);
        $this->objects->seed(self::STAFF_SCHEMA, 's3', ['posRole' => 'other']);

        $this->assertSame(2, $this->service->countStaffWithRole('role-9'));
    }//end testCountStaffWithRole()
}//end class
