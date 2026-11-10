<?php

/**
 * Golden test of the three period reports of ReportingController.
 *
 * `getKpis`, `getChannels` and `getAgents` share their gate, their period
 * validation and their failure answer; this pins every answer of each, with a
 * mocked ReportingService so a failing report can be reached.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\ReportingController;
use OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\ReportingService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins the gate, the period validation, the report and the failure answer of every period report.
 */
final class ReportingPeriodActionsGoldenTest extends TestCase {
	/**
	 * Every case, per report: [caller, query, service throws, expected status, expected body].
	 *
	 * @return array<string, array<int, mixed>> The cases.
	 */
	private function cases(): array {
		$missing = ['message' => 'Missing required parameters: from, to (or period)'];
		$invalid = ['message' => 'Invalid date format. Use ISO 8601 (YYYY-MM-DD or full datetime)'];
		$range = ['from' => '2026-06-01', 'to' => '2026-06-30'];
		return [
			'no session' => [null, $range, false, 401, ['message' => 'Authentication required']],
			'not a crm user' => ['outsider', $range, false, 403, ['message' => 'Forbidden']],
			'no range or period' => ['agent', [], false, 400, $missing],
			'unknown period' => ['agent', ['period' => 'year'], false, 400, $missing],
			'only a start' => ['agent', ['from' => '2026-06-01'], false, 400, $missing],
			'malformed start' => ['agent', ['from' => 'not-a-date', 'to' => '2026-06-30'], false, 400, $invalid],
			'malformed end' => ['agent', ['from' => '2026-06-01', 'to' => '30/06/2026'], false, 400, $invalid],
			'report fails' => ['agent', $range, true, 500, ['message' => 'Operation failed']],
			'report' => ['agent', $range + ['granularity' => 'weekly'], false, 200, null],
		];
	}//end cases()

	/**
	 * Every period report answers exactly as recorded, and only an admitted caller with a valid period reaches the service.
	 *
	 * @return void
	 */
	public function testEveryPeriodReportAnswersExactly(): void {
		$reports = [
			'getKpis' => [['getKpis'], ['kpi' => 1]],
			'getChannels' => [['getChannelDistribution', 'getChannelTrend'], ['distribution' => ['d'], 'trend' => ['t']]],
			'getAgents' => [['getAgentPerformance'], ['agents' => ['a']]],
		];
		foreach ($reports as $action => [$serviceMethods, $okBody]) {
			foreach ($this->cases() as $name => [$caller, $query, $throws, $status, $body]) {
				$calls = [];
				$controller = $this->controller(caller: $caller, query: $query, service: $this->service(throws: $throws, calls: $calls));

				$response = $controller->$action();

				$label = $action.' / '.$name;
				$this->assertSame([$status, ($body ?? $okBody)], [$response->getStatus(), $response->getData()], $label);
				$reached = in_array($name, ['report fails', 'report'], true);
				$this->assertSame($reached, $calls !== [], $label.' reaching the service');
				if ($name === 'report') {
					$expected = array_map(static fn (string $method): array => [$method, '2026-06-01', '2026-06-30'], $serviceMethods);
					if ($action === 'getChannels') {
						$expected[1][] = 'weekly';
					}

					$this->assertSame($expected, $calls, $label.' service calls');
				}
			}
		}
	}//end testEveryPeriodReportAnswersExactly()

	/**
	 * A reporting service that records its calls and answers canned figures, or throws.
	 *
	 * @param bool                          $throws Whether every report fails.
	 * @param array<int, array<int, mixed>> $calls  Receives the calls.
	 *
	 * @return ReportingService The service.
	 */
	private function service(bool $throws, array &$calls): ReportingService {
		$service = $this->createMock(ReportingService::class);
		$answers = [
			'getKpis' => ['kpi' => 1],
			'getChannelDistribution' => ['d'],
			'getChannelTrend' => ['t'],
			'getAgentPerformance' => ['a'],
		];
		foreach ($answers as $method => $answer) {
			$service->method($method)->willReturnCallback(
				static function (mixed ...$args) use ($method, $answer, $throws, &$calls): array {
					$calls[] = array_merge([$method], $args);
					if ($throws === true) {
						throw new RuntimeException('store offline');
					}

					return $answer;
				}
			);
		}

		return $service;
	}//end service()

