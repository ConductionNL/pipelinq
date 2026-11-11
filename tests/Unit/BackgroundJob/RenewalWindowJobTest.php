<?php

/**
 * Unit tests for RenewalWindowJob.
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

use OCA\Pipelinq\BackgroundJob\RenewalWindowJob;
use OCA\Pipelinq\Service\ContractService;
use OCA\Pipelinq\Service\RenewalEngineService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins which engine call each contract gets, the failure log and the tally.
 */
final class RenewalWindowJobTest extends TestCase {
	/**
	 * Sweep the given contracts.
	 *
	 * @param array<int, array<string, mixed>> $contracts The contracts.
	 * @param array<string, mixed>             $actions   Contract id => engine answer, or a Throwable to throw.
	 *
	 * @return array<int, array<int, mixed>> Every engine call and log line, in order.
	 */
	private function sweep(array $contracts, array $actions = []): array {
		$events = [];
		$contractService = $this->createMock(ContractService::class);
		$contractService->method('loadAll')->willReturn($contracts);
		$answer = static function (array $contract) use ($actions): string {
			$action = ($actions[$contract['id'] ?? ''] ?? 'noop');
			if ($action instanceof \Throwable) {
				throw $action;
			}

			return $action;
		};
		$engine = $this->createMock(RenewalEngineService::class);
		$engine->method('reconcile')->willReturnCallback(
			static function (array $contract) use ($answer, &$events): string {
				$events[] = ['reconcile', $contract['id'] ?? ''];
				return $answer($contract);
			}
		);
		$engine->method('processContract')->willReturnCallback(
			static function (array $contract, string $today) use ($answer, &$events): string {
				$events[] = ['process', $contract['id'] ?? '', $today];
				return $answer($contract);
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				static function (string $message, array $context = []) use ($level, &$events): void {
					$events[] = [$level, $message, $context];
				}
			);
		}

		$job = new RenewalWindowJob($this->createMock(ITimeFactory::class), $contractService, $engine, $logger);
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
		return $events;
	}//end sweep()

	/**
	 * Resolved expiring contracts are reconciled, the rest processed for today; failures are logged.
	 *
	 * @return void
	 */
	public function testEveryContractGetsItsEngineCall(): void {
		$today = date('Y-m-d');
		$this->assertSame(
			[
				['reconcile', 'c-1'],
				['process', 'c-2', $today],
				['process', 'c-3', $today],
				['process', 'c-4', $today],
				['error', 'RenewalWindowJob: contract processing failed', ['contract' => 'c-4', 'error' => 'engine down']],
				['reconcile', 'c-5'],
				['process', '', $today],
				['info', 'RenewalWindowJob: processed contracts', ['transitions' => 2]],
			],
			$this->sweep(
				contracts: [
					['id' => 'c-1', 'status' => 'expiring', 'renewalLeadOutcome' => 'won'],
					['id' => 'c-2', 'status' => 'expiring', 'renewalLeadOutcome' => ''],
					['id' => 'c-3', 'status' => 'active', 'renewalLeadOutcome' => 'won'],
					['id' => 'c-4', 'status' => 'active'],
					['id' => 'c-5', 'status' => 'expiring', 'renewalLeadOutcome' => 'lost'],
					['status' => 'active'],
				],
				actions: ['c-1' => 'renewed', 'c-2' => 'noop', 'c-3' => 'flagged', 'c-4' => new RuntimeException('engine down'), 'c-5' => 'noop']
			)
		);
	}//end testEveryContractGetsItsEngineCall()

	/**
	 * No contracts, or no transitions, logs nothing.
	 *
	 * @return void
	 */
	public function testQuietSweeps(): void {
		$this->assertSame([], $this->sweep(contracts: []));
		$this->assertSame([['process', 'c-1', date('Y-m-d')]], $this->sweep(contracts: [['id' => 'c-1']]));
	}//end testQuietSweeps()
}//end class
