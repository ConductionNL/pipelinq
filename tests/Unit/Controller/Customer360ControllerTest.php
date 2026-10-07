<?php

/**
 * Unit tests for Customer360Controller.
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
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/klantbeeld-360-activation/tasks.md#task-5.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\Pipelinq\Controller\Customer360Controller;
use OCA\Pipelinq\Service\Customer360SummaryService;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for Customer360Controller::summary() — auth, the per-object read guard
 * (no IDOR), and the doelbinding access log.
 *
 * @spec openspec/specs/customer-360/spec.md#requirement-customer-360-access-is-logged-doelbinding-mvp
 */
class Customer360ControllerTest extends TestCase {
	/**
	 * Build a controller with the given mocked collaborators, wiring
	 * `getParam('clientId', ...)` and a fixed register/client_schema config.
	 *
	 * @param string|null $clientId The `clientId` query param (null = absent).
	 * @param mixed $foundClient What the mocked ObjectService::find() returns.
	 * @param string|null $uid The authenticated user's UID (null = unauthenticated).
	 * @param mixed $summaryOrThrow The summary array to return, or a \Throwable to throw.
	 * @param LoggerInterface|null $logger Optional pre-built logger mock (for asserting calls).
	 * @param array|null $findCalls Receives the arguments of every ObjectService::find() call.
	 * @param \Throwable|null $findThrows What ObjectService::find() throws, if anything.
	 *
	 * @return Customer360Controller
	 */
	private function buildController(
		?string $clientId,
		mixed $foundClient,
		?string $uid,
		mixed $summaryOrThrow,
		?LoggerInterface $logger = null,
		?array &$findCalls = null,
		?\Throwable $findThrows = null,
	): Customer360Controller {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) use ($clientId) {
				if ($key === 'clientId') {
					return $clientId ?? $default;
				}
				return $default;
			}
		);

		$userSession = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$userSession->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$userSession->method('getUser')->willReturn($user);
		}

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				return match ($key) {
					'register' => 'pipelinq',
					'client_schema' => 'client',
					default => $default,
				};
			}
		);

		// ObjectService::find() returns an ObjectEntityInterface since ADR-084,
		// so the fixture row is wrapped rather than handed back as an array —
		// a mock cannot return the array shape the method no longer declares.
		$foundEntity = null;
		if (is_array($foundClient) === true) {
			$foundEntity = new ObjectEntity();
			$foundEntity->setUuid((string)($foundClient['@self']['id'] ?? 'client-1'));
			$foundEntity->setObject($foundClient);
		}

		$objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			static function (...$args) use ($foundEntity, &$findCalls, $findThrows) {
				$findCalls[] = $args;
				if ($findThrows !== null) {
					throw $findThrows;
				}

				return $foundEntity;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$summaryService = $this->createMock(Customer360SummaryService::class);
		if ($summaryOrThrow instanceof \Throwable) {
			$summaryService->method('getSummary')->willThrowException($summaryOrThrow);
		} else {
			$summaryService->method('getSummary')->willReturn($summaryOrThrow ?? []);
		}

		return new Customer360Controller($request,
			$summaryService,
			$userSession,
			$appConfig,
			$container,
			$logger ?? $this->createMock(LoggerInterface::class),
		);
	}//end buildController()

	/**
	 * Unauthenticated callers are rejected before any read happens.
	 *
	 * @return void
	 */
	public function testSummaryReturns401WhenNoUser(): void {
		$controller = $this->buildController(
			clientId: 'client-1',
			foundClient: ['name' => 'Client'],
			uid: null,
			summaryOrThrow: [],
		);

		$response = $controller->summary();

		$this->assertSame(401, $response->getStatus());
	}//end testSummaryReturns401WhenNoUser()

	/**
	 * A missing `clientId` query param is a 400, not a 404/500.
	 *
	 * @return void
	 */
	public function testSummaryReturns400WhenClientIdMissing(): void {
		$controller = $this->buildController(
			clientId: null,
			foundClient: ['name' => 'Client'],
			uid: 'agent-1',
			summaryOrThrow: [],
		);

		$response = $controller->summary();

		$this->assertSame(400, $response->getStatus());
	}//end testSummaryReturns400WhenClientIdMissing()

	/**
	 * When the client does not resolve through the RBAC-scoped
	 * ObjectService::find() (absent, or in another tenant), the endpoint 404s
	 * and never calls the summary service.
	 *
	 * @return void
	 */
	public function testSummaryReturns404WhenCallerCannotReadClient(): void {
		$controller = $this->buildController(
			clientId: 'client-not-mine',
			foundClient: null,
			uid: 'agent-1',
			summaryOrThrow: ['openTicketCount' => 99],
		);

		$response = $controller->summary();

		$this->assertSame(404, $response->getStatus());
		// The summary service must never be reached for a denied read.
		$this->assertNotSame(99, $response->getData()['openTicketCount'] ?? null);
	}//end testSummaryReturns404WhenCallerCannotReadClient()

	/**
	 * A readable client returns the summary payload as-is.
	 *
	 * @return void
	 */
	public function testSummaryReturnsPayloadForReadableClient(): void {
		$controller = $this->buildController(
			clientId: 'client-1',
			foundClient: ['name' => 'Client', '@self' => ['id' => 'client-1']],
			uid: 'agent-1',
			summaryOrThrow: ['clientId' => 'client-1', 'openTicketCount' => 4],
		);

		$response = $controller->summary();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(4, $response->getData()['openTicketCount']);
	}//end testSummaryReturnsPayloadForReadableClient()

	/**
	 * Doelbinding: a successful access is logged with the acting user and the
	 * client id (design.md — reuses the app's existing logging facility).
	 *
	 * @return void
	 */
	public function testSuccessfulAccessIsLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('info')
			->with($this->stringContains('Customer 360 accessed'),
				$this->callback(
					static function (array $context): bool {
						return ($context['actor'] ?? null) === 'agent-1'
							&& ($context['clientId'] ?? null) === 'client-1'
							&& isset($context['time']) === true;
					}
				)
			);

		$controller = $this->buildController(
			clientId: 'client-1',
			foundClient: ['name' => 'Client'],
			uid: 'agent-1',
			summaryOrThrow: ['clientId' => 'client-1'],
			logger: $logger,
		);

		$controller->summary();
	}//end testSuccessfulAccessIsLogged()

	/**
	 * A denied read (404) must NOT be logged as an access — nothing was
	 * actually read.
	 *
	 * @return void
	 */
	public function testDeniedReadIsNotLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('info');

		$controller = $this->buildController(
			clientId: 'client-not-mine',
			foundClient: null,
			uid: 'agent-1',
			summaryOrThrow: [],
			logger: $logger,
		);

		$controller->summary();
	}//end testDeniedReadIsNotLogged()

	/**
	 * A service-level failure (e.g. OR outage mid-aggregation) is a 500, not
	 * a leaked stack trace, and is not logged as a successful access.
	 *
	 * @return void
	 */
	public function testSummaryReturns500OnServiceFailure(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('info');

		$controller = $this->buildController(
			clientId: 'client-1',
			foundClient: ['name' => 'Client'],
			uid: 'agent-1',
			summaryOrThrow: new \RuntimeException('boom'),
			logger: $logger,
		);

		$response = $controller->summary();

		$this->assertSame(500, $response->getStatus());
	}//end testSummaryReturns500OnServiceFailure()

	/**
	 * The read guard reaches OpenRegister with the register and schema in
	 * their own parameters, RBAC and multitenancy on.
	 *
	 * The old guard called `find($clientId, $register, $schema)` positionally:
	 * the register landed in `?array $_extend`, the TypeError was swallowed and
	 * every caller got 404, so every Customer 360 widget on the client page
	 * read "Request failed with status code 404" (pipelinq#805). This test fails
	 * on that code: the mock declares the real signature, so the positional call
	 * throws before the expectation is met.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/review-audit-fixes-b/specs/customer-360/spec.md#requirement-the-customer-360-summary-follows-the-clients-read-rights-req-raf-030
	 */
	public function testReadGuardPassesRegisterAndSchemaByName(): void {
		$calls = [];
		$controller = $this->buildController(
			clientId: 'client-1',
			foundClient: ['name' => 'Client', '@self' => ['id' => 'client-1']],
			uid: 'agent-1',
			summaryOrThrow: ['clientId' => 'client-1', 'openTicketCount' => 4],
			findCalls: $calls,
		);

		$response = $controller->summary();

		$this->assertSame(200, $response->getStatus());
		$this->assertCount(1, $calls);
		$this->assertSame('client-1', $calls[0][0]);
		$this->assertSame([], $calls[0][1], 'No extend directives.');
		$this->assertSame('pipelinq', $calls[0][3], 'Register in the register parameter.');
		$this->assertSame('client', $calls[0][4], 'Schema in the schema parameter.');
		$this->assertTrue($calls[0][5], 'RBAC stays on.');
		$this->assertTrue($calls[0][6], 'Multitenancy stays on.');
	}//end testReadGuardPassesRegisterAndSchemaByName()

	/**
	 * A caller OpenRegister will not let read the client gets 403, and the
	 * summary service is never reached. Access follows the client's own read
	 * rights, not a group list (Ruben, 7 October).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/review-audit-fixes-b/specs/customer-360/spec.md#requirement-the-customer-360-summary-follows-the-clients-read-rights-req-raf-030
	 */
	public function testCallerWithoutReadAccessIsForbidden(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('info');

		$controller = $this->buildController(
			clientId: 'client-1',
			foundClient: ['name' => 'Client', '@self' => ['id' => 'client-1']],
			uid: 'agent-2',
			summaryOrThrow: ['clientId' => 'client-1', 'openTicketCount' => 99],
			logger: $logger,
			findThrows: new NotAuthorizedException('no read'),
		);

		$response = $controller->summary();

		$this->assertSame(403, $response->getStatus());
		$this->assertNotSame(99, $response->getData()['openTicketCount'] ?? null);
	}//end testCallerWithoutReadAccessIsForbidden()

	/**
	 * An unexpected read failure fails closed as a 500, never as a grant.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/review-audit-fixes-b/specs/customer-360/spec.md#requirement-the-customer-360-summary-follows-the-clients-read-rights-req-raf-030
	 */
	public function testUnexpectedReadFailureIsAnError(): void {
		$controller = $this->buildController(
			clientId: 'client-1',
			foundClient: ['name' => 'Client', '@self' => ['id' => 'client-1']],
			uid: 'agent-2',
			summaryOrThrow: ['clientId' => 'client-1', 'openTicketCount' => 99],
			findThrows: new \RuntimeException('OpenRegister unreachable'),
		);

		$response = $controller->summary();

		$this->assertSame(500, $response->getStatus());
	}//end testUnexpectedReadFailureIsAnError()
}//end class
