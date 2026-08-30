<?php

/**
 * Pipelinq InvalidTenderException.
 *
 * Domain exception raised by PosTenderService when a tender add / remove /
 * settle-validation operation cannot proceed because the transaction is in
 * the wrong status, the tender amount does not equal the transaction total,
 * the reference required by the tender type is missing, or the tender
 * amount fails its minimum / type-config invariant. Controllers map this to
 * HTTP 409 Conflict (settled-state mismatch) or HTTP 400 Bad Request
 * (validation failure) depending on the carried $statusCode.
 *
 * @category Exception
 * @package  OCA\Pipelinq\Service
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
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-002
 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-003
 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use Exception;

/**
 * A safe, status-bearing tender-domain error.
 *
 * The controller layer maps the carried `$statusCode` to the matching HTTP
 * response: 409 for state conflicts (cannot add / remove tender on a settled
 * transaction; tender sum vs. total mismatch), 400 for validation errors
 * (missing reference, amount < 0.01).
 *
 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-002
 */
class InvalidTenderException extends Exception {
	/**
	 * Constructor.
	 *
	 * @param string $message The user-facing safe message.
	 * @param int $statusCode The HTTP status the controller should map to (400 or 409).
	 */
	public function __construct(
		string $message,
		private int $statusCode = 400,
	) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The HTTP status code the controller should map to.
	 *
	 * @return int The status code.
	 */
	public function getStatusCode(): int {
		return $this->statusCode;
	}//end getStatusCode()
}//end class
