<?php

/**
 * Unit tests for PortalDelegationController.
 *
 * Delegation is a business-account feature of the portal: every action needs
 * a portal session, refuses consumer accounts, and acts only on the caller's
 * own account and tenant, never on ids taken from the request.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\PortalDelegationController;
use OCA\Pipelinq\Service\Portal\PortalDelegationService;
use OCA\Pipelinq\Service\Portal\PortalException;
use OCA\Pipelinq\Service\Portal\PortalRequestGuard;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for PortalDelegationController.
 *
 * @covers \OCA\Pipelinq\Controller\PortalDelegationController
 * @covers \OCA\Pipelinq\Controller\PortalApiController
 * @uses   \OCA\Pipelinq\Service\Portal\PortalException
 */
class PortalDelegationControllerTest extends TestCase {

	/**
	 * Mock request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock portal guard.
	 *
	 * @var PortalRequestGuard&MockObject
	 */
	private PortalRequestGuard&MockObject $guard;

	/**
	 * Mock delegation service.
	 *
	 * @var PortalDelegationService&MockObject
	 */
	private PortalDelegationService&MockObject $delegations;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Controller under test.
	 *
	 * @var PortalDelegationController
	 */
	private PortalDelegationController $controller;

	/**
	 * Wire the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(originalClassName: IRequest::class);
		$this->guard = $this->createMock(originalClassName: PortalRequestGuard::class);
		$this->delegations = $this->createMock(originalClassName: PortalDelegationService::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);

		$this->controller = new PortalDelegationController(
			request: $this->request,
			guard: $this->guard,
			logger: $this->logger,
			delegations: $this->delegations
		);
	}//end setUp()

	/**
	 * Authenticate the portal caller with the given account type.
	 *
	 * @param string $accountType The account type, b2b or b2c.
	 *
	 * @return void
	 */
	private function session(string $accountType): void {
		$this->guard->method('authenticate')->willReturn(
			[
				'accountId' => 'acct-1',
				'tenantId' => 'tenant-1',
				'account' => ['accountType' => $accountType],
			]
		);
	}//end session()

	/**
	 * Without a portal session the guard's refusal is returned and nothing is read.
	 *
	 * @return void
	 */
	public function testRequiresAPortalSession(): void {
		$this->guard->method('authenticate')->willThrowException(
			new PortalException(status: Http::STATUS_UNAUTHORIZED, errorCode: 'unauthenticated', message: 'Log in')
		);
		$this->delegations->expects(self::never())->method(self::anything());

		$response = $this->controller->index();

		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
		self::assertSame(expected: 'unauthenticated', actual: $response->getData()['errorCode']);
		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $this->controller->create()->getStatus());
		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $this->controller->destroy(id: 'd1')->getStatus());
	}//end testRequiresAPortalSession()

	/**
	 * A consumer account cannot list, grant or revoke delegations.
	 *
	 * @return void
	 */
	public function testRefusesAConsumerAccount(): void {
		$this->session(accountType: 'b2c');
		$this->delegations->expects(self::never())->method(self::anything());

		foreach ([$this->controller->index(), $this->controller->create(), $this->controller->destroy(id: 'd1')] as $response) {
			self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
			self::assertSame(expected: 'b2bOnly', actual: $response->getData()['errorCode']);
		}
	}//end testRefusesAConsumerAccount()

	/**
	 * A business account lists the delegations it granted.
	 *
	 * @return void
	 */
	public function testListsTheCallersOwnGrants(): void {
		$this->session(accountType: 'b2b');
		$this->delegations->expects(self::once())->method('listGrantedBy')->with('acct-1')->willReturn([['id' => 'd1']]);

		$response = $this->controller->index();

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(expected: ['delegations' => [['id' => 'd1']]], actual: $response->getData());
	}//end testListsTheCallersOwnGrants()

	/**
	 * A grant uses the session's account and tenant, with trimmed and normalised parameters.
	 *
	 * @return void
	 */
	public function testGrantsFromTheSessionAccount(): void {
		$this->session(accountType: 'b2b');
		$this->request->method('getParam')->willReturnMap(
			[
				['scopes', [], 'requests'],
				['validUntil', '', '  '],
				['granteeEmail', '', '  colleague@example.org '],
			]
		);
		$this->delegations->expects(self::once())->method('grant')
			->with('acct-1', 'tenant-1', 'colleague@example.org', [], null)
			->willReturn(['id' => 'd2']);

		$response = $this->controller->create();

		self::assertSame(expected: Http::STATUS_CREATED, actual: $response->getStatus());
		self::assertSame(expected: ['id' => 'd2'], actual: $response->getData());
	}//end testGrantsFromTheSessionAccount()

	/**
	 * A grant passes scopes and an end date through when they are given.
	 *
	 * @return void
	 */
	public function testGrantsWithScopesAndAnEndDate(): void {
		$this->session(accountType: 'b2b');
		$this->request->method('getParam')->willReturnMap(
			[
				['scopes', [], ['requests', 'documents']],
				['validUntil', '', '2027-01-01'],
				['granteeEmail', '', 'colleague@example.org'],
			]
		);
		$this->delegations->expects(self::once())->method('grant')
			->with('acct-1', 'tenant-1', 'colleague@example.org', ['requests', 'documents'], '2027-01-01')
			->willReturn(['id' => 'd3']);

		self::assertSame(expected: Http::STATUS_CREATED, actual: $this->controller->create()->getStatus());
	}//end testGrantsWithScopesAndAnEndDate()

	/**
	 * A revoke is scoped to the caller's account and tenant.
	 *
	 * @return void
	 */
	public function testRevokesWithinTheCallersAccount(): void {
		$this->session(accountType: 'b2b');
		$this->delegations->expects(self::once())->method('revoke')->with('d1', 'acct-1', 'tenant-1');

		$response = $this->controller->destroy(id: 'd1');

		self::assertSame(expected: ['status' => 'revoked'], actual: $response->getData());
	}//end testRevokesWithinTheCallersAccount()

	/**
	 * A service refusal keeps its status; an unexpected error is logged and hidden.
	 *
	 * @return void
	 */
	public function testServiceErrors(): void {
		$this->session(accountType: 'b2b');
		$this->delegations->method('revoke')->willThrowException(
			new PortalException(status: Http::STATUS_NOT_FOUND, errorCode: 'notFound', message: 'Onbekend')
		);
		$this->delegations->method('listGrantedBy')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects(self::once())->method('error')
			->with('Pipelinq portal: unhandled error', ['exception' => 'db down']);

		self::assertSame(expected: Http::STATUS_NOT_FOUND, actual: $this->controller->destroy(id: 'other')->getStatus());

		$failed = $this->controller->index();
		self::assertSame(expected: Http::STATUS_INTERNAL_SERVER_ERROR, actual: $failed->getStatus());
		self::assertSame(expected: 'serverError', actual: $failed->getData()['errorCode']);
	}//end testServiceErrors()
}//end class
