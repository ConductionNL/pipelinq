<?php

/**
 * Unit tests for RenewalEngineService.
 *
 * Covers renewal-window math, the active→expiring transition with single
 * renewal-lead creation, idempotency (no second lead when renewalLeadRef set),
 * won/lost reconciliation, successor drafting, and silent-expiry churn.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ContractService;
use OCA\Pipelinq\Service\RenewalEngineService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Capturing ObjectService double for renewal-lead / task creation.
 */
class FakeRenewalObjectService {
	/**
	 * Saved objects in [register, schema, data] form.
	 *
	 * @var array<int, array{0:string,1:string,2:array<string,mixed>}>
	 */
	public array $saved = [];

	/**
	 * Sequence for generated ids.
	 *
	 * @var int
	 */
	private int $seq = 0;

	/**
	 * Capture a saveObject call (OR signature: object, extend, register, schema, uuid).
	 *
	 * @param array|object $object The object payload.
	 * @param array|null $extend Ignored.
	 * @param string|null $register Register id.
	 * @param string|null $schema Schema id.
	 * @param string|null $uuid Existing uuid or null.
	 *
	 * @return array<string,mixed>
	 */
	public function saveObject($object, $extend = [], $register = null, $schema = null, $uuid = null): array {
		$data = (array)$object;
		$this->seq++;
		$data['id'] = ($uuid ?? 'new-' . $this->seq);
		$this->saved[] = [(string)$register, (string)$schema, $data];
		return $data;
	}//end saveObject()
}//end class

/**
 * Test suite for RenewalEngineService.
 */
class RenewalEngineServiceTest extends TestCase {
	/**
	 * Build an engine with a configurable app-config and a captured ObjectService.
	 *
	 * @param FakeRenewalObjectService $os The fake object service.
	 *
	 * @return RenewalEngineService
	 */
	private function makeEngine(FakeRenewalObjectService $os): RenewalEngineService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') {
				$vals = [
					'register' => 'reg-1',
					'contract_schema' => 'contract-schema',
					'lead_schema' => 'lead-schema',
					'task_schema' => 'task-schema',
					'renewal_default_lead_time_days' => '60',
				];
				return $vals[$key] ?? $default;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($os);

		// Real ContractService wired to the same fake OR, so saves land in $os too.
		$contractService = new ContractService($appConfig, $container, $this->createMock(LoggerInterface::class));

