<?php

/**
 * Pipelinq NrcConnection.
 *
 * The resolved ZgwClient record and NRC component URL a registration talks to.
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
 * A resolved NRC client and URL.
 *
 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-007
 */
final class NrcConnection {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $client Resolved ZgwClient record.
	 * @param string               $url    NRC component URL.
	 */
	public function __construct(
		public readonly array $client,
		public readonly string $url,
	) {
	}//end __construct()
}//end class
