<?php

/**
 * Pipelinq register access sync
 *
 * The register fragment `register.d/99-zzz-register-access.json` closes
 * pipelinq's registers to the built-in staff group `sales` (and the system
 * account). The deployment's own CRM group (app config `crm_group`, read by
 * ObjectOwnerAccessPolicy) cannot be named in a file, so this class writes it
 * into OpenRegister wherever `sales` is granted: on the registers' blocks and
 * on every schema that declares its own. It runs after every import (the
 * import writes the file's blocks back) and whenever the setting is saved,
 * when it also takes the previous group out.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Access
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/close-pipelinq-register-to-staff/specs/register-access/spec.md#requirement-the-configured-crm-group-is-granted-wherever-the-staff-group-is
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Access;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Grant the configured CRM group wherever the staff group is granted.
 *
 * @spec openspec/changes/close-pipelinq-register-to-staff/specs/register-access/spec.md#requirement-the-configured-crm-group-is-granted-wherever-the-staff-group-is
 */
class RegisterAccessSync {

	/**
	 * The built-in staff group the register fragment grants.
	 */
	public const STAFF_GROUP = 'sales';

	/**
	 * The registers pipelinq ships.
	 */
	public const REGISTERS = ['pipelinq', 'pipelinq-portal', 'sla'];

	/**
	 * Groups this class never adds or removes, whatever the setting says.
	 */
	private const RESERVED = ['', 'admin', 'public', 'authenticated', self::STAFF_GROUP];

	/**
	 * OpenRegister's register mapper, resolved lazily.
	 */
	private const REGISTER_MAPPER = 'OCA\\OpenRegister\\Db\\RegisterMapper';

	/**
	 * OpenRegister's schema mapper, resolved lazily.
	 */
	private const SCHEMA_MAPPER = 'OCA\\OpenRegister\\Db\\SchemaMapper';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig         $appConfig The `crm_group` setting.
	 * @param ContainerInterface $container Resolves OpenRegister's mappers.
	 * @param LoggerInterface    $logger    Records a sync that could not run.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write the configured CRM group into every block that grants the staff group.
	 *
	 * @param string $previous The group configured before, taken out of every block.
	 *
	 * @return int The number of registers and schemas written, or -1 when OpenRegister is unavailable.
	 *
	 * @spec openspec/changes/close-pipelinq-register-to-staff/specs/register-access/spec.md#requirement-the-configured-crm-group-is-granted-wherever-the-staff-group-is
	 */
	public function sync(string $previous=''): int {
		$group = trim($this->appConfig->getValueString(Application::APP_ID, ObjectOwnerAccessPolicy::CRM_GROUP_KEY, ''));
		$previous = trim($previous);
		if (in_array($group, self::RESERVED, true) === true && in_array($previous, self::RESERVED, true) === true) {
			return 0;
		}

		try {
			$registers = $this->container->get(self::REGISTER_MAPPER);
			$schemas   = $this->container->get(self::SCHEMA_MAPPER);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: the CRM group could not be written into the registers, OpenRegister is unavailable',
				['error' => $e->getMessage()]
			);
			return -1;
		}

		$written = 0;
		foreach (self::REGISTERS as $slug) {
			try {
				$register = $registers->find($slug, _rbac: false, _multitenancy: false);
			} catch (Throwable $e) {
				continue;
			}

			$written += $this->rewrite(mapper: $registers, entity: $register, group: $group, previous: $previous);

			foreach ((array)$register->getSchemas() as $schemaId) {
				try {
					$schema = $schemas->find($schemaId, [], _rbac: false, _multitenancy: false);
				} catch (Throwable $e) {
					continue;
				}

				$written += $this->rewrite(mapper: $schemas, entity: $schema, group: $group, previous: $previous);
			}
		}

		return $written;
	}//end sync()

	/**
	 * The block with the group granted wherever the staff group is, and the previous group gone.
	 *
	 * A verb that does not grant the staff group is left exactly as it is, so
	 * the service-account grants, the `public` create on a lead and the empty
	 * reads on the portal schemas never change.
	 *
	 * @param array<string, mixed> $authorization The block.
	 * @param string               $group         The configured CRM group, or ''.
	 * @param string               $previous      The group configured before, or ''.
	 *
	 * @return array<string, mixed> The rewritten block.
	 *
	 * @spec openspec/changes/close-pipelinq-register-to-staff/specs/register-access/spec.md#requirement-the-configured-crm-group-is-granted-wherever-the-staff-group-is
	 */
	public static function grant(array $authorization, string $group, string $previous=''): array {
		$addable   = (in_array($group, self::RESERVED, true) === false);
		$removable = (in_array($previous, self::RESERVED, true) === false && $previous !== $group);

		foreach ($authorization as $verb => $rules) {
			if (is_array($rules) === false || in_array(self::STAFF_GROUP, $rules, true) === false) {
				continue;
			}

			if ($removable === true) {
				$rules = array_values(array_filter($rules, static fn ($rule): bool => $rule !== $previous));
			}

			if ($addable === true && in_array($group, $rules, true) === false) {
				$rules[] = $group;
			}

			$authorization[$verb] = $rules;
		}

		return $authorization;
	}//end grant()

	/**
	 * Rewrite one entity's block when it changes.
	 *
	 * @param object $mapper   The entity's mapper.
	 * @param object $entity   The register or schema.
	 * @param string $group    The configured CRM group.
	 * @param string $previous The previous group.
	 *
	 * @return int 1 when written, else 0.
	 */
	private function rewrite(object $mapper, object $entity, string $group, string $previous): int {
		$current = $entity->getAuthorization();
		if (is_array($current) === false || $current === []) {
			return 0;
		}

		$next = self::grant(authorization: $current, group: $group, previous: $previous);
		if ($next === $current) {
			return 0;
		}

		try {
			$entity->setAuthorization($next);
			$mapper->update($entity);
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: the CRM group could not be written', ['error' => $e->getMessage()]);
			return 0;
		}

		return 1;
	}//end rewrite()
}//end class
