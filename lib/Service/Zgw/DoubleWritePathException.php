<?php

/**
 * Pipelinq DoubleWritePathException.
 *
 * Raised by `ZgwCoexistenceValidator::validateWritePath()` when both a
 * `ZgwEndpoint` (actief=true) and a `StufEndpoint` (write="on") exist for
 * the same gemeente at the moment of a Request creation. Per REQ-ZGW-008
 * exactly one write path MUST be active per gemeente; the exception lists
 * both conflicting endpoints so the beheerder can disable one without
 * having to walk the admin UI.
 *
 * @category Exception
 * @package  OCA\Pipelinq\Service\Zgw
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
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-008
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Zgw;

/**
 * Both StUF and ZGW write paths are active for the same gemeente.
 */
class DoubleWritePathException extends ZgwException {
	/**
	 * Constructor.
	 *
	 * @param string $message Human-readable error message.
	 * @param array<int, string> $conflictEndpointIds List of ZgwEndpoint + StufEndpoint ids involved.
	 */
	public function __construct(
		string $message,
		public readonly array $conflictEndpointIds = [],
	) {
		parent::__construct(message: $message);
	}//end __construct()
}//end class
