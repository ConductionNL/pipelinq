<?php

/**
 * Tests for LoyaltyPaymentController: the cash register spends loyalty points.
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
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\LoyaltyPaymentController;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\LoyaltyPaymentService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Points as a payment part, through the API.
 */
class LoyaltyPaymentControllerTest extends TestCase {

	/**
	 * Build the controller.
	 *
	 * @param LoyaltyPaymentService $service    The payment service.
	 * @param bool                  $privileged Whether the user may use loyalty.
	 * @param array<string, mixed>  $params     Request params.
	 *
	 * @return LoyaltyPaymentController
	 */
	private function controller(LoyaltyPaymentService $service, bool $privileged, array $params): LoyaltyPaymentController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('pieter');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$policy = $this->createMock(originalClassName: ObjectOwnerAccessPolicy::class);
		$policy->method('isPrivileged')->willReturn($privileged);
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new LoyaltyPaymentController(
			appName: 'pipelinq',
			request: $request,
			service: $service,
			policy: $policy,
			userSession: $session,
			l10n: $l10n
		);

	}//end controller()

	/**
	 * A cashier spends points against a sale and gets the amount back.
	 *
	 * @return void
	 */
	public function testPaySpendsThePoints(): void {
		$service = $this->createMock(originalClassName: LoyaltyPaymentService::class);
		$service->expects($this->once())
			->method('payWithPoints')
			->with('acc-1', 200, 'tx-1')
			->willReturn(['points' => 200, 'amount' => 10.0, 'currentBalance' => 140]);

		$response = $this->controller(service: $service, privileged: true, params: ['points' => '200', 'posTransactionId' => 'tx-1'])
			->pay(accountId: 'acc-1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: 10.0, actual: $response->getData()['amount']);

	}//end testPaySpendsThePoints()

	/**
	 * Someone without the loyalty capability is refused before anything is spent.
	 *
	 * @return void
	 */
	public function testPayIsRefusedWithoutTheCapability(): void {
		$service = $this->createMock(originalClassName: LoyaltyPaymentService::class);
		$service->expects($this->never())->method('payWithPoints');

		$response = $this->controller(service: $service, privileged: false, params: ['points' => '200', 'posTransactionId' => 'tx-1'])
			->pay(accountId: 'acc-1');

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());

	}//end testPayIsRefusedWithoutTheCapability()

	/**
	 * A refused spend says why.
	 *
	 * @return void
	 */
	public function testPaySaysWhyItWasRefused(): void {
		$service = $this->createMock(originalClassName: LoyaltyPaymentService::class);
		$service->method('payWithPoints')->willThrowException(new RuntimeException('Insufficient balance.'));

		$response = $this->controller(service: $service, privileged: true, params: ['points' => '900', 'posTransactionId' => 'tx-1'])
			->pay(accountId: 'acc-1');

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: 'Insufficient balance.', actual: $response->getData()['error']);

	}//end testPaySaysWhyItWasRefused()
}//end class
