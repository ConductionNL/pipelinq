<?php

/**
 * Contract of BsnAuditService
 *
 * The public surface of BsnAuditService that other components call, so they depend
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

use OCA\Pipelinq\Service\BsnAudit\LookupAudit;

/**
 * Contract of BsnAuditService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BsnAuditServiceInterface {

	/**
	 * Record a BSN lookup attempt — always invoked, regardless of outcome.
	 *
	 * Stores ONLY the SHA-256 hash of the BSN. The raw BSN never reaches this method's
	 * persistent path; callers MUST pass the raw BSN so it can be hashed in-process and
	 * immediately discarded.
	 *
	 * @param string      $actor  Actor user UID.
	 * @param LookupAudit $lookup The subject, grounds, outcome and actor role of the lookup.
	 *
	 * @return string The UUID of the written audit record (empty string if writing fails).
	 *
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-005-01
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) BsnValidationService::mask is a pure stateless helper.
	 */
	public function recordLookup(string $actor, LookupAudit $lookup): string;
}//end interface
