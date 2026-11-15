<?php

/**
 * Contract of WebhookProcessorService
 *
 * The public surface of WebhookProcessorService that other components call, so they depend
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
 * Contract of WebhookProcessorService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WebhookProcessorServiceInterface {

	/**
	 * Normalise a SendGrid event into our internal shape.
	 *
	 * SendGrid uses snake_case for some fields and embeds the BlastDelivery
	 * id in `sg_message_id` (which we set as the `providerId` on the row
	 * at dispatch time — see {@see BlastService::sendOneDelivery()}).
	 *
	 * @param array<string, mixed> $event Raw SendGrid event.
	 *
	 * @return array<string, mixed> Normalised event.
	 *
	 * @spec openspec/changes/archive/2026-06-14-marketing-segmentation-and-blast-05-jobs-and-webhooks/tasks.md#webhooks
	 */
	public function normaliseSendGrid(array $event): array;

	/**
	 * Process one normalised provider event.
	 *
	 * Dispatches by `eventType` to the matching `process*` handler. Unknown
	 * event types are logged and skipped — providers add new event types
	 * regularly and the job must not crash on an unrecognised one.
	 *
	 * @param array<string, mixed> $event Normalised event payload.
	 * @param string $provider Provider key ("sendgrid", "ses",
	 *                         "twilio").
	 *
	 * @return bool True when the event was handled (including
	 *              intentionally-skipped unknowns).
	 *
	 * @spec openspec/changes/archive/2026-06-14-marketing-segmentation-and-blast-05-jobs-and-webhooks/tasks.md#webhooks
	 */
	public function processEvent(array $event, string $provider): bool;
}//end interface
