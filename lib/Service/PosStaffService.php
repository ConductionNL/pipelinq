<?php

/**
 * Pipelinq PosStaffService.
 *
 * Security-critical service for POS staff management and PIN authentication.
 * The PIN is only ever stored as a salted one-way hash (via Nextcloud's IHasher)
 * and is NEVER returned by any read path — listStaff / getStaff strip pinHash and
 * failedPinAttempts. validatePin verifies the hash in constant time (IHasher),
 * enforces the active-account and lockout preconditions, increments the failure
 * counter with a 15-minute lockout at 5 consecutive failures, and resolves the
 * server-authoritative role permission matrix only on success. No "isManager"
 * style flag is ever trusted from the client: a PIN grants an action solely after
 * this server-side verification.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use OCP\Security\IHasher;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for POS staff business operations and PIN authentication.
 *
 * All reads / writes are scoped to this app's own register + posStaff schema, so
 * a staff id from another app/register resolves to a 404 (IDOR-safe). The PIN
 * hash is the only credential and never leaves the server.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires the collaborators the
 *  PIN/permission flow legitimately needs (OR container, app config, hasher,
 *  role service, logger); splitting them would add indirection without reducing
 *  real coupling.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)   The public surface is the staff
 *  CRUD (list/get/save/delete) plus the two auth primitives (validatePin /
 *  getPermissions) — each single-purpose and unit-tested individually.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The class aggregates the
 *  whole staff/PIN concern (CRUD + format validation + hash/verify + lockout
 *  state machine + permission resolution + OR persistence helpers) as many
 *  small, single-purpose, individually unit-tested methods; the cohesion is
 *  intentional and splitting it would scatter one security-critical concern.
 *
 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#3
 */
class PosStaffService
{
    /**
     * Sensitive fields stripped from every read response.
     *
     * @var string[]
     */
    private const SENSITIVE_FIELDS = ['pinHash', 'failedPinAttempts'];

    /**
     * Number of consecutive failed attempts that triggers a lockout.
     *
     * @var int
     */
    private const LOCKOUT_THRESHOLD = 5;

    /**
     * Lockout duration in seconds (15 minutes).
     *
     * @var int
     */
    private const LOCKOUT_SECONDS = 900;

    /**
     * Minimum PIN length (digits).
     *
     * @var int
     */
    private const PIN_MIN_LENGTH = 4;

    /**
     * Maximum PIN length (digits).
     *
     * @var int
     */
    private const PIN_MAX_LENGTH = 6;