	/**
	 * The controller for a caller and a query.
	 *
	 * @param string|null          $caller  'agent' (a CRM user), another uid, or null for no session.
	 * @param array<string, mixed> $query   The query parameters.
	 * @param ReportingService     $service The reporting service.
	 *
	 * @return ReportingController The controller.
	 */
	private function controller(?string $caller, array $query, ReportingService $service): ReportingController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($caller !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($caller);
		}

		$session->method('getUser')->willReturn($user);
		$policy = $this->createMock(ObjectOwnerAccessPolicy::class);
		$policy->method('isPrivileged')->willReturnCallback(static fn (string $uid): bool => $uid === 'agent');
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($query[$key] ?? $default)
		);

		return new ReportingController($request, $service, new CrmPrivilegeCheck($session, $policy));
	}//end controller()

	/**
	 * A named period resolves against today; an explicit bound wins over it.
	 *
	 * @return void
	 */
	public function testNamedPeriodsResolveAgainstToday(): void {
		$cases = [
			'today' => ['period' => 'today'],
			'week' => ['period' => 'week'],
			'month' => ['period' => 'month'],
			'explicit wins' => ['period' => 'today', 'from' => '2026-06-01', 'to' => '2026-06-30'],
		];
		$seen = [];
		foreach ($cases as $name => $query) {
			$calls = [];
			$this->controller(caller: 'agent', query: $query, service: $this->service(throws: false, calls: $calls))->getAgents();
			$seen[$name] = [$calls[0][1], $calls[0][2]];
		}

		$today = (new \DateTimeImmutable('now'))->format('Y-m-d');
		$this->assertSame([$today, $today], $seen['today']);
		$this->assertSame([(new \DateTimeImmutable('now'))->format('Y-m-01'), $today], $seen['month']);
		$this->assertSame($today, $seen['week'][1]);
		$this->assertSame('1', (new \DateTimeImmutable($seen['week'][0]))->format('N'), 'a week starts on Monday');
		$this->assertLessThan(7, (new \DateTimeImmutable($seen['week'][0]))->diff(new \DateTimeImmutable($today))->days);
		$this->assertSame(['2026-06-01', '2026-06-30'], $seen['explicit wins']);
	}//end testNamedPeriodsResolveAgainstToday()

	/**
	 * Saving SLA targets stores every numeric pair, refuses when any is rejected, and fails closed.
	 *
	 * @return void
	 */
	public function testSavingSlaTargetsAnswersExactly(): void {
		$targets = ['phone' => ['fcr' => 80, 'aht' => '300'], 'email' => 'not-a-map', 'chat' => ['fcr' => 70]];
		$cases = [
			'not a map' => ['nope', [], 400, ['message' => 'Operation failed']],
			'all accepted' => [$targets, [], 200, ['success' => true, 'targets' => ['phone' => ['fcr' => '80']]]],
			'one rejected' => [$targets, ['aht'], 400, ['message' => 'Operation failed']],
			'store fails' => [$targets, ['throw'], 500, ['message' => 'Operation failed']],
		];
		foreach ($cases as $name => [$given, $rejected, $status, $body]) {
			$calls = [];
			$service = $this->createMock(ReportingService::class);
			$service->method('setSlaTarget')->willReturnCallback(
				static function (string $channel, string $metric, string $value) use ($rejected, &$calls): bool {
					$calls[] = [$channel, $metric, $value];
					if ($rejected === ['throw']) {
						throw new RuntimeException('store offline');
					}

					return in_array($metric, $rejected, true) === false;
				}
			);
			$service->method('getAllSlaTargets')->willReturn(['phone' => ['fcr' => '80']]);

			$response = $this->controller(caller: 'agent', query: ['targets' => $given], service: $service)->updateSla();

			$this->assertSame([$status, $body], [$response->getStatus(), $response->getData()], $name);
			$expectedCalls = [['phone', 'fcr', '80'], ['phone', 'aht', '300'], ['chat', 'fcr', '70']];
			if ($name === 'not a map') {
				$expectedCalls = [];
			}

			if ($name === 'store fails') {
				$expectedCalls = [['phone', 'fcr', '80']];
			}

			$this->assertSame($expectedCalls, $calls, $name.' calls');
		}
	}//end testSavingSlaTargetsAnswersExactly()
}//end class
