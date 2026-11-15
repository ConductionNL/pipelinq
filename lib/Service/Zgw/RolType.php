<?php

/**
 * Pipelinq RolType.
 *
 * A ZTC roltype URL and the free-text role description sent with it.
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
 * A roltype with its role description.
 *
 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-002
 */
final class RolType {
	/**
	 * Constructor.
	 *
	 * @param string $url         Roltype URL (from ZtcClient::resolveRoltype).
	 * @param string $toelichting Free-text role description.
	 */
	public function __construct(
		public readonly string $url,
		public readonly string $toelichting = 'Initiator',
	) {
	}//end __construct()
}//end class
