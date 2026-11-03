<?php

/**
 * Golden test for ForecastController, called as Nextcloud dispatches it.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\ForecastController;
use OCA\Pipelinq\Lifecycle\ForecastAccessPolicy;
use OCA\Pipelinq\Service\ForecastExportService;
use OCA\Pipelinq\Service\ForecastOverrideService;
use OCA\Pipelinq\Tests\Unit\Support\Autowire;
use OCA\Pipelinq\Tests\Unit\Support\ComparesGolden;
use OCA\Pipelinq\Tests\Unit\Support\NextcloudDispatch;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Every action of ForecastController over the session, scope, validation and
 * failure cases: the response (class, status, body, download headers) and
 * every call the controller made to the policy, the services and the logger,
 * in order. The controller is built by autowiring from the leaf doubles only.
 * Compared with ForecastControllerGolden.json; set GOLDEN_RECORD=1 to rewrite
 * it, then run once more without it.
 *
 * @covers \OCA\Pipelinq\Controller\ForecastController
 * @covers \OCA\Pipelinq\Lifecycle\ForecastAccess
 * @covers \OCA\Pipelinq\Service\ForecastOverrideDesk
 * @covers \OCA\Pipelinq\Service\ForecastSnapshotQuery
 * @covers \OCA\Pipelinq\Util\ReadsTypedRequestParameters
 */
class ForecastControllerGoldenTest extends TestCase {
	use ComparesGolden;

	/**
	 * What the current case did, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $trace = [];

	/**
	 * Run every case and compare it with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$actual[$name] = $this->runCase(case: $case);
		}

		$this->assertGolden(file: __DIR__.'/ForecastControllerGolden.json', actual: $actual);
	}//end testGolden()

	/**
	 * Forged identity parameters change nothing: the actor is the session user.
	 *
	 * @return void
	 */
	public function testForgedIdentityParametersChangeNothing(): void {
		$forged = ['uid' => 'mallory', 'userId' => 'mallory', 'managerId' => 'mallory', 'user' => 'mallory'];
		foreach ($this->cases() as $name => $case) {
			$plain = $this->runCase(case: $case);
			$case['params'] = (($case['params'] ?? []) + $forged);
			$this->assertSame($plain, $this->runCase(case: $case), $name);
		}
	}//end testForgedIdentityParametersChangeNothing()

