<?php

/**
 * Pipelinq ConnectorRead.
 *
 * One outbound read through a resolved OpenConnector source.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Egress
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-every-outbound-read-leaves-through-an-openconnector-source
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Egress;

/**
 * The source, endpoint, method and configuration of one read.
 *
 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-every-outbound-read-leaves-through-an-openconnector-source
 */
final class ConnectorRead {

	/**
	 * Constructor.
	 *
	 * @param object|array<string, mixed> $source   The resolved OpenConnector source.
	 * @param string                      $sourceId The source id, for the log.
	 * @param string                      $endpoint The endpoint, relative to the source location.
	 * @param array<string, mixed>        $config   Extra call configuration.
	 * @param string                      $method   The HTTP method.
	 *
	 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-every-outbound-read-leaves-through-an-openconnector-source
	 */
	public function __construct(
		public readonly object|array $source,
		public readonly string $sourceId,
		public readonly string $endpoint,
		public readonly array $config,
		public readonly string $method,
	) {
	}//end __construct()
}//end class
