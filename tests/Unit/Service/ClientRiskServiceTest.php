<?php

/**
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\ClientRiskService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every client carries a risk level with its reasons (REQ-CIN-001).
 *
 * @covers \OCA\Pipelinq\Service\ClientRiskService
 *
 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-1.2
 */
class ClientRiskServiceTest extends TestCase {

	/**
	 * The moment every test judges at.
	 */
	private const NOW = '2026-11-13T12:00:00+00:00';

	/**
	 * Stored rows per schema id.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * The saves made, as [object, uuid, rbac].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $saved = [];

	/**
	 * The reads made, as [config, rbac, multitenancy].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $reads = [];

	/**
	 * The service over the stored rows.
	 *
	 * @param array<string, string> $config App config values.
	 * @param bool                  $fails  Whether every read and save throws.
	 *
	 * @return ClientRiskService
	 */
	private function service(array $config = [], bool $fails = false): ClientRiskService {
		$config += ['register' => 'r', 'ticket_schema' => 'ticket', 'contract_schema' => 'contract', 'surveyResponse_schema' => 'response', 'client_schema' => 'client'];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true) use ($fails): array {
				$this->reads[] = [$config, $_rbac, $_multitenancy];
				if ($fails === true) {
					throw new RuntimeException('down');
				}

				$filters = $config['filters'];
				$rows    = array_values(array_filter(
					($this->rows[$filters['schema']] ?? []),
					static function (array $row) use ($filters): bool {
						foreach (array_diff_key($filters, ['register' => 1, 'schema' => 1]) as $key => $value) {
							if (($row[$key] ?? null) !== $value) {
								return false;
							}
						}

						return true;
					}
				));

				return array_slice($rows, ($config['offset'] ?? 0), $config['limit']);
			}
		);
		$objects->method('find')->willReturnCallback(
			function (int|string $id) use ($fails): ?ObjectEntityInterface {
				if ($fails === true) {
					throw new RuntimeException('down');
				}

				foreach (($this->rows['client'] ?? []) as $client) {
					if ($client['id'] === $id) {
						$entity = $this->createMock(ObjectEntityInterface::class);
						$entity->method('jsonSerialize')->willReturn($client);
						return $entity;
					}
				}

				return null;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (...$args) use ($fails): ObjectEntityInterface {
				if ($fails === true) {
					throw new RuntimeException('down');
				}

				// Positional: object, extend, register, schema, uuid, _rbac, _multitenancy.
				$this->saved[] = [$args[0], $args[4], $args[5]];
				return $this->createMock(ObjectEntityInterface::class);
			}
		);

		return new ClientRiskService($appConfig, $objects, $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * A moment relative to NOW.
	 *
	 * @param string $shift E.g. "-10 days".
	 *
	 * @return string
	 */
	private function at(string $shift): string {
		return (new DateTimeImmutable(self::NOW))->modify($shift)->format(DATE_ATOM);
	}//end at()

	/**
	 * Assess client c-1 at NOW.
	 *
	 * @param ClientRiskService|null $service The service, or a fresh one.
	 *
	 * @return array<string, mixed>
	 */
	private function assess(?ClientRiskService $service = null): array {
		return ($service ?? $this->service())->assess('c-1', ['id' => 'c-1'], new DateTimeImmutable(self::NOW));
	}//end assess()

	/**
	 * A recent contact so the quiet signal stays out of the way.
	 *
	 * @return array<string, mixed>
	 */
	private function recentContact(): array {
		return ['id' => 'cm-1', 'client' => 'c-1', 'ticketType' => 'contactmoment', 'status' => 'closed', 'occurredAt' => $this->at('-7 days')];
	}//end recentContact()

	/**
	 * A quiet, happy client is low, with no reasons.
	 *
	 * @return void
	 */
	public function testAQuietHappyClientIsLow(): void {
		$this->rows = ['ticket' => [$this->recentContact()], 'contract' => [['id' => 'k-1', 'clientRef' => 'c-1', 'status' => 'active']]];

		$risk = $this->assess();

		$this->assertSame(['level' => 'low', 'score' => 0, 'reasons' => [], 'computedAt' => '2026-11-13T12:00:00+00:00'], $risk);
		foreach ($this->reads as [$config, $rbac, $multitenancy]) {
			$this->assertFalse($rbac);
			$this->assertFalse($multitenancy);
		}
	}//end testAQuietHappyClientIsLow()

	/**
	 * An expiring contract adds 30 and a contract churned in the last 90 days adds 40, naming them.
	 *
	 * @return void
	 */
	public function testContractSignals(): void {
		$this->rows = [
			'ticket' => [$this->recentContact()],
			'contract' => [
				['id' => 'k-1', 'clientRef' => 'c-1', 'status' => 'expiring'],
				['id' => 'k-2', 'clientRef' => 'c-1', 'status' => 'churned', 'endDate' => $this->at('-1 day')],
				['id' => 'k-3', 'clientRef' => 'c-1', 'status' => 'churned', 'endDate' => $this->at('-120 days')],
				['id' => 'k-4', 'clientRef' => 'c-2', 'status' => 'expiring'],
			],
		];

		$risk = $this->assess();

		$this->assertSame(
			[
				['signal' => 'contract-expiring', 'points' => 30, 'schema' => 'contract', 'records' => ['k-1']],
				['signal' => 'contract-churned', 'points' => 40, 'schema' => 'contract', 'records' => ['k-2']],
			],
			$risk['reasons']
		);
		$this->assertSame(70, $risk['score']);
		$this->assertSame('high', $risk['level']);
	}//end testContractSignals()

	/**
	 * Detractor answers of the last 180 days count 25 each, capped at 50.
	 *
	 * @return void
	 */
	public function testDetractorsAreCountedPerAnswerAndCapped(): void {
		$this->rows = [
			'ticket' => [$this->recentContact()],
			'response' => [
				['id' => 's-1', 'clientRef' => 'c-1', 'classification' => 'detractor', 'submittedAt' => $this->at('-10 days')],
				['id' => 's-2', 'clientRef' => 'c-1', 'classification' => 'detractor', 'submittedAt' => $this->at('-20 days')],
				['id' => 's-3', 'clientRef' => 'c-1', 'classification' => 'detractor', 'submittedAt' => $this->at('-30 days')],
				['id' => 's-4', 'clientRef' => 'c-1', 'classification' => 'detractor', 'submittedAt' => $this->at('-200 days')],
			],
		];

		$this->assertSame(
			[['signal' => 'detractor', 'points' => 50, 'schema' => 'surveyResponse', 'records' => ['s-1', 's-2', 's-3']]],
			$this->assess()['reasons']
		);

		$this->rows['response'] = [$this->rows['response'][0]];
		$this->assertSame(25, $this->assess()['score']);
	}//end testDetractorsAreCountedPerAnswerAndCapped()

	/**
	 * Open complaints count 10 each, capped at 30; a closed one and other open tickets do not.
	 *
	 * @return void
	 */
	public function testOpenComplaintsAreCountedAndCapped(): void {
		$complaint = static fn (string $id, string $status): array => ['id' => $id, 'client' => 'c-1', 'ticketType' => 'complaint', 'status' => $status, 'occurredAt' => '2026-11-10T09:00:00+00:00'];
		$this->rows = ['ticket' => [
			$complaint('t-1', 'new'), $complaint('t-2', 'in_progress'), $complaint('t-3', 'new'), $complaint('t-4', 'new'),
			$complaint('t-5', 'resolved'),
			['id' => 't-6', 'client' => 'c-1', 'ticketType' => 'request', 'status' => 'new', 'occurredAt' => '2026-11-10T09:00:00+00:00'],
		]];

		$this->assertSame(
			[['signal' => 'open-complaints', 'points' => 30, 'schema' => 'ticket', 'records' => ['t-1', 't-2', 't-3', 't-4']]],
			$this->assess()['reasons']
		);
	}//end testOpenComplaintsAreCountedAndCapped()

	/**
	 * An open ticket past its SLA deadline adds 15 once; a closed one or one still in time does not.
	 *
	 * @return void
	 */
	public function testABreachedSlaCountsOnce(): void {
		$ticket = fn (string $id, string $status, string $deadline): array => ['id' => $id, 'client' => 'c-1', 'ticketType' => 'request', 'status' => $status, 'slaDeadline' => $this->at($deadline), 'occurredAt' => $this->at('-2 days')];
		$this->rows = ['ticket' => [$ticket('t-1', 'new', '-1 hour'), $ticket('t-2', 'in_progress', '-3 days'), $ticket('t-3', 'resolved', '-3 days'), $ticket('t-4', 'new', '+1 day')]];

		$this->assertSame(
			[['signal' => 'sla-breached', 'points' => 15, 'schema' => 'ticket', 'records' => ['t-1', 't-2']]],
			$this->assess()['reasons']
		);
	}//end testABreachedSlaCountsOnce()

	/**
	 * No contact for more than 90 days adds 20 and names the last contact; a client with no dates is not judged.
	 *
	 * @return void
	 */
	public function testSilenceAfterNinetyDays(): void {
		$this->rows = ['ticket' => [
			['id' => 'cm-1', 'client' => 'c-1', 'ticketType' => 'contactmoment', 'status' => 'closed', 'occurredAt' => $this->at('-200 days')],
			['id' => 'cm-2', 'client' => 'c-1', 'ticketType' => 'contactmoment', 'status' => 'closed', '@self' => ['created' => $this->at('-91 days')]],
		]];

		$this->assertSame([['signal' => 'no-recent-contact', 'points' => 20, 'schema' => 'ticket', 'records' => ['cm-2']]], $this->assess()['reasons']);

		$this->rows['ticket'][1]['@self']['created'] = $this->at('-89 days');
		$this->assertSame([], $this->assess()['reasons']);

		$this->rows = [];
		$this->assertSame([], $this->assess()['reasons']);
		$quietSince = $this->service()->assess('c-1', ['id' => 'c-1', '@self' => ['created' => $this->at('-100 days')]], new DateTimeImmutable(self::NOW));
		$this->assertSame([['signal' => 'no-recent-contact', 'points' => 20, 'schema' => 'ticket', 'records' => []]], $quietSince['reasons']);
	}//end testSilenceAfterNinetyDays()

	/**
	 * The level boundaries: 29 low, 30 medium, 59 medium, 60 high.
	 *
	 * @return void
	 */
	public function testLevelBoundaries(): void {
		$service = $this->service();

		$this->assertSame(['low', 'medium', 'medium', 'high'], [$service->level(29), $service->level(30), $service->level(59), $service->level(60)]);
	}//end testLevelBoundaries()

	/**
	 * A weight set in app config replaces the default; a non-number is ignored.
	 *
	 * @return void
	 */
	public function testWeightsComeFromAppConfig(): void {
		$this->rows = ['ticket' => [$this->recentContact()], 'contract' => [['id' => 'k-1', 'clientRef' => 'c-1', 'status' => 'expiring']]];

		$this->assertSame(45, $this->assess($this->service(['client_risk_weight_contract-expiring' => '45']))['score']);
		$this->assertSame(30, $this->assess($this->service(['client_risk_weight_contract-expiring' => 'lots']))['score']);
	}//end testWeightsComeFromAppConfig()

	/**
	 * A signal that cannot be read adds nothing rather than failing the assessment.
	 *
	 * @return void
	 */
	public function testAnUnreadableSignalAddsNothing(): void {
		$this->assertSame('low', $this->assess($this->service(fails: true))['level']);
		$this->assertSame([], $this->assess($this->service(['register' => '']))['reasons']);
	}//end testAnUnreadableSignalAddsNothing()

	/**
	 * A risk is stored only when its level or reasons changed, never for a new computedAt alone.
	 *
	 * @return void
	 */
	public function testStoreWritesOnlyOnChange(): void {
		$risk    = ['level' => 'medium', 'score' => 30, 'reasons' => [['signal' => 'contract-expiring', 'points' => 30, 'schema' => 'contract', 'records' => ['k-1']]], 'computedAt' => self::NOW];
		$stored  = array_merge($risk, ['computedAt' => '2026-11-01T00:00:00+00:00']);
		$service = $this->service();

		$this->assertFalse($service->store(['id' => 'c-1', 'churnRisk' => $stored], $risk, 'r', 'client'));
		$this->assertSame([], $this->saved);

		$this->assertTrue($service->store(['id' => 'c-1', 'name' => 'Bakkerij Jansen', '@self' => ['id' => 'c-1']], $risk, 'r', 'client'));
		$this->assertSame([[['id' => 'c-1', 'name' => 'Bakkerij Jansen', 'churnRisk' => $risk], 'c-1', false]], $this->saved);

		$this->assertFalse($service->store(['name' => 'no id'], $risk, 'r', 'client'));
		$this->assertFalse($this->service(fails: true)->store(['id' => 'c-1'], $risk, 'r', 'client'));
	}//end testStoreWritesOnlyOnChange()

	/**
	 * recompute() reads the client as the system and stores its risk once.
	 *
	 * @return void
	 */
	public function testRecomputeStoresOnceThenLeavesItAlone(): void {
		$this->rows = ['client' => [['id' => 'c-1', 'name' => 'Bakkerij Jansen']], 'ticket' => [$this->recentContact()], 'contract' => [['id' => 'k-1', 'clientRef' => 'c-1', 'status' => 'expiring']]];

		$this->assertTrue($this->service()->recompute('c-1', new DateTimeImmutable(self::NOW)));
		$this->assertSame('medium', $this->saved[0][0]['churnRisk']['level']);

		$this->rows['client'][0]['churnRisk'] = $this->saved[0][0]['churnRisk'];
		$this->assertFalse($this->service()->recompute('c-1', new DateTimeImmutable('2026-11-14T12:00:00+00:00')));
		$this->assertCount(1, $this->saved);

		$this->assertFalse($this->service()->recompute(''));
		$this->assertFalse($this->service(['client_schema' => ''])->recompute('c-1'));
		$this->assertFalse($this->service(fails: true)->recompute('c-1'));
	}//end testRecomputeStoresOnceThenLeavesItAlone()

	/**
	 * recomputeAll() walks the clients in batches and counts what it wrote.
	 *
	 * @return void
	 */
	public function testRecomputeAllWalksEveryClient(): void {
		for ($i = 0; $i < (ClientRiskService::BATCH_SIZE + 2); $i++) {
			$this->rows['client'][] = ['id' => 'c-'.$i];
		}

		$this->rows['contract'] = [['id' => 'k-1', 'clientRef' => 'c-1', 'status' => 'expiring']];
		$this->rows['client'][1]['churnRisk'] = ['level' => 'low', 'reasons' => []];

		$tally = $this->service()->recomputeAll(new DateTimeImmutable(self::NOW));

		$this->assertSame(['written' => (ClientRiskService::BATCH_SIZE + 2), 'unchanged' => 0], $tally);
		$this->assertSame(['written' => 0, 'unchanged' => 0], $this->service(['client_schema' => ''])->recomputeAll());
	}//end testRecomputeAllWalksEveryClient()
}//end class
