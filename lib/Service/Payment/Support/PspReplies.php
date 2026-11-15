<?php

/**
 * Pipelinq PspReplies.
 *
 * Webhook secret read and refund reply handling shared by the payment adapters.
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
 * Steps of an AbstractPaymentAdapter subclass: the webhook secret guard and
 * turning a PSP refund reply into a refund envelope.
 *
 * @spec exclude shared plumbing of the payment adapters, no spec surface of its own.
 */
trait PspReplies {

	/**
	 * The configured webhook secret, or '' when the payload or signature is empty
	 * (the secret is then not read) or no secret is configured.
	 *
	 * @param string $rawPayload The raw body.
	 * @param string $signature The provided signature.
	 *
	 * @return string The webhook secret or ''.
	 *
	 * @spec exclude shared plumbing of the payment adapters, no spec surface of its own.
	 */
	private function webhookSecret(string $rawPayload, string $signature): string {
		if ($rawPayload === '' || $signature === '') {
			return '';
		}

		return $this->credential(key: 'webhookSecret');
	}//end webhookSecret()

	/**
	 * The refund envelope of a refund reply: the error when the call failed,
	 * otherwise the refund id from the reply body's `id`.
	 *
	 * @param array<string, mixed>|null $response The PSP reply.
	 * @param string $sessionId The session id.
	 *
	 * @return array{sessionId: string, refundId: string, status: string, error?: string|null}
	 *
	 * @spec exclude shared plumbing of the payment adapters, no spec surface of its own.
	 */
	private function refundedById(?array $response, string $sessionId): array {
		$error = $this->replyError(response: $response);
		if ($error !== null) {
			return $this->failedRefund(sessionId: $sessionId, message: $error);
		}

		return $this->refunded(sessionId: $sessionId, refundId: (string)($response['body']['id'] ?? ''));
	}//end refundedById()
}//end trait
