<?php

/**
 * Pipelinq PosRoleService.
 *
 * Manages POS role definitions (permission matrix) used by the staff PIN
 * authentication flow. Roles are stored as posRole objects in OpenRegister and
 * referenced by posStaff records.
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
 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Service for POS role business operations.
 *
 * Hosts the create/read/update/delete logic for the posRole schema plus the
 * "in-use" guard that prevents deleting a role still referenced by an active
 * posStaff record. All persistence flows through OpenRegister's ObjectService
 * (real API: find / findAll / saveObject / deleteObject).
 *
 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#2
 */
class PosRoleService {
	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config.
	 * @param LoggerInterface $logger The logger.
	 * @param ObjectServiceInterface $objectService OpenRegister object service.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * List all posRole objects.
	 *
	 * @return array<int, array<string, mixed>> The role objects.
	 *
	 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#2.1
	 */
	public function listRoles(): array {
		[$register, $schema] = $this->config(schemaKey: 'posRole_schema');

		$results = $this->getObjectService()->findAll(
			config: [
				'filters' => [
					'register' => $register,
					'schema' => $schema,
				],
				'limit' => 1000,
			]
		);

		$out = [];
		foreach ($results as $result) {
			$out[] = $this->toArray(object: $result);
		}

		return $out;
	}//end listRoles()

	/**
	 * Fetch a single role by id.
	 *
	 * @param string $id The role UUID.
	 *
	 * @return array<string, mixed> The role object.
	 *
	 * @throws OCSNotFoundException If the role is not found.
	 *
	 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#2.1
	 */
	public function getRole(string $id): array {
		[$register, $schema] = $this->config(schemaKey: 'posRole_schema');

		try {
			$object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$object = null;
		}

		if ($object === null) {
			throw new OCSNotFoundException('POS-rol niet gevonden.');
		}

		return $this->toArray(object: $object);
	}//end getRole()

	/**
	 * Create or update a posRole.
	 *
	 * Validates maxDiscountPercent is bounded to [0, 100] (REQ-PSP-001) before
	 * persisting via ObjectService::saveObject. When id is empty a new UUID is
	 * generated.
	 *
	 * @param array<string, mixed> $data The role data.
	 * @param string $id Optional UUID for updates.
	 *
	 * @return array<string, mixed> The saved role.
	 *
	 * @throws OCSBadRequestException If maxDiscountPercent is out of bounds or name is empty.
	 *
	 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#2.1
	 */
	public function saveRole(array $data, string $id = ''): array {
		$name = trim((string)($data['name'] ?? ''));
		if ($name === '') {
			throw new OCSBadRequestException('Rolnaam is verplicht.');
		}

		$max = (int)($data['maxDiscountPercent'] ?? 0);
		if ($max < 0 || $max > 100) {
			throw new OCSBadRequestException('Maximale korting moet tussen 0 en 100 liggen.');
		}

		$payload = [
			'name' => $name,
			'description' => (string)($data['description'] ?? ''),
			'canVoid' => (bool)($data['canVoid'] ?? false),
			'maxDiscountPercent' => $max,
			'canRefund' => (bool)($data['canRefund'] ?? false),
			'canNoSale' => (bool)($data['canNoSale'] ?? false),
		];

		[$register, $schema] = $this->config(schemaKey: 'posRole_schema');

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

		return $this->toArray(object: $saved);
	}//end saveRole()

	/**
	 * Delete a posRole.
	 *
	 * Refuses the deletion when the role is still referenced by any active
	 * posStaff record (REQ-PSP-001 scenario "Cannot delete a role assigned to
	 * active staff"). The check runs over the posStaff schema and counts
	 * non-soft-deleted entries with the matching posRole UUID.
	 *
	 * @param string $id The role UUID.
	 *
	 * @return void
	 *
	 * @throws OCSNotFoundException If the role is not found.
	 * @throws OCSForbiddenException If the role is still assigned to active staff.
	 *
	 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#2.1
	 */
	public function deleteRole(string $id): void {
		// Ensure the role exists first (throws OCSNotFoundException).
		$this->getRole(id: $id);

		$assignedCount = $this->countActiveStaffForRole(roleId: $id);
		if ($assignedCount > 0) {
			throw new OCSForbiddenException(
				'Deze rol is nog toegewezen aan ' . $assignedCount . ' actieve medewerker(s). '
				. 'Wijs ze eerst een andere rol toe.'
			);
		}

		[$register, $schema] = $this->config(schemaKey: 'posRole_schema');

		try {
			$this->getObjectService()->deleteObject(uuid: $id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: failed to delete posRole', ['exception' => $e->getMessage()]);
			throw new OCSNotFoundException('POS-rol kon niet worden verwijderd.');
		}
	}//end deleteRole()

	/**
	 * Count active posStaff records that reference the given role.
	 *
	 * @param string $roleId The posRole UUID.
	 *
	 * @return int Number of active staff assigned to the role.
	 *
	 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#2.1
	 */
	public function countActiveStaffForRole(string $roleId): int {
		try {
			[$register, $staffSchema] = $this->config(schemaKey: 'posStaff_schema');
		} catch (OCSNotFoundException $e) {
			return 0;
		}

		try {
			// Push the role-match filter down into OpenRegister instead of
			// fetching up to 2000 posStaff records and filtering in PHP. The
			// previous findAll() argument shape also did not match the OR
			// ObjectService signature. The `isActive` predicate stays in PHP
			// because the original semantics treat a *missing* isActive field as
			// active ((bool) ($staff['isActive'] ?? true)), which a server-side
			// `isActive == true` equality filter would wrongly exclude.
			$results = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $staffSchema,
						'posRole' => $roleId,
					],
				]
			);
		} catch (\Throwable $e) {
			return 0;
		}

		$count = 0;
		foreach ($results as $result) {
			$staff = $this->toArray(object: $result);
			if ((bool)($staff['isActive'] ?? true) === true) {
				$count++;
			}
		}

		return $count;
	}//end countActiveStaffForRole()

	/**
	 * Resolve the register + a schema config key into their stored IDs.
	 *
	 * @param string $schemaKey The app-config schema key.
	 *
	 * @return array{0: string, 1: string} The [register, schema] IDs.
	 *
	 * @throws OCSNotFoundException If the register or schema is not configured.
	 *
	 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#2.1
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
	 *
	 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#2.1
	 */
	private function getObjectService(): object {
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable — phpstan reports it as a dead catch.
		return $this->objectService;
	}//end getObjectService()

	/**
	 * Normalise an OR object (entity or array) into a plain array.
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
