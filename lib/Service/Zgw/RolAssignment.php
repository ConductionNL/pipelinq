<?php

/**
 * Pipelinq RolAssignment.
 *
 * A pipelinq Contact and the roltype it takes on a zaak.
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
 * A contact in a ZGW role.
 *
 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-002
 */
final class RolAssignment {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $contact Pipelinq Contact payload.
	 * @param RolType              $roltype The roltype and its description.
	 */
	public function __construct(
		public readonly array $contact,
		public readonly RolType $roltype,
	) {
	}//end __construct()

	/**
	 * The contact's ZGW betrokkeneType and identification map.
	 *
	 * @return array{0:string,1:array<string,mixed>} [betrokkeneType, ident]
	 *
	 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-002
	 */
	public function identification(): array {
		return ZrcClient::contactIdentification(contact: $this->contact);
	}//end identification()
}//end class
