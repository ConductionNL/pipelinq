<?php

/**
 * Pipelinq LogiusPost.
 *
 * The endpoint and body of one outbound Berichtenbox message.
 *
 * @category Service
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
 * @spec openspec/specs/berichtenbox/spec.md#REQ-BBK-010
 * @spec openspec/specs/berichtenbox/spec.md#REQ-MAILBOX-002
 * @spec openspec/specs/berichtenbox/spec.md#REQ-RECEIPT-005
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * Where one outbound Berichtenbox message goes and the body it carries.
 *
 * @spec exclude infrastructure value object of LogiusConnector; the behaviour is
 *   specified on the connector's callers
 */
final class LogiusPost {
	/**
	 * Constructor.
	 *
	 * @param string               $endpoint The messages endpoint.
	 * @param array<string, mixed> $body     The BBK 1.7 message body.
	 */
	public function __construct(
		public readonly string $endpoint,
		public readonly array $body,
	) {
	}//end __construct()
}//end class