	/**
	 * The cases: action, caller, request parameters and how the doubles answer.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$read = ['periodId' => 'FY26-Q1', 'level' => 'team'];
		$override = ['period_id' => 'FY26-Q1', 'override_owner_id' => 'rep-bob', 'level' => 'team', 'category' => 'best_case', 'override_amount' => '1250.50', 'reason' => 'Signed'];
		return [
			'read no session'                 => ['action' => 'snapshots', 'params' => $read, 'user' => null],
			'read no period'                  => ['action' => 'snapshots', 'params' => ['level' => 'team']],
			'read no level'                   => ['action' => 'snapshots', 'params' => ['periodId' => 'FY26-Q1']],
			'read unknown level'              => ['action' => 'snapshots', 'params' => ['periodId' => 'FY26-Q1', 'level' => 'galaxy']],
			'read upper-case level'           => ['action' => 'snapshots', 'params' => ['periodId' => 'FY26-Q1', 'level' => 'TEAM']],
			'read denied'                     => ['action' => 'snapshots', 'params' => $read + ['ownerId' => 'rep-x'], 'user' => 'rep-bob'],
			'read team json'                  => ['action' => 'snapshots', 'params' => $read],
			'read rep with owner'             => ['action' => 'snapshots', 'params' => ['periodId' => 'P', 'level' => 'rep', 'ownerId' => 'rep-bob']],
			'read division'                   => ['action' => 'snapshots', 'params' => ['periodId' => 'P', 'level' => 'division']],
			'read company clamps'             => ['action' => 'snapshots', 'params' => ['periodId' => 'P', 'level' => 'company', 'limit' => '100000', 'offset' => '-5']],
			'read zero limit'                 => ['action' => 'snapshots', 'params' => $read + ['limit' => '0', 'offset' => '3']],
			'read text limit'                 => ['action' => 'snapshots', 'params' => $read + ['limit' => 'abc', 'offset' => 'x']],
			'read float limit'                => ['action' => 'snapshots', 'params' => $read + ['limit' => '12.7', 'offset' => 4.9]],
			'read int limit'                  => ['action' => 'snapshots', 'params' => $read + ['limit' => 25, 'offset' => 50]],
			'read limit 200'                  => ['action' => 'snapshots', 'params' => $read + ['limit' => '200']],
			'read limit 201'                  => ['action' => 'snapshots', 'params' => $read + ['limit' => '201']],
			'read array limit'                => ['action' => 'snapshots', 'params' => $read + ['limit' => ['5']]],
			'read csv'                        => ['action' => 'snapshots', 'params' => $read + ['format' => 'csv']],
			'read CSV upper'                  => ['action' => 'snapshots', 'params' => $read + ['format' => 'CSV']],
			'read xml format'                 => ['action' => 'snapshots', 'params' => $read + ['format' => 'xml']],
			'read empty owner'                => ['action' => 'snapshots', 'params' => $read + ['ownerId' => '']],
			'read null owner'                 => ['action' => 'snapshots', 'params' => $read + ['ownerId' => null]],
			'read array period'               => ['action' => 'snapshots', 'params' => ['periodId' => ['x'], 'level' => 'team']],
			'read array level'                => ['action' => 'snapshots', 'params' => ['periodId' => 'P', 'level' => ['team']]],
			'read array owner'                => ['action' => 'snapshots', 'params' => $read + ['ownerId' => ['a']]],
			'read array format'               => ['action' => 'snapshots', 'params' => $read + ['format' => ['csv']]],
			'read integer period'             => ['action' => 'snapshots', 'params' => ['periodId' => 2026, 'level' => 'team']],
			'read boolean owner'              => ['action' => 'snapshots', 'params' => $read + ['ownerId' => true]],
			'read no session array period'    => ['action' => 'snapshots', 'params' => ['periodId' => ['x']], 'user' => null],
			'create no session'               => ['action' => 'createOverride', 'params' => $override, 'user' => null],
			'create denied'                   => ['action' => 'createOverride', 'params' => $override, 'user' => 'rep-bob'],
			'create denied company'           => ['action' => 'createOverride', 'params' => ['level' => 'company'] + $override],
			'create invalid'                  => ['action' => 'createOverride', 'params' => $override, 'invalid' => 'Amount must be a number.'],
			'create defaults'                 => ['action' => 'createOverride', 'params' => ['level' => 'team']],
			'create ok'                       => ['action' => 'createOverride', 'params' => $override],
			'create store fails'              => ['action' => 'createOverride', 'params' => $override, 'createFails' => true],
			'create no level'                 => ['action' => 'createOverride', 'params' => []],
			'delete no session'               => ['action' => 'deleteOverride', 'params' => ['id' => 'ov-1'], 'user' => null],
			'delete missing'                  => ['action' => 'deleteOverride', 'params' => ['id' => 'ov-missing']],
			'delete denied'                   => ['action' => 'deleteOverride', 'params' => ['id' => 'ov-1'], 'user' => 'rep-bob'],
			'delete stored company denied'    => ['action' => 'deleteOverride', 'params' => ['id' => 'ov-company']],
			'delete no stored level'          => ['action' => 'deleteOverride', 'params' => ['id' => 'ov-nolevel']],
			'delete ok'                       => ['action' => 'deleteOverride', 'params' => ['id' => 'ov-1']],
			'delete fails'                    => ['action' => 'deleteOverride', 'params' => ['id' => 'ov-1'], 'deleteFails' => true],
			'delete array id'                 => ['action' => 'deleteOverride', 'params' => ['id' => ['ov-1']]],
		];
	}//end cases()

	/**
	 * Run one case against a freshly autowired controller.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(array $case): array {
		$this->trace = [];
		$params = ($case['params'] ?? []);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => (isset($params[$key]) === true ? $params[$key] : $default)
		);
		$request->method('getParams')->willReturn($params);

		$doubles = [
			IRequest::class => $request,
			IUserSession::class => $this->session(uid: (array_key_exists('user', $case) === true ? $case['user'] : 'manager')),
			ForecastAccessPolicy::class => $this->policy(),
			ForecastExportService::class => $this->exportService(),
			ForecastOverrideService::class => $this->overrideService(case: $case),
			LoggerInterface::class => $this->logger(),
		];
		$controller = Autowire::build(class: ForecastController::class, doubles: $doubles);

		try {
			$response = NextcloudDispatch::call(controller: $controller, method: $case['action'], params: $params);
			$result = ['class' => get_class($response), 'status' => $response->getStatus()];
			if ($response instanceof JSONResponse) {
				$result['data'] = $response->getData();
			}

			if ($response instanceof DataDownloadResponse) {
				$result['body'] = $response->render();
				$result['headers'] = (new ReflectionProperty(Response::class, 'headers'))->getValue($response);
			}
		} catch (Throwable $e) {
			$result = ['threw' => get_class($e), 'message' => $e->getMessage()];
		}

		return $result + ['trace' => $this->trace];
	}//end runCase()

	/**
	 * A session for the given user (or none).
	 *
	 * @param string|null $uid The acting user.
	 *
	 * @return IUserSession
	 */
	private function session(?string $uid): IUserSession {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	/**
	 * A policy that lets `manager` read and override below company level.
	 *
	 * @return ForecastAccessPolicy
	 */
	private function policy(): ForecastAccessPolicy {
		$policy = $this->createMock(ForecastAccessPolicy::class);
		$policy->method('canRead')->willReturnCallback(
			function (string $userId, string $level, ?string $ownerId): bool {
				$this->trace[] = ['canRead' => [$userId, $level, $ownerId]];
				return $userId === 'manager';
			}
		);
		$policy->method('canOverride')->willReturnCallback(
			function (string $userId, string $level): bool {
				$this->trace[] = ['canOverride' => [$userId, $level]];
				return $userId === 'manager' && $level !== 'company';
			}
		);
		return $policy;
	}//end policy()

	/**
	 * An export service that echoes the page it was asked for.
	 *
	 * @return ForecastExportService
	 */
	private function exportService(): ForecastExportService {
		$service = $this->createMock(ForecastExportService::class);
		$service->method('exportSnapshots')->willReturnCallback(
			function (string $periodId, string $level, ?string $ownerId, int $limit, int $offset): array {
				$this->trace[] = ['exportSnapshots' => [$periodId, $level, $ownerId, $limit, $offset]];
				return ['snapshots' => [['owner_id' => 'rep-bob', 'commit' => 1000.5]], 'total' => 7, 'limit' => $limit, 'offset' => $offset];
			}
		);
		$service->method('toCsv')->willReturnCallback(
			function (array $snapshots): string {
				$this->trace[] = ['toCsv' => $snapshots];
				return "owner_id,commit\nrep-bob,1000.5\n";
			}
		);
		return $service;
	}//end exportService()

	/**
	 * An override service with three stored overrides.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return ForecastOverrideService
	 */
	private function overrideService(array $case): ForecastOverrideService {
		$stored = ['ov-1' => ['id' => 'ov-1', 'level' => 'team'], 'ov-company' => ['id' => 'ov-company', 'level' => 'company'], 'ov-nolevel' => ['id' => 'ov-nolevel']];
		$service = $this->createMock(ForecastOverrideService::class);
		$service->method('validatePayload')->willReturnCallback(
			function (array $payload) use ($case): ?string {
				$this->trace[] = ['validatePayload' => $payload];
				return ($case['invalid'] ?? null);
			}
		);
		$service->method('createOverride')->willReturnCallback(
			function (array $payload, string $managerId) use ($case): array {
				$this->trace[] = ['createOverride' => [$payload, $managerId]];
				if (($case['createFails'] ?? false) === true) {
					throw new RuntimeException('db down: secret detail');
				}

				return ['id' => 'ov-new'] + $payload;
			}
		);
		$service->method('getOverride')->willReturnCallback(
			function (string $id) use ($stored): ?array {
				$this->trace[] = ['getOverride' => $id];
				return ($stored[$id] ?? null);
			}
		);
		$service->method('deleteOverride')->willReturnCallback(
			function (string $id) use ($case): bool {
				$this->trace[] = ['deleteOverride' => $id];
				return (($case['deleteFails'] ?? false) === false);
			}
		);
		return $service;
	}//end overrideService()

	/**
	 * A logger that records every entry.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					$this->trace[] = ['log' => [$level, (string)$message, $context]];
				}
			);
		}

		return $logger;
	}//end logger()
}//end class
