<?php

/**
 * Unit tests for PosStaffService (security-critical).
 *
 * Exercises the real PIN-hashing path through a fake IHasher backed by PHP's
 * password_hash / password_verify, so the stored credential is verified to be a
 * one-way hash (never the plain PIN) and verification is genuine. Covers: pinHash
 * is stripped from every read response; the PIN is required on create and
 * optional on edit (existing hash preserved); the 4-6 digit format gate; the
 * lockout after 5 consecutive failures with a 15-minute window; the failure
 * counter reset on success; inactive accounts and active lockouts are rejected;
 * and the role permission matrix is resolved on success (fail-closed on a
 * dangling role).
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
use OCA\Pipelinq\Service\PosStaffService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IAppConfig;
use OCP\Security\IHasher;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A fake IHasher wrapping real bcrypt hashing for genuine verify tests.
 */
class PosFakeHasher implements IHasher
{
    /**
     * Hash a message with bcrypt.
     *
     * @param string $message The plain-text message.
     *
     * @return string The hash.
     */
    public function hash(string $message): string
    {
        return password_hash($message, PASSWORD_BCRYPT);
    }

    /**
     * Verify a message against a hash.
     *
     * @param string $message  The plain-text message.
     * @param string $hash     The stored hash.
     * @param mixed  &$newHash A rehash slot (unused).
     *
     * @return bool True on match.
     */
    public function verify(string $message, string $hash, &$newHash=null): bool
    {
        return password_verify($message, $hash);
    }

    /**
     * Validate that a prefixed hash is well-formed.
     *
     * @param string $prefixedHash The hash to validate.
     *
     * @return bool Always true for the test double.
     */
    public function validate(string $prefixedHash): bool
    {
        return true;
    }
}

/**
 * Tests for PosStaffService.
 */
class PosStaffServiceTest extends TestCase
{
    private PosFakeObjectService $objects;

    private PosStaffService $service;

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

        $logger      = $this->createMock(LoggerInterface::class);
        $hasher      = new PosFakeHasher();
        $roleService = new PosRoleService($container, $appConfig, $logger);

