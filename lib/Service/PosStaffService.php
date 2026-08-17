<?php

/**
 * Pipelinq PosStaffService.
 *
 * Manages POS staff records and the PIN-based authentication flow used at the
 * point-of-sale terminal. Stores PINs only as bcrypt hashes; the plain text is
 * never persisted or logged. Implements the 5-attempt / 15-minute lockout.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
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
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Service for POS staff business operations.
 *
 * All reads of staff records strip the bcrypt `pinHash` from the response
 * envelope to guarantee the hash never leaves the process. validatePin owns
 * the authentication path: PASSWORD_BCRYPT verify, lockout counter increment,
 * 15-minute lock at 5 consecutive failures, counter reset on success.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Wires the collaborators a
 *  staff service legitimately needs (OR container, app config, role lookup,
 *  logger) — splitting them would add indirection without reducing coupling.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     Cohesive CRUD + auth surface;
 *  every method is single-purpose.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) CRUD + PIN-auth + lockout
 *  logic is inherently branchy; split across small focused methods.
 *
 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#3
 */
class PosStaffService {
	/**
	 * Maximum consecutive failed PIN attempts before a 15-minute lockout.
	 *
	 * @var int
	 */
	public const LOCKOUT_THRESHOLD = 5;

	/**
	 * Lockout duration in seconds (15 minutes).
	 *
	 * @var int
	 */
	public const LOCKOUT_SECONDS = 900;

	/**
	 * Bcrypt cost factor. Balances safety against PIN-entry latency on the
	 * shared POS terminal.
	 *
	 * @var int
	 */
	public const BCRYPT_COST = 12;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config.
	 * @param PosRoleService $posRoleService The POS role service (for permission lookup).
	 * @param LoggerInterface $logger The logger.
	 * @param ObjectServiceInterface $objectService OpenRegister object service.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private PosRoleService $posRoleService,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * List all posStaff records with the bcrypt pinHash stripped.
	 *
	 * @return array<int, array<string, mixed>> The staff objects without pinHash.
	 *
	 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#3.1
	 */
	public function listStaff(): array {
		[$register, $schema] = $this->config(schemaKey: 'posStaff_schema');

		$results = $this->getObjectService()->findAll(
			config: [
				'filters' => [
					'register' => $register,
					'schema' => $schema,
				],
				'limit' => 2000,
			]
		);

		$out = [];
		foreach ($results as $result) {
			$out[] = $this->stripSensitive(staff: $this->toArray(object: $result));
		}

		return $out;
	}//end listStaff()

	/**
	 * Fetch a single staff record with the bcrypt pinHash stripped.
	 *
	 * @param string $id The staff UUID.
	 *
	 * @return array<string, mixed> The staff object without pinHash.
	 *
	 * @throws OCSNotFoundException If the staff is not found.
	 *
	 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#3.1
	 */
	public function getStaff(string $id): array {
		$staff = $this->fetchStaff(id: $id);
		return $this->stripSensitive(staff: $staff);
	}//end getStaff()

	/**
	 * Create or update a posStaff record.
	 *
	 * On create the PIN is required; on update an empty/absent PIN preserves
	 * the existing hash. PIN format must be 4-6 digits (REQ-PSP-002). The PIN
	 * is bcrypt-hashed with PASSWORD_BCRYPT before persistence; the plain text
	 * never lands in the OR row.
	 *
	 * @param array<string, mixed> $data The staff data.
	 * @param string $id Optional UUID for updates.
	 *
	 * @return array<string, mixed> The saved staff (pinHash stripped).
	 *
	 * @throws OCSBadRequestException If displayName/role missing, or PIN format is invalid on create/update.
	 *
	 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#3.1
	 */
	public function saveStaff(array $data, string $id = ''): array {
		$displayName = trim((string)($data['displayName'] ?? ''));
		if ($displayName === '') {
			throw new OCSBadRequestException('Naam is verplicht.');
		}

		$posRole = trim((string)($data['posRole'] ?? ''));
		if ($posRole === '') {
			throw new OCSBadRequestException('Rol is verplicht.');
		}

		// Verify role exists.
		$this->posRoleService->getRole(id: $posRole);

		[$existing, $isUpdate] = $this->resolveExistingStaff(id: $id);

		$hash = $this->resolvePinHash(data: $data, isUpdate: $isUpdate, existing: $existing);

		$payload = $this->buildStaffPayload(
			data: $data,
			posRole: $posRole,
			displayName: $displayName,
			hash: $hash,
			existing: $existing
		);

		[$register, $schema] = $this->config(schemaKey: 'posStaff_schema');

		$uuid = $id;
		if ($uuid === '') {
			$uuid = $this->uuid();
		}

		$saved = $this->getObjectService()->saveObject(
			object: $payload,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: $uuid
		);

		return $this->stripSensitive(staff: $this->toArray(object: $saved));
	}//end saveStaff()

