<?php

/**
 * Pipelinq InitiatorLink.
 *
 * One request to link a pipelinq Contact to a zaak as a rol: the zaak
 * mapping and the rol to assign on it.
 *
 * @category Service
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
 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Zgw;

/**
 * A request to link a contact to a zaak as a rol.
 *
 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-002
 */
final class InitiatorLink {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $caseMap ZgwResourceMapping for the parent zaak.
	 * @param RolAssignment        $rol     The contact and the roltype to assign.
	 */
	public function __construct(
		public readonly array $caseMap,
		public readonly RolAssignment $rol,
	) {
	}//end __construct()

	/**
	 * The URL of the parent zaak, or an empty string when the mapping has none.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-002
	 */
	public function caseUrl(): string {
		return (string)($this->caseMap['zgwUrl'] ?? '');
	}//end caseUrl()
}//end class
