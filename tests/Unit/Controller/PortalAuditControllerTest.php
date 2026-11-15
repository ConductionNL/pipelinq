<?php

/**
 * Unit tests for PortalAuditController.
 *
 * A portal customer reads only their own audit trail: the account id comes
 * from the authenticated session, never from the request. The tests pin that
 * scoping, the pagination clamps (page >= 1, 1 <= perPage <= 100), and the
 * error answers when the session is refused or the read fails.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\PortalApiController;
use OCA\Pipelinq\Controller\PortalAuditController;
use OCA\Pipelinq\Service\Portal\PortalAuditService;
use OCA\Pipelinq\Service\Portal\PortalException;
use OCA\Pipelinq\Service\Portal\PortalRequestGuard;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for PortalAuditController.
 */
#[CoversClass(PortalAuditController::class)]
#[UsesClass(PortalApiController::class)]
#[UsesClass(PortalException::class)]
class PortalAuditControllerTest extends TestCase {

	/**
	 * The request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * The portal guard.
	 *
	 * @var PortalRequestGuard&MockObject
	 */
	private PortalRequestGuard&MockObject $guard;

	/**
	 * The audit service.
	 *
	 * @var PortalAuditService&MockObject
	 */
	private PortalAuditService&MockObject $audit;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->guard = $this->createMock(PortalRequestGuard::class);
		$this->audit = $this->createMock(PortalAuditService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The controller under test.
	 *
	 * @return PortalAuditController
	 */
	private function controller(): PortalAuditController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $name, mixed $default = null): mixed => ($this->params[$name] ?? $default)
		);

		return new PortalAuditController($request, $this->guard, $this->logger, $this->audit);
	}//end controller()

	/**
	 * Events numbered 1..$count.
	 *
	 * @param int $count The number of events.
	 *
	 * @return array<int, array<string, int>>
	 */
	private function events(int $count): array {
		return array_map(fn (int $n): array => ['n' => $n], range(1, $count));
	}//end events()

	/**
	 * The trail is read for the session's own account, first page of 25 by default.
	 *
	 * @return void
	 */
	public function testReadsTheSessionAccountsOwnTrail(): void {
		$this->params = ['accountId' => 'someone-else'];
		$this->guard->method('authenticate')->willReturn(['accountId' => 'acct-1']);
		$this->audit->expects($this->once())->method('getForAccount')->with('acct-1')->willReturn($this->events(30));

		$response = $this->controller()->index();

		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertSame([30, 1, 25], [$data['total'], $data['page'], $data['perPage']]);
		$this->assertSame($this->events(25), $data['items']);
	}//end testReadsTheSessionAccountsOwnTrail()

	/**
	 * A later page slices from its offset.
	 *
	 * @return void
	 */
	public function testALaterPageSlicesFromItsOffset(): void {
		$this->params = ['page' => '2', 'perPage' => '10'];
		$this->guard->method('authenticate')->willReturn(['accountId' => 'acct-1']);
		$this->audit->method('getForAccount')->willReturn($this->events(25));

		$data = $this->controller()->index()->getData();

		$this->assertSame([25, 2, 10], [$data['total'], $data['page'], $data['perPage']]);
		$this->assertSame([['n' => 11], ['n' => 12], ['n' => 13], ['n' => 14], ['n' => 15], ['n' => 16], ['n' => 17], ['n' => 18], ['n' => 19], ['n' => 20]], $data['items']);
	}//end testALaterPageSlicesFromItsOffset()

	/**
	 * Out-of-range and non-numeric paging is clamped or defaulted.
	 *
	 * @return void
	 */
	public function testPagingIsClamped(): void {
		$this->guard->method('authenticate')->willReturn(['accountId' => 'acct-1']);
		$this->audit->method('getForAccount')->willReturn($this->events(150));

		$this->params = ['page' => '0', 'perPage' => '500'];
		$high = $this->controller()->index()->getData();
		$this->params = ['page' => '-3', 'perPage' => '0'];
		$low = $this->controller()->index()->getData();
		$this->params = ['page' => 'two', 'perPage' => ['x']];
		$junk = $this->controller()->index()->getData();

		$this->assertSame([1, 100, 100], [$high['page'], $high['perPage'], count($high['items'])]);
		$this->assertSame([1, 1, [['n' => 1]]], [$low['page'], $low['perPage'], $low['items']]);
		$this->assertSame([1, 25], [$junk['page'], $junk['perPage']]);
	}//end testPagingIsClamped()

	/**
	 * A page past the end is empty but still reports the total.
	 *
	 * @return void
	 */
	public function testAPagePastTheEndIsEmpty(): void {
		$this->params = ['page' => '5'];
		$this->guard->method('authenticate')->willReturn(['accountId' => 'acct-1']);
		$this->audit->method('getForAccount')->willReturn($this->events(3));

		$data = $this->controller()->index()->getData();

		$this->assertSame(3, $data['total']);
		$this->assertSame([], $data['items']);
	}//end testAPagePastTheEndIsEmpty()

	/**
	 * A refused session answers with the guard's own status and body, and reads nothing.
	 *
	 * @return void
	 */
	public function testARefusedSessionIsAnsweredByTheGuard(): void {
		$this->guard->method('authenticate')->willThrowException(new PortalException(401, 'unauthenticated', 'Log opnieuw in.'));
		$this->audit->expects($this->never())->method('getForAccount');

		$response = $this->controller()->index();

		$this->assertSame(401, $response->getStatus());
		$this->assertSame(['errorCode' => 'unauthenticated', 'message' => 'Log opnieuw in.'], $response->getData());
	}//end testARefusedSessionIsAnsweredByTheGuard()

	/**
	 * An unexpected failure is a generic 500 and is logged.
	 *
	 * @return void
	 */
	public function testAnUnexpectedFailureIsAGeneric500(): void {
		$this->guard->method('authenticate')->willReturn(['accountId' => 'acct-1']);
		$this->audit->method('getForAccount')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('error')->with('Pipelinq portal: unhandled error', ['exception' => 'db down']);

		$response = $this->controller()->index();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['errorCode' => 'serverError', 'message' => 'Er is een fout opgetreden.'], $response->getData());
	}//end testAnUnexpectedFailureIsAGeneric500()
}//end class
