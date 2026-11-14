<?php

/**
 * Contract of ContactmomentService
 *
 * The public surface of ContactmomentService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\NotPermittedException;

/**
 * Contract of ContactmomentService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ContactmomentServiceInterface {

	/**
	 * Delete a contactmoment with permission checking.
	 *
	 * Only the creating agent or a Nextcloud admin may delete. The target is a
	 * `ticket` narrowed to `ticketType: contactmoment` — a request- or
	 * complaint-type ticket is reported as not-found, so this surface can never
	 * delete another ticket subtype.
	 *
	 * @param string $id The contactmoment ticket UUID.
	 * @param string $currentUserId The ID of the user requesting deletion.
	 *
	 * @return bool True if deleted successfully.
	 *
	 * @throws DoesNotExistException If contactmoment not found.
	 * @throws NotPermittedException If user lacks permission.
	 *
	 * @spec openspec/specs/contactmomenten/spec.md#requirement-contactmoment-update-and-deletion
	 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
	 */
	public function delete(string $id, string $currentUserId): bool;
}//end interface
