<?php

/**
 * Contract of NrcNotificationListener
 *
 * The public surface of NrcNotificationListener that other components call, so they depend
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

/**
 * Contract of NrcNotificationListener.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface NrcNotificationListenerInterface {

	/**
	 * Dispatch a single inbound notification.
	 *
	 * @param array<string, mixed> $abonnement Matching NrcAbonnement record.
	 * @param array<string, mixed> $notification Parsed inbound JSON payload.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-007
	 */
	public function dispatch(array $abonnement, array $notification): void;
}//end interface
