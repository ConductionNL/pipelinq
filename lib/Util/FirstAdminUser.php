<?php

/**
 * Pipelinq FirstAdminUser.
 *
 * The first member of the admin group, for repair work that saves as an admin.
 *
 * @category Util
 * @package  OCA\Pipelinq\Util
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Util;

use OCP\IUser;

/**
 * Answers the first user of the 'admin' group, or null when the group is
 * missing or empty. The host class provides an IGroupManager `$groupManager`
 * property.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait FirstAdminUser {

	/**
	 * Resolve an admin to act as while saving.
	 *
	 * @return IUser|null The first admin, or null when none exists.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function actingAdmin(): ?IUser {
		$admins = $this->groupManager->get('admin')?->getUsers() ?? [];

		return (array_values($admins)[0] ?? null);
	}//end actingAdmin()
}//end trait
