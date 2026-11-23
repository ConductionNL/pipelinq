<?php

/**
 * Contract of BillingHandoffAccessPolicy
 *
 * The public surface of BillingHandoffAccessPolicy that other components call, so they depend
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

/**
 * Contract of BillingHandoffAccessPolicy.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/time-approval-workflow/spec.md
 */
interface BillingHandoffAccessPolicyInterface {

	/**
	 * Whether a user may trigger the "Send to billing" handoff.
	 *
	 * A manager is a member of the configured manager group
	 * (`billing_handoff_manager_group`) or a Nextcloud administrator. Fails
	 * closed: an empty user or an unconfigured group never grants access
	 * beyond the explicit admin check (closes the IDOR — any authenticated
	 * user could otherwise trigger a billing batch for any client).
	 *
	 * @param string $userId The acting user UID.
	 *
	 * @return bool Whether the user may trigger the billing handoff.
	 *
	 * @spec openspec/specs/time-approval-workflow/spec.md
	 */
	public function isManager(string $userId): bool;
}//end interface