    /**
     * Constructor.
     *
     * @param ContainerInterface $container   The DI container.
     * @param IAppConfig         $appConfig   The app config.
     * @param IHasher            $hasher      The Nextcloud password hasher.
     * @param PosRoleService     $roleService The POS role service.
     * @param LoggerInterface    $logger      The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private IHasher $hasher,
        private PosRoleService $roleService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * List all POS staff, with sensitive fields stripped.
     *
     * @return array<int, array<string, mixed>> The staff members (no pinHash).
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#3
     */
    public function listStaff(): array
    {
        [$register, $schema] = $this->config(schemaKey: 'posStaff_schema');

        try {
            $results = $this->getObjectService()->findAll(
                config: [
                    'filters' => [
                        'register' => $register,
                        'schema'   => $schema,
                    ],
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Pipelinq: failed to list POS staff', ['exception' => $e->getMessage()]);
            return [];
        }

        $staff = [];
        foreach (($results ?? []) as $result) {
            $staff[] = $this->stripSensitive(staff: $this->toArray(object: $result));
        }

        return $staff;
    }//end listStaff()

    /**
     * Get a single staff member, with sensitive fields stripped.
     *
     * @param string $id The staff UUID.
     *
     * @return array<string, mixed> The staff member (no pinHash).
     *
     * @throws OCSNotFoundException If the staff member is not found in this app.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#3
     */
    public function getStaff(string $id): array
    {
        return $this->stripSensitive(staff: $this->fetchStaff(id: $id));
    }//end getStaff()

    /**
     * Create or update a staff member.
     *
     * Validates the display name and role reference. When a PIN is supplied it is
     * validated (4-6 digits) and stored only as an IHasher hash; when omitted on
     * an edit the existing hash is preserved (a PIN is required on create). The
     * response never contains pinHash.
     *
     * @param array<string, mixed> $data The staff data (may carry a plain-text `pin`).
     * @param string               $id   The staff UUID to update, or '' to create.
     *
     * @return array<string, mixed> The saved staff member (no pinHash).
     *
     * @throws OCSBadRequestException If the name/role is missing or the PIN is invalid.
     * @throws OCSNotFoundException   If updating a staff member that does not exist.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#3
     */
    public function saveStaff(array $data, string $id=''): array
    {
        $displayName = trim((string) ($data['displayName'] ?? ''));
        if ($displayName === '') {
            throw new OCSBadRequestException('Vul een naam in voor de medewerker.');
        }

        $roleRef = trim((string) ($data['posRole'] ?? ''));
        if ($roleRef === '') {
            throw new OCSBadRequestException('Selecteer een rol voor de medewerker.');
        }

        $existing = [];
        if ($id !== '') {
            $existing = $this->fetchStaff(id: $id);
        }

        $pin = (string) ($data['pin'] ?? '');

        $staff = [
            'displayName'       => $displayName,
            'userId'            => trim((string) ($data['userId'] ?? ($existing['userId'] ?? ''))),
            'posRole'           => $roleRef,
            'isActive'          => (bool) ($data['isActive'] ?? ($existing['isActive'] ?? true)),
            'failedPinAttempts' => (int) ($existing['failedPinAttempts'] ?? 0),
            'lockedUntil'       => (string) ($existing['lockedUntil'] ?? ''),
        ];

        if ($pin !== '') {
            $this->assertPinFormat(pin: $pin);
            $staff['pinHash']           = $this->hasher->hash($pin);
            $staff['failedPinAttempts'] = 0;
            $staff['lockedUntil']       = '';
        }

        if ($pin === '' && $id === '') {
            throw new OCSBadRequestException('Een pincode is verplicht bij een nieuwe medewerker.');
        }

        if ($pin === '') {
            // Preserve the existing hash on an edit that does not change the PIN.
            $staff['pinHash'] = (string) ($existing['pinHash'] ?? '');
        }

        $saved = $this->saveStaffObject(id: $id, staff: $staff);

        return $this->stripSensitive(staff: $saved);
    }//end saveStaff()

    /**
     * Delete a staff member.
     *
     * @param string $id The staff UUID.
     *
     * @return void
     *
     * @throws OCSNotFoundException   If the staff member does not exist in this app.
     * @throws OCSBadRequestException If the delete fails.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#3
     */
    public function deleteStaff(string $id): void
    {
        $this->fetchStaff(id: $id);

        [$register, $schema] = $this->config(schemaKey: 'posStaff_schema');

        try {
            $this->getObjectService()->deleteObject(uuid: $id, register: $register, schema: $schema);
        } catch (\Throwable $e) {
            $this->logger->error('Pipelinq: failed to delete POS staff', ['id' => $id, 'exception' => $e->getMessage()]);
            throw new OCSBadRequestException('Verwijderen van de medewerker is mislukt.');
        }
    }//end deleteStaff()

    /**
     * Verify a staff member's PIN and open a permission-bearing session payload.
     *
     * Fails closed and uniformly: an inactive account, an active lockout, or an
     * incorrect PIN all raise an exception without revealing the hash. On an
     * incorrect PIN the failure counter is incremented and a 15-minute lockout is
     * set at {@see self::LOCKOUT_THRESHOLD} consecutive failures. On success the
     * counter and lockout are cleared and the role permission matrix is returned.
     *
     * Verification is constant-time (IHasher::verify wraps password_verify).
     *
     * @param string $staffId The staff UUID.
     * @param string $pin     The submitted PIN.
     *
     * @return array<string, mixed> The session payload: staffId, displayName, permissions.
     *
     * @throws OCSNotFoundException   If the staff member does not exist in this app.
     * @throws OCSForbiddenException  If the account is inactive, locked, or the PIN is wrong.
     * @throws OCSBadRequestException If the PIN format is invalid.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#3
     */
    public function validatePin(string $staffId, string $pin): array
    {
        $this->assertPinFormat(pin: $pin);

        $staff = $this->fetchStaff(id: $staffId);

        if ((bool) ($staff['isActive'] ?? false) === false) {
            throw new OCSForbiddenException('Dit account is niet actief.');
        }

        if ($this->isLocked(staff: $staff) === true) {
            throw new OCSForbiddenException('Account geblokkeerd. Probeer het later opnieuw.');
        }

        $hash = (string) ($staff['pinHash'] ?? '');
        if ($hash === '' || $this->hasher->verify($pin, $hash) === false) {
            $this->registerFailure(staffId: $staffId, staff: $staff);
            throw new OCSForbiddenException('Onjuiste pincode.');
        }

        // Success: clear the failure counter / lockout.
        $staff['failedPinAttempts'] = 0;
        $staff['lockedUntil']       = '';
        $this->saveStaffObject(id: $staffId, staff: $staff);

        $permissions = $this->resolvePermissions(roleRef: (string) ($staff['posRole'] ?? ''));

        return [
            'staffId'     => $staffId,
            'displayName' => (string) ($staff['displayName'] ?? ''),
            'permissions' => $permissions,
        ];
    }//end validatePin()

    /**
     * Return the role permission matrix for a staff member.
     *
     * @param string $staffId The staff UUID.
     *
     * @return array<string, mixed> The permission matrix.
     *
     * @throws OCSNotFoundException If the staff member does not exist in this app.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#3
     */
    public function getPermissions(string $staffId): array
    {
        $staff = $this->fetchStaff(id: $staffId);

        return $this->resolvePermissions(roleRef: (string) ($staff['posRole'] ?? ''));
    }//end getPermissions()

    /**
     * Resolve a role reference (UUID) into the normalised permission matrix.
     *
     * A missing / unresolvable role yields the most restrictive matrix (no
     * permissions), so a dangling reference never fails open.
     *
     * @param string $roleRef The role UUID stored on the staff member.
     *
     * @return array{canVoid: bool, maxDiscountPercent: int, canRefund: bool, canNoSale: bool}
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#3
     */
    private function resolvePermissions(string $roleRef): array
    {
        $default = [
            'canVoid'            => false,
            'maxDiscountPercent' => 0,
            'canRefund'          => false,
            'canNoSale'          => false,
        ];

        if ($roleRef === '') {
            return $default;
        }

        try {
            $role = $this->roleService->getRole(id: $roleRef);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq: POS staff references an unresolvable role; denying all permissions',
                ['roleRef' => $roleRef, 'exception' => $e->getMessage()]
            );
            return $default;
        }

        return [
            'canVoid'            => (bool) ($role['canVoid'] ?? false),
            'maxDiscountPercent' => (int) ($role['maxDiscountPercent'] ?? 0),
            'canRefund'          => (bool) ($role['canRefund'] ?? false),
            'canNoSale'          => (bool) ($role['canNoSale'] ?? false),
        ];
    }//end resolvePermissions()

    /**
     * Record a failed PIN attempt, applying the lockout at the threshold.
     *
     * @param string               $staffId The staff UUID.
     * @param array<string, mixed> $staff   The current staff payload.
     *
     * @return void
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#3
     */
    private function registerFailure(string $staffId, array $staff): void
    {
        $attempts = ((int) ($staff['failedPinAttempts'] ?? 0) + 1);
        $staff['failedPinAttempts'] = $attempts;

        if ($attempts >= self::LOCKOUT_THRESHOLD) {
            $lockUntil            = (new DateTimeImmutable())->modify('+'.self::LOCKOUT_SECONDS.' seconds');
            $staff['lockedUntil'] = $lockUntil->format(DateTimeInterface::ATOM);
        }

        $this->saveStaffObject(id: $staffId, staff: $staff);
    }//end registerFailure()

    /**
     * Whether a staff member is currently within an active lockout window.
     *
     * @param array<string, mixed> $staff The staff payload.
     *
     * @return bool True when lockedUntil is in the future.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#3
     */
    public function isLocked(array $staff): bool
    {
        $lockedUntil = trim((string) ($staff['lockedUntil'] ?? ''));
        if ($lockedUntil === '') {
            return false;
        }

        $until = strtotime($lockedUntil);
        if ($until === false) {
            return false;
        }

        return $until > time();
    }//end isLocked()

    /**
     * Assert a PIN is 4-6 numeric digits.
     *
     * @param string $pin The PIN.
     *
     * @return void
     *
     * @throws OCSBadRequestException If the PIN is not 4-6 digits.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#3
     */
    private function assertPinFormat(string $pin): void
    {
        if (preg_match('/^[0-9]{'.self::PIN_MIN_LENGTH.','.self::PIN_MAX_LENGTH.'}$/', $pin) !== 1) {
            throw new OCSBadRequestException('Pincode moet uit 4 tot 6 cijfers bestaan.');
        }
    }//end assertPinFormat()

    /**
     * Strip sensitive fields from a staff payload before it leaves the server.
     *
     * @param array<string, mixed> $staff The staff payload.
     *
     * @return array<string, mixed> The staff payload without pinHash / failedPinAttempts.
     */
    private function stripSensitive(array $staff): array
    {
        foreach (self::SENSITIVE_FIELDS as $field) {
            unset($staff[$field]);
        }

        return $staff;
    }//end stripSensitive()

    /**
     * Fetch a staff member (including sensitive fields) scoped to this app.
     *
     * @param string $id The staff UUID.
     *
     * @return array<string, mixed> The raw staff payload.
     *
     * @throws OCSNotFoundException If the staff member is not found in this app's schema.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#3
     */
    private function fetchStaff(string $id): array
    {
        [$register, $schema] = $this->config(schemaKey: 'posStaff_schema');

        try {
            $object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
        } catch (\Throwable $e) {
            $object = null;
        }

        if ($object === null) {
            throw new OCSNotFoundException('Medewerker niet gevonden.');
        }

        return $this->toArray(object: $object);
    }//end fetchStaff()

    /**
     * Persist a staff object via the OR ObjectService.
     *
     * @param string               $id    The staff UUID, or '' to create.
     * @param array<string, mixed> $staff The staff data.
     *
     * @return array<string, mixed> The saved staff as an array.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#3
     */
    private function saveStaffObject(string $id, array $staff): array
    {
        [$register, $schema] = $this->config(schemaKey: 'posStaff_schema');

        unset($staff['@self']);

        $uuid = null;
        if ($id !== '') {
            $uuid = $id;
        }

        $saved = $this->getObjectService()->saveObject(
            object: $staff,
            extend: [],
            register: $register,
            schema: $schema,
            uuid: $uuid
        );

        return $this->toArray(object: $saved);
    }//end saveStaffObject()

    /**
     * Resolve the register + a schema config key into their stored IDs.
     *
     * @param string $schemaKey The app-config schema key (e.g. posStaff_schema).
     *
     * @return array{0: string, 1: string} The [register, schema] IDs.
     *
     * @throws OCSNotFoundException If the register or schema is not configured.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#3
     */
    private function config(string $schemaKey): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');

        if ($register === '' || $schema === '') {
            throw new OCSNotFoundException('POS register of schema is niet geconfigureerd.');
        }

        return [$register, $schema];
    }//end config()

    /**
     * Get the OpenRegister ObjectService.
     *
     * @return object The object service.
     *
     * @throws RuntimeException If OpenRegister is not available.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#3
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            throw new RuntimeException('OpenRegister service is not available.');
        }
    }//end getObjectService()

    /**
     * Normalise an OR object (entity or array) into a plain array.
     *
     * @param mixed $object The OR object.
     *
     * @return array<string, mixed> The object as an array.
     */
    private function toArray(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            $serialized = $object->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        if (is_object($object) === true && method_exists($object, 'getObject') === true) {
            $data = $object->getObject();
            if (is_array($data) === true) {
                return $data;
            }
        }

        return (array) $object;
    }//end toArray()
}//end class