	/**
	 * Resolve the existing staff record (if any) for a saveStaff() call.
	 *
	 * Falls back to "create" semantics when an id is supplied but the
	 * record cannot be found.
	 *
	 * @param string $id Optional UUID for updates.
	 *
	 * @return array{0: ?array<string, mixed>, 1: bool} [existing, isUpdate].
	 */
	private function resolveExistingStaff(string $id): array {
		$isUpdate = ($id !== '');
		$existing = null;
		if ($isUpdate === true) {
			try {
				$existing = $this->fetchStaff(id: $id);
			} catch (OCSNotFoundException $e) {
				$existing = null;
				$isUpdate = false;
			}
		}

		return [$existing, $isUpdate];
	}//end resolveExistingStaff()

	/**
	 * Resolve the pinHash to persist for a saveStaff() call.
	 *
	 * A submitted PIN is validated and re-hashed; otherwise an update
	 * falls back to the existing hash. Creating without a PIN is rejected.
	 *
	 * @param array<string, mixed> $data The staff data.
	 * @param bool $isUpdate Whether this is an update.
	 * @param ?array<string, mixed> $existing The existing staff record, if any.
	 *
	 * @return string The bcrypt pinHash to persist.
	 *
	 * @throws OCSBadRequestException If the PIN format is invalid, or missing on create.
	 */
	private function resolvePinHash(array $data, bool $isUpdate, ?array $existing): string {
		$pin = (string)($data['pin'] ?? '');
		$hash = '';
		if ($isUpdate === true && $existing !== null) {
			$hash = (string)($existing['pinHash'] ?? '');
		}

		if ($pin !== '') {
			if ($this->isValidPinFormat(pin: $pin) === false) {
				throw new OCSBadRequestException('PIN moet 4 tot 6 cijfers bevatten.');
			}

			// Password_hash with PASSWORD_BCRYPT always returns a non-empty
			// string on PHP 8 (the deprecated false-on-failure return is
			// gone), so no fallback is needed at this layer.
			$hash = password_hash($pin, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
		}

		if ($isUpdate === false && $hash === '') {
			throw new OCSBadRequestException('PIN is verplicht bij het aanmaken van een medewerker.');
		}

		return $hash;
	}//end resolvePinHash()

	/**
	 * Build the persisted payload for a saveStaff() call.
	 *
	 * @param array<string, mixed> $data The staff data.
	 * @param string $posRole The posRole UUID.
	 * @param string $displayName The trimmed display name.
	 * @param string $hash The resolved pinHash.
	 * @param ?array<string, mixed> $existing The existing staff record, if any.
	 *
	 * @return array<string, mixed> The payload to save.
	 */
	private function buildStaffPayload(array $data, string $posRole, string $displayName, string $hash, ?array $existing): array {
		$payload = [
			'displayName' => $displayName,
			'userId' => (string)($data['userId'] ?? ($existing['userId'] ?? '')),
			'posRole' => $posRole,
			'pinHash' => $hash,
			'isActive' => (bool)($data['isActive'] ?? ($existing['isActive'] ?? true)),
			'failedPinAttempts' => (int)($existing['failedPinAttempts'] ?? 0),
		];

		if (isset($existing['lockedUntil']) === true && $existing['lockedUntil'] !== '') {
			$payload['lockedUntil'] = (string)$existing['lockedUntil'];
		}

		if (isset($existing['lastLoginAt']) === true && $existing['lastLoginAt'] !== '') {
			$payload['lastLoginAt'] = (string)$existing['lastLoginAt'];
		}

		return $payload;
	}//end buildStaffPayload()

	/**
	 * Delete a staff record.
	 *
	 * @param string $id The staff UUID.
	 *
	 * @return void
	 *
	 * @throws OCSNotFoundException If deletion fails.
	 *
	 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#3.1
	 */
	public function deleteStaff(string $id): void {
		// Ensure the staff exists first (throws OCSNotFoundException).
		$this->fetchStaff(id: $id);

		[$register, $schema] = $this->config(schemaKey: 'posStaff_schema');

		try {
			$this->getObjectService()->deleteObject(uuid: $id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: failed to delete posStaff', ['exception' => $e->getMessage()]);
			throw new OCSNotFoundException('Medewerker kon niet worden verwijderd.');
		}
	}//end deleteStaff()

	/**
	 * Validate a staff member's PIN and open a session.
	 *
	 * Enforces inactive-account block, current-lockout block, bcrypt verify
	 * (constant-time), failed-attempt counter, 5-strike 15-minute lockout, and
	 * counter reset on success. On success returns a flat envelope with the
	 * staff id, display name, and the role's permission matrix.
	 *
	 * @param string $staffId The staff UUID to authenticate.
	 * @param string $pin The submitted plain-text PIN.
	 *
	 * @return array{staffId: string, displayName: string, permissions: array<string, mixed>, expiresAt: string} The session envelope.
	 *
	 * @throws OCSNotFoundException If the staff is not found.
	 * @throws OCSForbiddenException If the account is inactive, currently locked, or the PIN does not match.
	 *
	 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#3.1
	 */
	public function validatePin(string $staffId, string $pin): array {
		if ($this->isValidPinFormat(pin: $pin) === false) {
			throw new OCSForbiddenException('PIN onjuist.');
		}

		$staff = $this->fetchStaff(id: $staffId);

		if ((bool)($staff['isActive'] ?? true) === false) {
			throw new OCSForbiddenException('Account is gedeactiveerd.');
		}

		$lockedUntil = (string)($staff['lockedUntil'] ?? '');
		if ($lockedUntil !== '' && $this->isStillLocked(lockedUntil: $lockedUntil) === true) {
			throw new OCSForbiddenException('Account is geblokkeerd. Probeer het later opnieuw.');
		}

		$hash = (string)($staff['pinHash'] ?? '');
		if ($hash === '' || password_verify($pin, $hash) === false) {
			$this->incrementFailedAttempts(staff: $staff);
			throw new OCSForbiddenException('PIN onjuist.');
		}

		// Successful login: clear counters, update lastLoginAt.
		$this->markLoginSuccess(staff: $staff);

		$permissions = $this->resolvePermissions(roleId: (string)($staff['posRole'] ?? ''));

		return [
			'staffId' => (string)($staff['id'] ?? $staffId),
			'displayName' => (string)($staff['displayName'] ?? ''),
			'permissions' => $permissions,
			'expiresAt' => (new DateTimeImmutable('+8 hours'))->format(DateTimeInterface::ATOM),
		];
	}//end validatePin()

	/**
	 * Get the role's permission matrix for a staff member without verifying the PIN.
	 *
	 * Used by the controllers' authorizeStaff helper when an existing session
	 * needs to confirm the current permission set (e.g. on role-edit
	 * reauthorization).
	 *
	 * @param string $staffId The staff UUID.
	 *
	 * @return array<string, mixed> The permission matrix.
	 *
	 * @throws OCSNotFoundException If the staff is not found.
	 *
	 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#3.1
	 */
	public function getPermissions(string $staffId): array {
		$staff = $this->fetchStaff(id: $staffId);
		return $this->resolvePermissions(roleId: (string)($staff['posRole'] ?? ''));
	}//end getPermissions()

	/**
	 * Validate that the caller is allowed to mutate the given staff object.
	 *
	 * Per ADR-005 Rule 3, mutation endpoints MUST run a per-object
	 * authorization check. POS staff records are admin-managed: only an admin
	 * (group `admin`) is allowed to mutate. The actual `isAdmin` check is the
	 * caller controller's responsibility; this helper is the policy gate that
	 * confirms the object actually belongs to this app's posStaff schema (so
	 * an attacker cannot pass a foreign id and have an admin endpoint
	 * silently operate on someone else's data).
	 *
	 * @param string $staffId The staff UUID.
	 * @param string $userId The acting Nextcloud user UID (informational only).
	 *
	 * @return array<string, mixed> The authorised staff record.
	 *
	 * @throws OCSNotFoundException If the staff is not found in this app's schema.
	 *
	 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#3.3
	 */
	public function authorizeStaff(string $staffId, string $userId): array {
		$staff = $this->fetchStaff(id: $staffId);
		unset($userId);
		// Admin is enforced at the controller; this is the schema-scope guard.
		return $staff;
	}//end authorizeStaff()

	/**
	 * Fetch a staff record by id without stripping the pinHash (internal use).
	 *
	 * @param string $id The staff UUID.
	 *
	 * @return array<string, mixed> The raw staff object.
	 *
	 * @throws OCSNotFoundException If not found.
	 */
	private function fetchStaff(string $id): array {
		[$register, $schema] = $this->config(schemaKey: 'posStaff_schema');

		try {
			$object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$object = null;
		}

		if ($object === null) {
			throw new OCSNotFoundException('Medewerker niet gevonden.');
		}

		$staff = $this->toArray(object: $object);
		$staff['id'] = (string)($staff['id'] ?? $id);

		return $staff;
	}//end fetchStaff()

	/**
	 * Strip sensitive fields (pinHash) from a staff response envelope.
	 *
	 * @param array<string, mixed> $staff The raw staff object.
	 *
	 * @return array<string, mixed> The staff object without pinHash.
	 */
	private function stripSensitive(array $staff): array {
		unset($staff['pinHash']);
		return $staff;
	}//end stripSensitive()

	/**
	 * Determine whether a PIN is in the accepted format (4 to 6 digits).
	 *
	 * @param string $pin The PIN to validate.
	 *
	 * @return bool True when the PIN is exactly 4-6 ASCII digits.
	 */
	private function isValidPinFormat(string $pin): bool {
		return preg_match('/^[0-9]{4,6}$/', $pin) === 1;
	}//end isValidPinFormat()

	/**
	 * Resolve the role permission matrix for a given role id.
	 *
	 * Returns a zero-permission matrix when the role can't be found, so a
	 * stale staff record (orphan role) can never elevate.
	 *
	 * @param string $roleId The posRole UUID.
	 *
	 * @return array<string, mixed> The permission matrix.
	 */
	private function resolvePermissions(string $roleId): array {
		if ($roleId === '') {
			return $this->emptyPermissions();
		}

		try {
			$role = $this->posRoleService->getRole(id: $roleId);
		} catch (\Throwable $e) {
			return $this->emptyPermissions();
		}

		return [
			'canVoid' => (bool)($role['canVoid'] ?? false),
			'maxDiscountPercent' => (int)($role['maxDiscountPercent'] ?? 0),
			'canRefund' => (bool)($role['canRefund'] ?? false),
			'canNoSale' => (bool)($role['canNoSale'] ?? false),
			'roleId' => $roleId,
			'roleName' => (string)($role['name'] ?? ''),
		];
	}//end resolvePermissions()

	/**
	 * Empty (deny-all) permission matrix.
	 *
	 * @return array<string, mixed> Zero permissions.
	 */
	private function emptyPermissions(): array {
		return [
			'canVoid' => false,
			'maxDiscountPercent' => 0,
			'canRefund' => false,
			'canNoSale' => false,
			'roleId' => '',
			'roleName' => '',
		];
	}//end emptyPermissions()

	/**
	 * Determine whether the staff is still locked out based on a stored ISO time.
	 *
	 * @param string $lockedUntil ISO 8601 timestamp.
	 *
	 * @return bool True when "now" is before the lock expiry.
	 */
	private function isStillLocked(string $lockedUntil): bool {
		try {
			$lockTime = new DateTimeImmutable($lockedUntil);
		} catch (\Throwable $e) {
			return false;
		}

		return $lockTime > new DateTimeImmutable();
	}//end isStillLocked()

	/**
	 * Increment failedPinAttempts and apply the lockout when threshold reached.
	 *
	 * @param array<string, mixed> $staff The current staff record.
	 *
	 * @return void
	 */
	private function incrementFailedAttempts(array $staff): void {
		$current = (int)($staff['failedPinAttempts'] ?? 0);
		$next = ($current + 1);

		$update = [
			'failedPinAttempts' => $next,
		];

		if ($next >= self::LOCKOUT_THRESHOLD) {
			$update['lockedUntil'] = (new DateTimeImmutable('+' . self::LOCKOUT_SECONDS . ' seconds'))
				->format(DateTimeInterface::ATOM);
			$update['failedPinAttempts'] = 0;
		}

		$this->updateStaffFields(staff: $staff, fields: $update);
	}//end incrementFailedAttempts()

	/**
	 * Reset failedPinAttempts and stamp lastLoginAt on a successful PIN login.
	 *
	 * @param array<string, mixed> $staff The current staff record.
	 *
	 * @return void
	 */
	private function markLoginSuccess(array $staff): void {
		$this->updateStaffFields(
			staff: $staff,
			fields: [
				'failedPinAttempts' => 0,
				'lockedUntil' => '',
				'lastLoginAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
			]
		);
	}//end markLoginSuccess()

	/**
	 * Persist a partial update on a staff record.
	 *
	 * @param array<string, mixed> $staff The current staff record (must include id, pinHash, posRole, displayName).
	 * @param array<string, mixed> $fields The fields to overwrite.
	 *
	 * @return void
	 */
	private function updateStaffFields(array $staff, array $fields): void {
		$id = (string)($staff['id'] ?? '');
		if ($id === '') {
			return;
		}

		$payload = [
			'displayName' => (string)($staff['displayName'] ?? ''),
			'userId' => (string)($staff['userId'] ?? ''),
			'posRole' => (string)($staff['posRole'] ?? ''),
			'pinHash' => (string)($staff['pinHash'] ?? ''),
			'isActive' => (bool)($staff['isActive'] ?? true),
			'failedPinAttempts' => (int)($staff['failedPinAttempts'] ?? 0),
			'lockedUntil' => (string)($staff['lockedUntil'] ?? ''),
			'lastLoginAt' => (string)($staff['lastLoginAt'] ?? ''),
		];

		foreach ($fields as $key => $value) {
			$payload[$key] = $value;
		}

		try {
			[$register, $schema] = $this->config(schemaKey: 'posStaff_schema');
			$this->getObjectService()->saveObject(
				object: $payload,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: $id
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: failed to update posStaff', ['exception' => $e->getMessage()]);
		}
	}//end updateStaffFields()

	/**
	 * Resolve the register + schema config key into stored IDs.
	 *
	 * @param string $schemaKey The app-config schema key.
	 *
	 * @return array{0: string, 1: string} The [register, schema] IDs.
	 *
	 * @throws OCSNotFoundException If the register or schema is not configured.
	 */
	private function config(string $schemaKey): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');

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
	 */
	private function getObjectService(): object {
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable — phpstan reports it as a dead catch.
		return $this->objectService;
	}//end getObjectService()

	/**
	 * Normalise an OR object into a plain array.
	 *
	 * @param mixed $object The OR object.
	 *
	 * @return array<string, mixed> The object as an array.
	 */
	private function toArray(mixed $object): array {
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

		return (array)$object;
	}//end toArray()

	/**
	 * Generate a v4 UUID.
	 *
	 * @return string The UUID.
	 */
	private function uuid(): string {
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3F) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}//end uuid()
}//end class
