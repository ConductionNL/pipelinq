<?php

/**
 * Pipelinq AbonnementRequest.
 *
 * What an NRC abonnement subscribes to: the kanalen and the callback URL
 * NRC posts its notifications to.
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
 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-007
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Zgw;

/**
 * A request to subscribe to NRC kanalen.
 *
 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-007
 */
final class AbonnementRequest {
	/**
	 * Constructor.
	 *
	 * @param array<int, array<string,mixed>> $kanalen     List of `{name, filters}` kanaal entries.
	 * @param string                          $callbackUrl Pipelinq inbox URL.
	 */
	public function __construct(
		public readonly array $kanalen,
		public readonly string $callbackUrl,
	) {
	}//end __construct()
}//end class
