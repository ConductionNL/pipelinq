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
use OCA\Pipelinq\Controller\Customer360Controller;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
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
	 *
	 * @return Customer360Controller
	 */
	private function buildController(
		?string $clientId,
		mixed $foundClient,
		?string $uid,
		mixed $summaryOrThrow,
		?LoggerInterface $logger = null,
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
		$objectService->method('find')->willReturn($foundEntity);

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
			$this->createConfiguredMock(ObjectOwnerAccessPolicy::class, ['isPrivileged' => true, 'mayAccess' => true]),
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
	 * IDOR guard: when the client does not resolve through the RBAC-scoped
	 * ObjectService::find() (hidden, wrong tenant, or genuinely absent), the
	 * endpoint 404s and never calls the summary service.
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
	 * INCOMPLETE, DELIBERATELY — see ConductionNL/pipelinq#805. This endpoint has
	 * never returned data to anybody: `canReadClient()` calls
	 * `ObjectService::find($clientId, $register, $schema)` POSITIONALLY, so
	 * `$register` lands in `?array $_extend`, the TypeError is swallowed by the
	 * method's own `catch (Throwable)`, and the guard denies unconditionally.
	 *
	 * The one-line repair is NOT ours to make: the predicate behind the guard is
	 * `return $object !== null` — an EXISTENCE test, on a `client` schema with no
	 * owner field and no authorization block — so repairing the call converts an
	 * endpoint that denies everyone into one that grants every authenticated
	 * caller a full customer-360 read of any clientId. #805 records this
	 * explicitly: the crash is load-bearing, and what "may read this client"
	 * means is an open product decision.
	 *
	 * The assertions below are left intact and unedited: they are the spec this
	 * endpoint must meet once #805 lands. Delete the marker then, not before.
	 * Retargeting them at the current 404 would convert a product gap into a
	 * fixed-looking test.
	 *
	 * @return void
	 */
	public function testSummaryReturnsPayloadForReadableClient(): void {
		$this->markTestIncomplete(
			'pipelinq#805: Customer 360 read guard is a dead fail-closed call; '
			. 'repairing it without an authorisation model is an IDOR.'
		);

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
	 * INCOMPLETE, DELIBERATELY — see pipelinq#805 and the note on
	 * testSummaryReturnsPayloadForReadableClient(). There is no successful access
	 * to log while the read guard denies every caller. Assertions left intact.
	 *
	 * @return void
	 */
	public function testSuccessfulAccessIsLogged(): void {
		$this->markTestIncomplete(
			'pipelinq#805: no access can succeed while the read guard is dead.'
		);

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
	 * INCOMPLETE, DELIBERATELY — see pipelinq#805 and the note on
	 * testSummaryReturnsPayloadForReadableClient(). The summary service is never
	 * reached, so its failure mode cannot be exercised. Assertions left intact.
	 *
	 * @return void
	 */
	public function testSummaryReturns500OnServiceFailure(): void {
		$this->markTestIncomplete(
			'pipelinq#805: the summary service is unreachable behind the dead guard.'
		);

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
	 * TRIPWIRE for pipelinq#805 — do not "fix" this test.
	 *
	 * Pins the endpoint's CURRENT, deliberate state: even a privileged caller
	 * asking for a client that exists and that the ObjectService double resolves
	 * is answered 404, because `canReadClient()`'s positional `find()` call
	 * raises a TypeError that its own `catch (Throwable)` swallows.
	 *
	 * This is NOT an endorsement of that behaviour. It exists so that repairing
	 * the call — which is a one-line change any drive-by cleanup would make —
	 * turns this test RED and forces the author to read #805 first. Behind the
	 * guard sits `return $object !== null`, an existence test on a schema with
	 * no owner field, so the repair alone would grant every authenticated caller
	 * a full customer-360 read of any clientId.
	 *
	 * When #805 lands an authorisation model: delete this test and remove the
	 * markTestIncomplete() calls above.
	 *
	 * @return void
	 */
	public function testReadGuardDeniesEvenAResolvableClientPendingIssue805(): void {
		$controller = $this->buildController(
			clientId: 'client-1',
			foundClient: ['name' => 'Client', '@self' => ['id' => 'client-1']],
			uid: 'agent-1',
			summaryOrThrow: ['clientId' => 'client-1', 'openTicketCount' => 4],
		);

		$response = $controller->summary();

		$this->assertSame(
			404,
			$response->getStatus(),
			'pipelinq#805: the Customer 360 read guard is a dead fail-closed call. '
			. 'If this is now 200, the positional find() was repaired without an '
			. 'authorisation model — read #805 before going further.'
		);
	}//end testReadGuardDeniesEvenAResolvableClientPendingIssue805()
}//end class
