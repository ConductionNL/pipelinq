<?php

/**
 * Pipelinq MappedStatusCapture.
 *
 * Capture reply handling shared by the payment adapters that read the status back.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Payment\Support
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Payment\Support;

/**
 * The capture step of an AbstractPaymentAdapter subclass whose capture is a
 * status read. The host provides a mapStatus() step.
 *
 * @spec exclude shared plumbing of the payment adapters, no spec surface of its own.
 */
trait MappedStatusCapture {

	/**
	 * The capture envelope of a status reply: the error when the call failed,
	 * otherwise the mapped provider status (pending when absent).
	 *
	 * @param array<string, mixed>|null $response The PSP reply.
	 * @param string $sessionId The session id.
	 *
	 * @return array{sessionId: string, status: string, error?: string|null}
	 *
	 * @spec exclude shared plumbing of the payment adapters, no spec surface of its own.
	 */
	private function settledCapture(?array $response, string $sessionId): array {
		$error = $this->replyError(response: $response);
		if ($error !== null) {
			return $this->failedCapture(sessionId: $sessionId, message: $error);
		}

		$status = (string)($response['body']['status'] ?? 'pending');
		return $this->settled(sessionId: $sessionId, status: $this->mapStatus(providerStatus: $status));
	}//end settledCapture()
}//end trait
