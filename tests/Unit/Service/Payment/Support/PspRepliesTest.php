<?php

/**
 * Behavioural tests for the PspReplies and MappedStatusCapture traits.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Payment\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
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

namespace OCA\Pipelinq\Tests\Unit\Service\Payment\Support;

use OCA\Pipelinq\Service\Payment\Support\MappedStatusCapture;
use OCA\Pipelinq\Service\Payment\Support\PspReplies;
use PHPUnit\Framework\TestCase;

/**
 * Webhook secret guard, refund and capture envelopes.
 *
 * @covers \OCA\Pipelinq\Service\Payment\Support\PspReplies
 * @covers \OCA\Pipelinq\Service\Payment\Support\MappedStatusCapture
 */
class PspRepliesTest extends TestCase {

	/**
	 * @return void
	 */
	public function testWebhookSecret(): void {
		$host = new PspRepliesHost('s3cret');
		$this->assertSame('', $host->call('webhookSecret', '', 'sig'));
		$this->assertSame('', $host->call('webhookSecret', '{}', ''));
		$this->assertSame(0, $host->reads);
		$this->assertSame('s3cret', $host->call('webhookSecret', '{}', 'sig'));
		$this->assertSame(1, $host->reads);
		$this->assertSame('', (new PspRepliesHost(''))->call('webhookSecret', '{}', 'sig'));
	}//end testWebhookSecret()

	/**
	 * @return void
	 */
	public function testRefundedById(): void {
		$host = new PspRepliesHost('');
		$this->assertSame(['failedRefund', 's1', 'no reply'], $host->call('refundedById', null, 's1'));
		$this->assertSame(['refunded', 's1', 're_1'], $host->call('refundedById', ['body' => ['id' => 're_1']], 's1'));
		$this->assertSame(['refunded', 's1', ''], $host->call('refundedById', ['body' => []], 's1'));
	}//end testRefundedById()

	/**
	 * @return void
	 */
	public function testSettledCapture(): void {
		$host = new PspRepliesHost('');
		$this->assertSame(['failedCapture', 's1', 'no reply'], $host->call('settledCapture', null, 's1'));
		$this->assertSame(['settled', 's1', 'mapped:paid'], $host->call('settledCapture', ['body' => ['status' => 'paid']], 's1'));
		$this->assertSame(['settled', 's1', 'mapped:pending'], $host->call('settledCapture', ['body' => []], 's1'));
	}//end testSettledCapture()
}//end class

/**
 * A host with the adapter steps the traits call.
 */
final class PspRepliesHost {
	use PspReplies;
	use MappedStatusCapture;

	public int $reads = 0;

	/**
	 * @param string $secret The configured webhook secret.
	 */
	public function __construct(
		private string $secret,
	) {
	}

	/**
	 * @param string $key The credential key.
	 * @return string
	 */
	private function credential(string $key): string {
		$this->reads++;
		return ($key === 'webhookSecret') ? $this->secret : '';
	}

	/**
	 * @param array<string, mixed>|null $response The reply.
	 * @return string|null
	 */
	private function replyError(?array $response): ?string {
		return ($response === null) ? 'no reply' : null;
	}

	/**
	 * @param string $sessionId The session.
	 * @param string $message   The message.
	 * @return array<int, string>
	 */
	private function failedCapture(string $sessionId, string $message): array {
		return ['failedCapture', $sessionId, $message];
	}

	/**
	 * @param string $sessionId The session.
	 * @param string $message   The message.
	 * @return array<int, string>
	 */
	private function failedRefund(string $sessionId, string $message): array {
		return ['failedRefund', $sessionId, $message];
	}

	/**
	 * @param string $sessionId The session.
	 * @param string $status    The status.
	 * @return array<int, string>
	 */
	private function settled(string $sessionId, string $status): array {
		return ['settled', $sessionId, $status];
	}

	/**
	 * @param string $sessionId The session.
	 * @param string $refundId  The refund id.
	 * @return array<int, string>
	 */
	private function refunded(string $sessionId, string $refundId): array {
		return ['refunded', $sessionId, $refundId];
	}

	/**
	 * @param string $providerStatus The provider status.
	 * @return string
	 */
	private function mapStatus(string $providerStatus): string {
		return 'mapped:' . $providerStatus;
	}

	/**
	 * @param string $method  The private method.
	 * @param mixed  ...$args The arguments.
	 * @return mixed
	 */
	public function call(string $method, mixed ...$args): mixed {
		return $this->$method(...$args);
	}
}//end class
