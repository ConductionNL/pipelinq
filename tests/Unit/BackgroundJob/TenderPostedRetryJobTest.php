<?php

/**
 * Unit tests for TenderPostedRetryJob.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\Pipelinq\BackgroundJob\TenderPostedRetryJob;
use OCA\Pipelinq\Service\PosTenderService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins every re-emit, skip and log line of a retry run.
 */
final class TenderPostedRetryJobTest extends TestCase {
	/**
	 * Run the job over the given unposted tenders.
	 *
	 * @param array<int, array<string, mixed>>|null $unposted The unposted tenders, or null to make listing fail.
	 * @param array<string, mixed>                  $emits    Transaction uuid => event id, or a Throwable to throw.
	 *
	 * @return array<int, array<int, mixed>> Every emit and log line, in order.
	 */
	private function retry(?array $unposted, array $emits = []): array {
		$events = [];
		$service = $this->createMock(PosTenderService::class);
		if ($unposted === null) {
			$service->method('listUnpostedTenders')->willThrowException(new RuntimeException('register offline'));
		} else {
			$service->method('listUnpostedTenders')->willReturn($unposted);
		}

		$service->method('emitSingleTenderPosted')->willReturnCallback(
			static function (string $transactionUuid, string $transactionReference, array $tender) use ($emits, &$events): string {
				$events[] = ['emit', $transactionUuid, $transactionReference, $tender];
				$outcome = ($emits[$transactionUuid] ?? 'evt');
				if ($outcome instanceof \Throwable) {
					throw $outcome;
				}

				return $outcome;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				static function (string $message, array $context = []) use ($level, &$events): void {
					$events[] = [$level, $message, $context];
				}
			);
		}

		$job = new TenderPostedRetryJob($this->createMock(ITimeFactory::class), $service, $logger);
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
		return $events;
	}//end retry()

	/**
	 * Every tender with a transaction is re-emitted; only emits with an event id count.
	 *
	 * @return void
	 */
	public function testReEmitsEveryTenderWithATransaction(): void {
		$t1 = ['transaction' => 'tx-1', 'amount' => 5];
		$t2 = ['amount' => 3];
		$t3 = ['transaction' => 'tx-3'];
		$t4 = ['transaction' => 'tx-4'];
		$t5 = ['transaction' => 'tx-5'];
		$this->assertSame(
			[
				['emit', 'tx-1', '', $t1],
				['emit', 'tx-3', '', $t3],
				['emit', 'tx-4', '', $t4],
				['info', 'Pipelinq TenderPostedRetryJob: per-tender re-emit failed (soft)', ['exception' => 'bus down']],
				['emit', 'tx-5', '', $t5],
				['info', 'Pipelinq TenderPostedRetryJob: re-emitted 2 tender CloudEvent(s)', []],
			],
			$this->retry(unposted: [$t1, $t2, $t3, $t4, $t5], emits: ['tx-3' => '', 'tx-4' => new RuntimeException('bus down')])
		);
	}//end testReEmitsEveryTenderWithATransaction()

	/**
	 * Nothing re-emitted logs no summary; no tenders or a failing list emit nothing.
	 *
	 * @return void
	 */
	public function testQuietRuns(): void {
		$this->assertSame([['emit', 'tx-1', '', ['transaction' => 'tx-1']]], $this->retry(unposted: [['transaction' => 'tx-1']], emits: ['tx-1' => '']));
		$this->assertSame([], $this->retry(unposted: []));
		$this->assertSame(
			[['warning', 'Pipelinq TenderPostedRetryJob: failed to list unposted tenders', ['exception' => 'register offline']]],
			$this->retry(unposted: null)
		);
	}//end testQuietRuns()
}//end class
