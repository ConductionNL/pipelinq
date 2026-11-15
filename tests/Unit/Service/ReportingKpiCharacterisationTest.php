<?php

/**
 * Characterisation test for ReportingService's KPI and agent reads.
 *
 * Pins getKpis() and getAgentPerformance() over a realistic ticket set:
 * the date window, the row shapes a ticket can arrive in, the per-channel
 * SLA thresholds, the handling-time average, the agent order, and the
 * zero-value answer when the read throws.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use JsonSerializable;
use OCA\Pipelinq\Service\ReportingService;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * KPI and agent answers.
 */
class ReportingKpiCharacterisationTest extends TestCase {
	/**
	 * The tickets the resolver answers.
	 *
	 * @return array<int, mixed>
	 */
	private static function tickets(): array {
		$json = new class implements JsonSerializable {
			/**
			 * A ticket entity.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['occurredAt' => '2026-09-03T10:00:00Z', 'assignee' => 'bob', 'channel' => 'email', 'outcome' => 'afgehandeld', 'duration' => '0:10:00', 'channelMetadata' => ['responseTimeHours' => 2]];
			}
		};
		$scalar = new class implements JsonSerializable {
			/**
			 * Not an object payload.
			 *
			 * @return string
			 */
			public function jsonSerialize(): string {
				return 'x';
			}
		};
		return [
			['occurredAt' => '2026-09-01T08:00:00Z', 'assignee' => 'anna', 'channel' => 'telefoon', 'outcome' => 'afgehandeld', 'duration' => '0:05:00', 'channelMetadata' => ['waitTime' => 20]],
			['occurredAt' => '2026-09-01T09:00:00Z', 'assignee' => 'anna', 'channel' => 'telefoon', 'outcome' => 'doorverbonden', 'duration' => '', 'channelMetadata' => ['waitTime' => 45]],
			['occurredAt' => '2026-09-02T09:00:00Z', 'channel' => 'telefoon', 'duration' => '0:03:30'],
			['occurredAt' => '2026-09-02T12:00:00Z', 'assignee' => 'bob', 'channel' => 'email', 'outcome' => 'afgehandeld', 'channelMetadata' => ['responseTimeHours' => 9]],
			['occurredAt' => '2026-09-02T13:00:00Z', 'assignee' => 'bob', 'channel' => 'post', 'outcome' => 'afgehandeld', 'duration' => '0:01:00'],
			['occurredAt' => '2026-09-02T14:00:00Z', 'assignee' => 'cas', 'outcome' => 'afgehandeld'],
			['occurredAt' => '2026-08-31T23:59:59Z', 'assignee' => 'anna', 'channel' => 'telefoon'],
			['occurredAt' => '2026-09-04T00:00:00Z', 'assignee' => 'anna', 'channel' => 'telefoon'],
			['assignee' => 'anna', 'channel' => 'telefoon'],
			'not a row',
			$json,
			$scalar,
		];
	}//end tickets()

	/**
	 * The KPIs and the agent table over the window.
	 *
	 * @return void
	 */
	public function testKpisAndAgentsOverTheWindow(): void {
		$service = $this->service(tickets: self::tickets(), errors: $errors);

		self::assertSame(
			['total' => 7, 'fcrRate' => (new ReportingService($this->createMock(IAppConfig::class), $this->createMock(LoggerInterface::class), $this->createMock(TicketService::class)))->calculateFcr(contactmomenten: $this->moments()), 'avgHandlingTime' => (new ReportingService($this->createMock(IAppConfig::class), $this->createMock(LoggerInterface::class), $this->createMock(TicketService::class)))->calculateAverageHandlingTime(durations: ['0:05:00', '0:03:30', '0:01:00', '0:10:00']), 'slaCompliance' => round((4 / 7) * 100, 1)],
			$service->getKpis(from: '2026-09-01', to: '2026-09-03')
		);
		$agents = $service->getAgentPerformance(from: '2026-09-01', to: '2026-09-03');
		self::assertSame(['bob', 'anna', 'unknown', 'cas'], array_keys($agents));
		self::assertSame([3, 2, 1, 1], array_column($agents, 'count'));
		self::assertSame('0:00', $agents['cas']['avgHandlingTime']);
		self::assertSame($service->calculateAverageHandlingTime(durations: ['0:05:00']), $agents['anna']['avgHandlingTime']);
		self::assertSame($service->calculateFcr(contactmomenten: [['outcome' => 'afgehandeld'], ['outcome' => 'doorverbonden']]), $agents['anna']['fcrRate']);
		self::assertSame([], $errors);

		self::assertSame(['total' => 0, 'fcrRate' => 0.0, 'avgHandlingTime' => '0:00', 'slaCompliance' => 0.0], $service->getKpis(from: '2027-01-01', to: '2027-01-02'));
		self::assertSame(9, $service->getKpis(from: '', to: '')['total']);
	}//end testKpisAndAgentsOverTheWindow()

	/**
	 * A failing read answers zero values and logs.
	 *
	 * @return void
	 */
	public function testAFailingReadAnswersZeroValues(): void {
		$service = $this->service(tickets: null, errors: $errors);

		self::assertSame(['total' => 0, 'fcrRate' => 0.0, 'avgHandlingTime' => '0:00', 'slaCompliance' => 0.0], $service->getKpis(from: '', to: ''));
		self::assertSame([], $service->getAgentPerformance(from: '', to: ''));
		self::assertSame(['ReportingService::getKpis failed', 'ReportingService::getAgentPerformance failed'], array_column($errors, 0));
	}//end testAFailingReadAnswersZeroValues()

	/**
	 * The moments inside the window, as the calculators get them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function moments(): array {
		return [
			['outcome' => 'afgehandeld'], ['outcome' => 'doorverbonden'], ['outcome' => ''], ['outcome' => 'afgehandeld'],
			['outcome' => 'afgehandeld'], ['outcome' => 'afgehandeld'], ['outcome' => 'afgehandeld'],
		];
	}//end moments()

	/**
	 * Build the service; null tickets make the resolver throw.
	 *
	 * @param array<int, mixed>|null $tickets The tickets.
	 * @param mixed                  $errors  Receives the error log lines.
	 *
	 * @return ReportingService
	 */
	private function service(?array $tickets, mixed &$errors): ReportingService {
		$errors = [];
		$resolver = $this->createMock(TicketService::class);
		if ($tickets === null) {
			$resolver->method('findByType')->willThrowException(new RuntimeException('down'));
		} else {
			$resolver->method('findByType')->willReturn($tickets);
		}

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			static function (string $message, array $context = []) use (&$errors): void {
				$errors[] = [$message, $context];
			}
		);

		return new ReportingService($this->createMock(IAppConfig::class), $logger, $resolver);
	}//end service()
}//end class
