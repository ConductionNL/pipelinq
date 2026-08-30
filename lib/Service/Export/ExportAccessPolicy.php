<?php

/**
 * Pipelinq ExportAccessPolicy.
 *
 * Shared authorization predicate for the BI export / data-warehouse-sink
 * surface. Centralises the "export analyst OR Nextcloud admin" rule used to
 * gate destination / job configuration and the run-history audit views.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Export
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
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Export;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IGroupManager;

/**
 * Authorization predicate shared by the export controllers and services.
 *
 * Export configuration and run history expose every customer record that
 * flows to an external warehouse, so they are restricted to the configured
 * export-analyst group or Nextcloud admins (ADR-005). The predicate fails
 * closed: an empty user, or an unconfigured group, never grants access beyond
 * the explicit admin check.
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-001
 */
class ExportAccessPolicy {
	/**
	 * App-config key for the export-analyst group.
	 *
	 * @var string
	 */
	public const ANALYST_GROUP_KEY = 'export_analyst_group';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config.
	 * @param IGroupManager $groupManager The group manager.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Whether a user may configure or operate the export pipeline.
	 *
	 * Grants access to a Nextcloud admin or a member of the configured
	 * export-analyst group. Fails closed for an empty user or an unconfigured
	 * group (admins only).
	 *
	 * @param string $userId The acting user UID.
	 *
	 * @return bool Whether the user may use the export surface.
	 *
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-001
	 */
	public function isExportAdmin(string $userId): bool {
		if ($userId === '') {
			return false;
		}

		if ($this->groupManager->isAdmin($userId) === true) {
			return true;
		}

		$group = $this->appConfig->getValueString(Application::APP_ID, self::ANALYST_GROUP_KEY, '');
		if ($group === '') {
			return false;
		}

		return $this->groupManager->isInGroup($userId, $group);
	}//end isExportAdmin()
}//end class