		return new RenewalEngineService($appConfig,
			$container,
			$contractService,
			$this->createMock(LoggerInterface::class),
		);
	}//end makeEngine()

	/**
	 * Window width is the larger of noticePeriodDays and the default lead time.
	 *
	 * @return void
	 */
	public function testRenewalWindowDays(): void {
		$engine = $this->makeEngine(new FakeRenewalObjectService());
		$this->assertSame(60, $engine->renewalWindowDays(['noticePeriodDays' => 30]));
		$this->assertSame(90, $engine->renewalWindowDays(['noticePeriodDays' => 90]));
	}//end testRenewalWindowDays()

	/**
	 * A contract ending 2026-09-30 with notice 60 + default 60 enters its window
	 * on 2026-08-01 (60 days before end).
	 *
	 * @return void
	 */
	public function testIsInRenewalWindow(): void {
		$engine = $this->makeEngine(new FakeRenewalObjectService());
		$contract = ['status' => 'active', 'endDate' => '2026-09-30', 'noticePeriodDays' => 60];

		$this->assertFalse($engine->isInRenewalWindow($contract, '2026-07-15'));
		$this->assertTrue($engine->isInRenewalWindow($contract, '2026-08-01'));
		$this->assertTrue($engine->isInRenewalWindow($contract, '2026-09-30'));
	}//end testIsInRenewalWindow()

	/**
	 * Annualized value scales by interval (monthly ×12, quarterly ×4).
	 *
	 * @return void
	 */
	public function testAnnualizedValue(): void {
		$engine = $this->makeEngine(new FakeRenewalObjectService());
		$this->assertSame(9000.0, $engine->annualizedValue(['billingInterval' => 'monthly', 'valuePerInterval' => 750]));
		$this->assertSame(12000.0, $engine->annualizedValue(['billingInterval' => 'quarterly', 'valuePerInterval' => 3000]));
		$this->assertSame(5000.0, $engine->annualizedValue(['billingInterval' => 'annual', 'valuePerInterval' => 5000]));
	}//end testAnnualizedValue()

	/**
	 * Processing an in-window active contract flips it to expiring and creates
	 * exactly one renewal lead, linked via renewalLeadRef.
	 *
	 * @return void
	 */
	public function testProcessCreatesExpiringAndOneLead(): void {
		$os = new FakeRenewalObjectService();
		$engine = $this->makeEngine($os);

		$contract = [
			'id' => 'k-1',
			'status' => 'active',
			'title' => 'Support',
			'clientRef' => 'client-1',
			'ownerId' => 'maria',
			'billingInterval' => 'monthly',
			'valuePerInterval' => 750,
			'endDate' => '2026-09-30',
			'noticePeriodDays' => 60,
		];

		$action = $engine->processContract($contract, '2026-08-01');
		$this->assertSame('expiring', $action);

		// One lead created against the lead schema, tagged renewal.
		$leads = array_filter($os->saved, static fn ($s) => $s[1] === 'lead-schema');
		$this->assertCount(1, $leads);
		$lead = reset($leads)[2];
		$this->assertSame(['renewal'], $lead['tags']);
		$this->assertSame('client-1', $lead['client']);
		$this->assertSame(9000.0, $lead['value']);

		// Contract saved with status expiring + renewalLeadRef set.
		$contractSaves = array_filter($os->saved, static fn ($s) => $s[1] === 'contract-schema');
		$this->assertNotEmpty($contractSaves);
		$savedContract = end($contractSaves)[2];
		$this->assertSame('expiring', $savedContract['status']);
		$this->assertNotSame('', $savedContract['renewalLeadRef']);
	}//end testProcessCreatesExpiringAndOneLead()

	/**
	 * An expiring contract that already has a renewalLeadRef is not re-processed
	 * (idempotent — no new lead).
	 *
	 * @return void
	 */
	public function testIdempotentReRun(): void {
		$os = new FakeRenewalObjectService();
		$engine = $this->makeEngine($os);

		$contract = [
			'id' => 'k-1',
			'status' => 'expiring',
			'endDate' => '2026-09-30',
			'renewalLeadRef' => 'lead-1',
			'renewalLeadOutcome' => '',
			'noticePeriodDays' => 60,
			'noticeReminderSent' => true,
		];

		$action = $engine->processContract($contract, '2026-08-01');
		$this->assertSame('noop', $action);
		$leads = array_filter($os->saved, static fn ($s) => $s[1] === 'lead-schema');
		$this->assertCount(0, $leads);
	}//end testIdempotentReRun()

	/**
	 * A won renewal lead marks the contract renewed and drafts a successor.
	 *
	 * @return void
	 */
	public function testReconcileWonDraftsSuccessor(): void {
		$os = new FakeRenewalObjectService();
		$engine = $this->makeEngine($os);

		$contract = [
			'id' => 'k-1',
			'status' => 'expiring',
			'title' => 'Support',
			'clientRef' => 'client-1',
			'ownerId' => 'maria',
			'billingInterval' => 'monthly',
			'valuePerInterval' => 750,
			'endDate' => '2027-06-30',
			'renewalLeadOutcome' => 'won',
		];

		$action = $engine->reconcile($contract);
		$this->assertSame('renewed', $action);

		$contractSaves = array_values(array_filter($os->saved, static fn ($s) => $s[1] === 'contract-schema'));
		// First save: the renewed contract. Second save: the successor draft.
		$this->assertGreaterThanOrEqual(2, count($contractSaves));
		$renewed = $contractSaves[0][2];
		$successor = end($contractSaves)[2];
		$this->assertSame('renewed', $renewed['status']);
		$this->assertSame('draft', $successor['status']);
		$this->assertSame('2027-07-01', $successor['startDate']);
		$this->assertSame('k-1', $successor['predecessorContractRef']);
	}//end testReconcileWonDraftsSuccessor()

	/**
	 * A lost renewal lead churns the contract.
	 *
	 * @return void
	 */
	public function testReconcileLostChurns(): void {
		$os = new FakeRenewalObjectService();
		$engine = $this->makeEngine($os);

		$action = $engine->reconcile(
			['id' => 'k-1', 'status' => 'expiring', 'renewalLeadOutcome' => 'lost']
		);
		$this->assertSame('churned', $action);
		$contractSaves = array_values(array_filter($os->saved, static fn ($s) => $s[1] === 'contract-schema'));
		$this->assertSame('churned', $contractSaves[0][2]['status']);
	}//end testReconcileLostChurns()

	/**
	 * An expiring contract whose endDate has passed with an unresolved lead is
	 * churned by the nightly run (silent expiry).
	 *
	 * @return void
	 */
	public function testSilentExpiryChurns(): void {
		$os = new FakeRenewalObjectService();
		$engine = $this->makeEngine($os);

		$contract = [
			'id' => 'k-1',
			'status' => 'expiring',
			'endDate' => '2026-06-30',
			'renewalLeadOutcome' => '',
		];

		$action = $engine->processContract($contract, '2026-07-01');
		$this->assertSame('churned-silent', $action);
		$contractSaves = array_values(array_filter($os->saved, static fn ($s) => $s[1] === 'contract-schema'));
		$this->assertSame('churned', $contractSaves[0][2]['status']);
	}//end testSilentExpiryChurns()

	/**
	 * The notice-deadline My Work entry is created once for an expiring contract.
	 *
	 * @return void
	 */
	public function testNoticeDeadlineMyWorkEntry(): void {
		$os = new FakeRenewalObjectService();
		$engine = $this->makeEngine($os);

		$contract = [
			'id' => 'k-1',
			'status' => 'expiring',
			'endDate' => '2026-09-30',
			'noticePeriodDays' => 60,
			'autoRenew' => true,
			'contractNumber' => 'C-2026-001',
			'ownerId' => 'maria',
			'renewalLeadOutcome' => '',
			'noticeReminderSent' => false,
		];

		// Notice deadline = 2026-09-30 − 60 days = 2026-08-01.
		$action = $engine->processContract($contract, '2026-08-01');
		$this->assertSame('noticed', $action);

		$tasks = array_filter($os->saved, static fn ($s) => $s[1] === 'task-schema');
		$this->assertCount(1, $tasks);
		$task = reset($tasks)[2];
		$this->assertSame('maria', $task['assigneeUserId']);
		$this->assertStringContainsStringIgnoringCase('automatically', $task['subject']);
	}//end testNoticeDeadlineMyWorkEntry()
}//end class
