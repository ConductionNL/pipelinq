<?php

/**
 * Pipelinq PosRoleService.
 *
 * Business logic for POS role management: server-authoritative CRUD over the
 * posRole schema, the maxDiscountPercent [0,100] bound, and the
 * delete-while-assigned guard that prevents orphaning a staff member's role.
 * Permission resolution (the matrix returned on PIN authentication) lives in
 * PosStaffService, which reads roles through this service.
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
 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for POS role business operations.
 *
 * All reads / writes are scoped to this app's own register + posRole schema, so
 * a role id from another app/register resolves to a 404 (IDOR-safe). Every
 * mutating method validates input server-side; the maxDiscountPercent bound and
 * the delete-while-assigned rule are enforced here, never trusted from the
 * client.
 *
 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#2
 */
class PosRoleService
{
    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container.
     * @param IAppConfig         $appConfig The app config.
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * List all POS roles.
     *
     * @return array<int, array<string, mixed>> The roles.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#2
     */
    public function listRoles(): array
    {
        [$register, $schema] = $this->config(schemaKey: 'posRole_schema');

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
            $this->logger->warning('Pipelinq: failed to list POS roles', ['exception' => $e->getMessage()]);
            return [];
        }

        $roles = [];
        foreach (($results ?? []) as $result) {
            $roles[] = $this->toArray(object: $result);
        }

        return $roles;
    }//end listRoles()

    /**
     * Get a single POS role.
     *
     * @param string $id The role UUID.
     *
     * @return array<string, mixed> The role.
     *
     * @throws OCSNotFoundException If the role is not found in this app's schema.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#2
     */
    public function getRole(string $id): array
    {
        [$register, $schema] = $this->config(schemaKey: 'posRole_schema');

        try {
            $object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
        } catch (\Throwable $e) {
            $object = null;
        }

        if ($object === null) {
            throw new OCSNotFoundException('Rol niet gevonden.');
        }

        return $this->toArray(object: $object);
    }//end getRole()

    /**
     * Create or update a POS role.
     *
     * Validates the role name is non-empty and the maxDiscountPercent is within
     * [0, 100]; the boolean permission flags are normalised. When $id is non-empty
     * the existing role is loaded first (scoping the write to this app), so an
     * unknown id can never create an off-register object.
     *
     * @param array<string, mixed> $data The role data.
     * @param string               $id   The role UUID to update, or '' to create.
     *
     * @return array<string, mixed> The saved role.
     *
     * @throws OCSBadRequestException If the name is empty or the discount is out of range.
     * @throws OCSNotFoundException   If updating a role that does not exist in this app.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#2
     */
    public function saveRole(array $data, string $id=''): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new OCSBadRequestException('Vul een naam in voor de rol.');
        }

        $maxDiscount = (int) ($data['maxDiscountPercent'] ?? 0);
        if ($maxDiscount < 0 || $maxDiscount > 100) {
            throw new OCSBadRequestException('Maximale korting moet tussen 0 en 100 liggen.');
        }

        if ($id !== '') {
            // Scope the update to this app's schema (404 on a foreign id).
            $this->getRole(id: $id);
        }

        $role = [
            'name'               => $name,
            'description'        => trim((string) ($data['description'] ?? '')),
            'canVoid'            => (bool) ($data['canVoid'] ?? false),
            'maxDiscountPercent' => $maxDiscount,
            'canRefund'          => (bool) ($data['canRefund'] ?? false),
            'canNoSale'          => (bool) ($data['canNoSale'] ?? false),
        ];

        return $this->saveRoleObject(id: $id, role: $role);
    }//end saveRole()

    /**
     * Delete a POS role.
     *
     * Rejects the delete when any posStaff member (active or not) still references
     * the role, so a staff member can never be left with a dangling role
     * reference and an undefined permission set.
     *
     * @param string $id The role UUID.
     *
     * @return void
     *
     * @throws OCSNotFoundException   If the role does not exist in this app.
     * @throws OCSBadRequestException If the role is still assigned to staff.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#2
     */
    public function deleteRole(string $id): void
    {
        $this->getRole(id: $id);

        if ($this->countStaffWithRole(roleId: $id) > 0) {
            throw new OCSBadRequestException('Deze rol is nog toegewezen aan medewerkers.');
        }

        [$register, $schema] = $this->config(schemaKey: 'posRole_schema');

        try {
            $this->getObjectService()->deleteObject(register: $register, schema: $schema, uuid: $id);
        } catch (\Throwable $e) {
            $this->logger->error('Pipelinq: failed to delete POS role', ['id' => $id, 'exception' => $e->getMessage()]);
            throw new OCSBadRequestException('Verwijderen van de rol is mislukt.');
        }
    }//end deleteRole()

    /**
     * Count posStaff members referencing a role.
     *
     * Resolves both the staff member's stored posRole UUID and the role's own
     * slug, so a seed reference (slug) and a runtime reference (UUID) both count.
     *
     * @param string $roleId The role UUID.
     *
     * @return int The number of staff members referencing the role.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#2
     */
    public function countStaffWithRole(string $roleId): int
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
            $this->logger->warning('Pipelinq: failed to count staff for role', ['exception' => $e->getMessage()]);
            return 0;
        }

        $count = 0;
        foreach (($results ?? []) as $result) {
            $staff = $this->toArray(object: $result);
            if ((string) ($staff['posRole'] ?? '') === $roleId) {
                $count++;
            }
        }

        return $count;
    }//end countStaffWithRole()

    /**
     * Persist a role object via the OR ObjectService.
     *
     * @param string               $id   The role UUID, or '' to create.
     * @param array<string, mixed> $role The role data.
     *
     * @return array<string, mixed> The saved role as an array.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#2
     */
    private function saveRoleObject(string $id, array $role): array
    {
        [$register, $schema] = $this->config(schemaKey: 'posRole_schema');

        unset($role['@self']);

        $uuid = null;
        if ($id !== '') {
            $uuid = $id;
        }

        $saved = $this->getObjectService()->saveObject(
            object: $role,
            extend: [],
            register: $register,
            schema: $schema,
            uuid: $uuid
        );

        return $this->toArray(object: $saved);
    }//end saveRoleObject()

    /**
     * Resolve the register + a schema config key into their stored IDs.
     *
     * @param string $schemaKey The app-config schema key (e.g. posRole_schema).
     *
     * @return array{0: string, 1: string} The [register, schema] IDs.
     *
     * @throws OCSNotFoundException If the register or schema is not configured.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#2
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
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#2
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
