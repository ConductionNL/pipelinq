<?php

/**
 * Pipelinq LabelRenameException.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Labels
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/shared-label-list/specs/shared-labels/spec.md#requirement-an-administrator-manages-the-labels-on-beheer-marketing
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Labels;

use RuntimeException;

/**
 * A refused rename, with the HTTP status that answers it.
 *
 * @spec openspec/changes/shared-label-list/specs/shared-labels/spec.md#requirement-an-administrator-manages-the-labels-on-beheer-marketing
 */
class LabelRenameException extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param string  $message What was refused, for the administrator.
	 * @param integer $status  The HTTP status: 400, 403, 404 or 409.
	 *
	 * @spec openspec/changes/shared-label-list/specs/shared-labels/spec.md#requirement-an-administrator-manages-the-labels-on-beheer-marketing
	 */
	public function __construct(string $message, private int $status) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The HTTP status that answers the refusal.
	 *
	 * @return integer
	 *
	 * @spec openspec/changes/shared-label-list/specs/shared-labels/spec.md#requirement-an-administrator-manages-the-labels-on-beheer-marketing
	 */
	public function getStatus(): int {
		return $this->status;
	}//end getStatus()
}//end class
