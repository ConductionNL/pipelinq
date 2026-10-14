<?php

/**
 * Pipelinq register access sync
 *
 * The register fragments `register.d/99-zzz-register-access-registers.json`
 * and `register.d/99-zzz-register-access.json` close
 * pipelinq's registers to the built-in staff group `sales` (and the system
 * account). Two groups a deployment picks cannot be named in a file, so this
 * class writes them into OpenRegister:
 *
 * - the CRM group (`crm_group`, read by ObjectOwnerAccessPolicy) is granted
 *   wherever `sales` is granted: the registers' blocks and every schema that
 *   declares its own;
 * - the CRM read group (`crm_read_group`) is granted `read` and `export`, and
 *   nothing else, on the schemas other apps read as the signed-in user for the
 *   party panel and the contact moments (READ_SCHEMAS), so a case handler in
 *   dossiq reads a party without CRM write rights.
 *
 * Each group last written is kept in app config (`<key>_applied`), so a
 * group the setting no longer names is taken out whichever way the setting
 * changed: the settings screen, an example set writing app config directly,
 * or `occ config:app:set`. The sync runs after every import (the import
 * writes the file's blocks back), when the settings are saved, and from
 * SyncRegisterAccessJob, which does nothing while the settings match what
 * was applied.
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
 * @spec openspec/changes/close-pipelinq-register-to-staff/specs/register-access/spec.md#requirement-a-configured-read-group-reads-the-parties-and-contact-moments-and-writes-nothing
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
 * Grant the configured CRM group and CRM read group in pipelinq's registers.
 *
 * @spec openspec/changes/close-pipelinq-register-to-staff/specs/register-access/spec.md#requirement-the-configured-crm-group-is-granted-wherever-the-staff-group-is
 * @spec openspec/changes/close-pipelinq-register-to-staff/specs/register-access/spec.md#requirement-a-configured-read-group-reads-the-parties-and-contact-moments-and-writes-nothing
 */
class RegisterAccessSync {

	/**
	 * The built-in staff group the register fragment grants.
	 */
	public const STAFF_GROUP = 'sales';

	/**
	 * The app config key of the read-only group.
	 */
	public const READ_GROUP_KEY = 'crm_read_group';

	/**
	 * The registers pipelinq ships.
	 */
	public const REGISTERS = ['pipelinq', 'pipelinq-portal', 'sla'];

	/**
	 * The schemas the read group reads.
	 *
	 * What dossiq reads as the signed-in case handler: the party (client and
	 * contact, PartyLeafProvider, CorrespondenceLanguageService), the contact
	 * moments (ticket, ContactMomentLeafProvider, ContactMomentFilingService)
	 * and the indicators held on a party (partyIndicatorValue,
	 * PartyIndicatorService). partyKind, partyFieldSet and partyIndicator are
	 * readable by every signed-in account already.
	 */
	public const READ_SCHEMAS = ['client', 'contact', 'ticket', 'partyIndicatorValue'];

	/**
	 * The verbs the read group is granted.
	 */
	public const READ_VERBS = ['read', 'export'];

	/**
	 * Groups this class never adds or removes, whatever the setting says.
	 */
	private const RESERVED = ['', 'admin', 'public', 'authenticated', self::STAFF_GROUP];

	/**
	 * The suffix of the app config key that holds the group last applied.
	 */
	private const APPLIED = '_applied';

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
	 * @param IAppConfig         $appConfig The settings and the groups last applied.
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
	 * Write the configured groups into the registers, taking out the groups last applied.
	 *
	 * @param bool $force Rewrite even when the settings match what was applied
	 *                    (after an import, which writes the file's blocks back).
	 *
	 * @return int The number of registers and schemas written, or -1 when OpenRegister is unavailable.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) A plain re-apply toggle, like SettingsService::loadSettings.
	 *
	 * @spec openspec/changes/close-pipelinq-register-to-staff/specs/register-access/spec.md#requirement-the-configured-crm-group-is-granted-wherever-the-staff-group-is
	 * @spec openspec/changes/close-pipelinq-register-to-staff/specs/register-access/spec.md#requirement-a-configured-read-group-reads-the-parties-and-contact-moments-and-writes-nothing
	 */
	public function sync(bool $force=false): int {
		$crm         = $this->setting(key: ObjectOwnerAccessPolicy::CRM_GROUP_KEY);
		$read        = $this->setting(key: self::READ_GROUP_KEY);
		$crmApplied  = $this->setting(key: ObjectOwnerAccessPolicy::CRM_GROUP_KEY.self::APPLIED);
		$readApplied = $this->setting(key: self::READ_GROUP_KEY.self::APPLIED);

		if ($force === false && $crm === $crmApplied && $read === $readApplied) {
			return 0;
		}

		try {
			$registers = $this->container->get(self::REGISTER_MAPPER);
			$schemas   = $this->container->get(self::SCHEMA_MAPPER);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: the CRM groups could not be written into the registers, OpenRegister is unavailable',
				['error' => $e->getMessage()]
			);
			return -1;
		}

		$groups  = ['read' => $read, 'readApplied' => $readApplied, 'crm' => $crm, 'crmApplied' => $crmApplied];
		$written = 0;
		foreach (self::REGISTERS as $slug) {
			try {
				$register = $registers->find($slug, _rbac: false, _multitenancy: false);
			} catch (Throwable $e) {
				continue;
			}

			$written += $this->syncRegister(registers: $registers, schemas: $schemas, register: $register, groups: $groups);
		}

