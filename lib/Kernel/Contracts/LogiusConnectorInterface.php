<?php

/**
 * Contract of LogiusConnector
 *
 * The public surface of LogiusConnector that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

use OCP\IRequest;

/**
 * Contract of LogiusConnector.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LogiusConnectorInterface {

	/**
	 * Verify a Logius webhook signature per BBK 1.7 (HMAC-SHA256 of the
	 * raw body with the tenant-configured webhook secret, transported in
	 * the X-Logius-Signature header).
	 *
	 * @param IRequest $request The incoming Nextcloud request.
	 * @param string $rawBody The raw request body (because IRequest
	 *                        may consume the stream when parsing).
	 *
	 * @return bool True iff the signature is valid.
	 * @spec openspec/specs/berichtenbox/spec.md#REQ-BBK-010
	 */
	public function handleWebhookSignature(IRequest $request, string $rawBody): bool;
}//end interface
