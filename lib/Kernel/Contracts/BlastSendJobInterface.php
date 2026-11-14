<?php

/**
 * Contract of BlastSendJob
 *
 * The public surface of BlastSendJob that other components call, so they depend
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
 * Contract of BlastSendJob.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BlastSendJobInterface {

	/**
	 * Append one webhook event envelope to the queue (called from the
	 * BlastWebhookController fast path).
	 *
	 * @param string $provider Provider key.
	 * @param array<string, mixed> $event Normalised event payload.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-06-14-marketing-segmentation-and-blast-05-jobs-and-webhooks/tasks.md#webhooks
	 */
	public function enqueueWebhookEvent(string $provider, array $event): void;
}//end interface