		$this->appConfig->setValueString(Application::APP_ID, ObjectOwnerAccessPolicy::CRM_GROUP_KEY.self::APPLIED, $crm);
		$this->appConfig->setValueString(Application::APP_ID, self::READ_GROUP_KEY.self::APPLIED, $read);

		return $written;
	}//end sync()

	/**
	 * Rewrite one register and its schemas.
	 *
	 * The register never names the read group; a schema names it only when
	 * it is one of READ_SCHEMAS, and loses a previous one either way.
	 *
	 * @param object                $registers The register mapper.
	 * @param object                $schemas   The schema mapper.
	 * @param object                $register  The register.
	 * @param array<string, string> $groups    read, readApplied, crm and crmApplied.
	 *
	 * @return int The number of entities written.
	 */
	private function syncRegister(object $registers, object $schemas, object $register, array $groups): int {
		$written = $this->rewrite(
			mapper: $registers,
			entity: $register,
			read: '',
			readApplied: '',
			crm: $groups['crm'],
			crmApplied: $groups['crmApplied']
		);

		foreach ((array)$register->getSchemas() as $schemaId) {
			try {
				$schema = $schemas->find($schemaId, [], _rbac: false, _multitenancy: false);
			} catch (Throwable $e) {
				continue;
			}

			$read = '';
			if (in_array((string)$schema->getSlug(), self::READ_SCHEMAS, true) === true) {
				$read = $groups['read'];
			}

			$written += $this->rewrite(
				mapper: $schemas,
				entity: $schema,
				read: $read,
				readApplied: $groups['readApplied'],
				crm: $groups['crm'],
				crmApplied: $groups['crmApplied']
			);
		}

		return $written;
	}//end syncRegister()

	/**
	 * The block with the CRM group granted wherever the staff group is, and the group last applied gone.
	 *
	 * A verb that does not grant the staff group is left exactly as it is, so
	 * the service-account grants, the `public` create on a lead and the empty
	 * reads on the portal schemas never change.
	 *
	 * @param array<string, mixed> $authorization The block.
	 * @param string               $group         The configured CRM group, or ''.
	 * @param string               $previous      The group last applied, or ''.
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
	 * The block with the read group granted `read` and `export`, and the group last applied gone.
	 *
	 * The group is never granted create, update or delete, and a verb that
	 * does not grant the staff group (a schema closed even to the staff) is
	 * never opened to it. The group last applied is taken out of every verb,
	 * so a group moved from the CRM setting to the read setting keeps no write.
	 *
	 * @param array<string, mixed> $authorization The block.
	 * @param string               $group         The configured read group, or '' on a schema it does not read.
	 * @param string               $previous      The read group last applied, or ''.
	 *
	 * @return array<string, mixed> The rewritten block.
	 *
	 * @spec openspec/changes/close-pipelinq-register-to-staff/specs/register-access/spec.md#requirement-a-configured-read-group-reads-the-parties-and-contact-moments-and-writes-nothing
	 */
	public static function grantRead(array $authorization, string $group, string $previous=''): array {
		$addable   = (in_array($group, self::RESERVED, true) === false);
		$removable = (in_array($previous, self::RESERVED, true) === false && $previous !== $group);

		foreach ($authorization as $verb => $rules) {
			if (is_array($rules) === false) {
				continue;
			}

			if ($removable === true) {
				$rules = array_values(array_filter($rules, static fn ($rule): bool => $rule !== $previous));
			}

			$reads = in_array($verb, self::READ_VERBS, true) === true && in_array(self::STAFF_GROUP, $rules, true) === true;
			if ($addable === true && $reads === true && in_array($group, $rules, true) === false) {
				$rules[] = $group;
			}

			$authorization[$verb] = $rules;
		}

		return $authorization;
	}//end grantRead()

	/**
	 * A setting's trimmed value.
	 *
	 * @param string $key The app config key.
	 *
	 * @return string
	 */
	private function setting(string $key): string {
		return trim($this->appConfig->getValueString(Application::APP_ID, $key, ''));
	}//end setting()

	/**
	 * Rewrite one entity's block when it changes.
	 *
	 * The CRM read group is applied first, so a group named in both settings
	 * ends with the CRM group's grants.
	 *
	 * @param object $mapper      The entity's mapper.
	 * @param object $entity      The register or schema.
	 * @param string $read        The read group for this entity, or ''.
	 * @param string $readApplied The read group last applied.
	 * @param string $crm         The CRM group.
	 * @param string $crmApplied  The CRM group last applied.
	 *
	 * @return int 1 when written, else 0.
	 */
	private function rewrite(object $mapper, object $entity, string $read, string $readApplied, string $crm, string $crmApplied): int {
		$current = $entity->getAuthorization();
		if (is_array($current) === false || $current === []) {
			return 0;
		}

		$next = self::grantRead(authorization: $current, group: $read, previous: $readApplied);
		$next = self::grant(authorization: $next, group: $crm, previous: $crmApplied);
		if ($next === $current) {
			return 0;
		}

		try {
			$entity->setAuthorization($next);
			$mapper->update($entity);
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: the CRM groups could not be written', ['error' => $e->getMessage()]);
			return 0;
		}

		return 1;
	}//end rewrite()
}//end class