        $this->service = new PosStaffService($container, $appConfig, $hasher, $roleService, $logger);
    }//end setUp()

    /**
     * Creating a staff member stores a hash (not the plain PIN) and never
     * returns it.
     *
     * @return void
     */
    public function testCreateHashesPinAndStripsHash(): void
    {
        $saved = $this->service->saveStaff([
            'displayName' => 'Anna de Vries',
            'posRole'     => 'role-1',
            'pin'         => '1234',
            'isActive'    => true,
        ]);

        $this->assertArrayNotHasKey('pinHash', $saved);
        $this->assertArrayNotHasKey('failedPinAttempts', $saved);

        $stored = $this->objects->store[self::STAFF_SCHEMA][$saved['id']];
        $this->assertArrayHasKey('pinHash', $stored);
        $this->assertNotSame('1234', $stored['pinHash']);
        $this->assertTrue(password_verify('1234', $stored['pinHash']));
    }//end testCreateHashesPinAndStripsHash()

    /**
     * Creating without a PIN is rejected.
     *
     * @return void
     */
    public function testCreateRequiresPin(): void
    {
        $this->expectException(OCSBadRequestException::class);
        $this->service->saveStaff(['displayName' => 'Bob', 'posRole' => 'role-1']);
    }//end testCreateRequiresPin()

    /**
     * A PIN outside 4-6 digits is rejected.
     *
     * @return void
     */
    public function testCreateRejectsShortPin(): void
    {
        $this->expectException(OCSBadRequestException::class);
        $this->service->saveStaff(['displayName' => 'Bob', 'posRole' => 'role-1', 'pin' => '12']);
    }//end testCreateRejectsShortPin()

    /**
     * Editing without a PIN preserves the existing hash.
     *
     * @return void
     */
    public function testEditPreservesExistingPin(): void
    {
        $hash = password_hash('4321', PASSWORD_BCRYPT);
        $this->objects->seed(self::STAFF_SCHEMA, 'staff-1', [
            'displayName' => 'Carla',
            'posRole'     => 'role-1',
            'pinHash'     => $hash,
            'isActive'    => true,
        ]);

        $this->service->saveStaff(['displayName' => 'Carla Peters', 'posRole' => 'role-1'], 'staff-1');

        $stored = $this->objects->store[self::STAFF_SCHEMA]['staff-1'];
        $this->assertSame($hash, $stored['pinHash']);
        $this->assertSame('Carla Peters', $stored['displayName']);
    }//end testEditPreservesExistingPin()

    /**
     * List output never contains the hash.
     *
     * @return void
     */
    public function testListStripsHash(): void
    {
        $this->objects->seed(self::STAFF_SCHEMA, 'staff-1', [
            'displayName' => 'Anna',
            'pinHash'     => password_hash('1234', PASSWORD_BCRYPT),
            'failedPinAttempts' => 3,
        ]);

        $list = $this->service->listStaff();

        $this->assertCount(1, $list);
        $this->assertArrayNotHasKey('pinHash', $list[0]);
        $this->assertArrayNotHasKey('failedPinAttempts', $list[0]);
    }//end testListStripsHash()

    /**
     * A correct PIN opens a session with the resolved permission matrix.
     *
     * @return void
     */
    public function testValidatePinSuccessReturnsPermissions(): void
    {
        $this->objects->seed(self::ROLE_SCHEMA, 'role-mgr', [
            'name'               => 'Manager',
            'canVoid'            => true,
            'maxDiscountPercent' => 100,
            'canRefund'          => true,
            'canNoSale'          => true,
        ]);
        $this->objects->seed(self::STAFF_SCHEMA, 'staff-1', [
            'displayName' => 'David Visser',
            'posRole'     => 'role-mgr',
            'pinHash'     => password_hash('9999', PASSWORD_BCRYPT),
            'isActive'    => true,
            'failedPinAttempts' => 2,
        ]);

        $session = $this->service->validatePin('staff-1', '9999');

        $this->assertSame('staff-1', $session['staffId']);
        $this->assertSame('David Visser', $session['displayName']);
        $this->assertTrue($session['permissions']['canVoid']);
        $this->assertSame(100, $session['permissions']['maxDiscountPercent']);
        // Failure counter reset on success.
        $this->assertSame(0, $this->objects->store[self::STAFF_SCHEMA]['staff-1']['failedPinAttempts']);
    }//end testValidatePinSuccessReturnsPermissions()

    /**
     * A wrong PIN is rejected and increments the failure counter.
     *
     * @return void
     */
    public function testValidatePinWrongIncrementsCounter(): void
    {
        $this->objects->seed(self::STAFF_SCHEMA, 'staff-1', [
            'displayName' => 'Anna',
            'posRole'     => 'role-1',
            'pinHash'     => password_hash('1234', PASSWORD_BCRYPT),
            'isActive'    => true,
            'failedPinAttempts' => 0,
        ]);

        try {
            $this->service->validatePin('staff-1', '0000');
            $this->fail('Expected OCSForbiddenException');
        } catch (OCSForbiddenException $e) {
            // Expected.
        }

        $this->assertSame(1, $this->objects->store[self::STAFF_SCHEMA]['staff-1']['failedPinAttempts']);
    }//end testValidatePinWrongIncrementsCounter()

    /**
     * The 5th consecutive failure sets a future lockout and blocks further tries.
     *
     * @return void
     */
    public function testLockoutAfterFiveFailures(): void
    {
        $this->objects->seed(self::STAFF_SCHEMA, 'staff-1', [
            'displayName' => 'Anna',
            'posRole'     => 'role-1',
            'pinHash'     => password_hash('1234', PASSWORD_BCRYPT),
            'isActive'    => true,
            'failedPinAttempts' => 4,
        ]);

        try {
            $this->service->validatePin('staff-1', '0000');
            $this->fail('Expected OCSForbiddenException on the 5th failure');
        } catch (OCSForbiddenException $e) {
            // Expected.
        }

        $stored = $this->objects->store[self::STAFF_SCHEMA]['staff-1'];
        $this->assertSame(5, $stored['failedPinAttempts']);
        $this->assertNotSame('', (string) $stored['lockedUntil']);
        $this->assertGreaterThan(time(), strtotime((string) $stored['lockedUntil']));

        // Even the correct PIN is now rejected while locked.
        $this->expectException(OCSForbiddenException::class);
        $this->service->validatePin('staff-1', '1234');
    }//end testLockoutAfterFiveFailures()

    /**
     * An inactive account is rejected even with the correct PIN.
     *
     * @return void
     */
    public function testInactiveAccountRejected(): void
    {
        $this->objects->seed(self::STAFF_SCHEMA, 'staff-1', [
            'displayName' => 'Emma',
            'posRole'     => 'role-1',
            'pinHash'     => password_hash('5678', PASSWORD_BCRYPT),
            'isActive'    => false,
        ]);

        $this->expectException(OCSForbiddenException::class);
        $this->service->validatePin('staff-1', '5678');
    }//end testInactiveAccountRejected()

    /**
     * A dangling role reference resolves to the most-restrictive matrix.
     *
     * @return void
     */
    public function testDanglingRoleFailsClosed(): void
    {
        $this->objects->seed(self::STAFF_SCHEMA, 'staff-1', [
            'displayName' => 'Anna',
            'posRole'     => 'ghost-role',
            'pinHash'     => password_hash('1234', PASSWORD_BCRYPT),
            'isActive'    => true,
        ]);

        $session = $this->service->validatePin('staff-1', '1234');

        $this->assertFalse($session['permissions']['canVoid']);
        $this->assertSame(0, $session['permissions']['maxDiscountPercent']);
        $this->assertFalse($session['permissions']['canRefund']);
        $this->assertFalse($session['permissions']['canNoSale']);
    }//end testDanglingRoleFailsClosed()
}//end class
