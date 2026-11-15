<?php

/**
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
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

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\Pipelinq\Controller\ClientHistorySummaryController;
use OCA\Pipelinq\Service\ClientHistorySummaryService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * The history summary route reads the client as the caller first (task 2.2).
 *
 * @covers \OCA\Pipelinq\Controller\ClientHistorySummaryController
 *
 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-2.2
 */
class ClientHistorySummaryControllerTest extends TestCase {

	/**
	 * The summary service.
	 *
	 * @var ClientHistorySummaryService&MockObject
	 */
	private ClientHistorySummaryService&MockObject $summary;

	/**
	 * The find calls made, as their named arguments.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $finds = [];

	/**
	 * The controller.
	 *
	 * @param string|null    $uid   The signed-in user; null for none.
	 * @param mixed          $found What find returns: true for a client, null for none.
	 * @param Throwable|null $throw What find throws.
	 * @param string         $check The `check` parameter.
	 *
	 * @return ClientHistorySummaryController
	 */
	private function controller(?string $uid = 'anna', mixed $found = true, ?Throwable $throw = null, string $check = ''): ClientHistorySummaryController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($key === 'check' ? $check : $default)
		);

		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (['register' => 'r', 'client_schema' => 'client'][$key] ?? $default)
		);

		$entity  = $this->createMock(ObjectEntityInterface::class);
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (...$args) use ($found, $throw, $entity): ?ObjectEntityInterface {
				$this->finds[] = $args;
				if ($throw !== null) {
					throw $throw;
				}

				return ($found === true) ? $entity : null;
			}
		);

		$this->summary = $this->createMock(ClientHistorySummaryService::class);

		return new ClientHistorySummaryController($request, $this->summary, $objects, $session, $appConfig, $this->createMock(LoggerInterface::class));
	}//end controller()

	/**
	 * A readable client gets its summary, written for the caller.
	 *
	 * @return void
	 */
	public function testAReadableClientGetsItsSummary(): void {
		$controller = $this->controller();
		$payload    = ['available' => true, 'summary' => 'Two calls.', 'recordsRead' => 2, 'generatedAt' => '2026-11-13T12:00:00+00:00'];
		$this->summary->expects($this->once())->method('summarise')->with('client-1', 'anna')->willReturn($payload);

		$response = $controller->show('client-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($payload, $response->getData());
		// Positional after the mock: id, _extend, files, register, schema, _rbac, _multitenancy.
		$this->assertSame(['client-1', 'r', 'client', true, true], [$this->finds[0][0], $this->finds[0][3], $this->finds[0][4], $this->finds[0][5], $this->finds[0][6]]);
	}//end testAReadableClientGetsItsSummary()

	/**
	 * The check only answers whether summarising is available.
	 *
	 * @return void
	 */
	public function testCheckAnswersOnlyAvailability(): void {
		$controller = $this->controller(check: '1');
		$this->summary->method('available')->willReturn(false);
		$this->summary->expects($this->never())->method('summarise');

		$response = $controller->show('client-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['available' => false], $response->getData());
	}//end testCheckAnswersOnlyAvailability()

	/**
	 * Every refusal stops before the summary is written.
	 *
	 * @return void
	 */
	public function testRefusalsNeverReachTheSummary(): void {
		$cases = [
			[401, $this->controller(uid: null)],
			[403, $this->controller(throw: new NotAuthorizedException('no'))],
			[404, $this->controller(throw: new DoesNotExistException('gone'))],
			[404, $this->controller(found: null)],
			[500, $this->controller(throw: new RuntimeException('down'))],
		];

		foreach ($cases as [$status, $controller]) {
			$this->summary->expects($this->never())->method('summarise');
			$this->summary->expects($this->never())->method('available');
			$this->assertSame($status, $controller->show('client-1')->getStatus());
		}
	}//end testRefusalsNeverReachTheSummary()

	/**
	 * A failing summary is a 500, not a partial answer.
	 *
	 * @return void
	 */
	public function testAFailingSummaryIsAServerError(): void {
		$controller = $this->controller();
		$this->summary->method('summarise')->willThrowException(new RuntimeException('down'));

		$response = $controller->show('client-1');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['message' => 'Operation failed'], $response->getData());
	}//end testAFailingSummaryIsAServerError()
}//end class
