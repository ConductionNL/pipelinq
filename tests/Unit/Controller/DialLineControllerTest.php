<?php

/**
 * Contract tests for DialLineController.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\DialLineController;
use OCA\Pipelinq\Kernel\Contracts\DialLineReaderInterface;
use OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * `GET /api/cti/dial-line`: whose line is read, and who is refused.
 *
 * @covers \OCA\Pipelinq\Controller\DialLineController
 *
 * @spec openspec/changes/specify-capability-rows/tasks.md#task-36.1
 * @uses \OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck
 * @uses   \OCA\Pipelinq\Middleware\Support\ApiPathAliases
 * @uses   \OCA\Pipelinq\Middleware\Support\StandardProtocolPaths
 */
class DialLineControllerTest extends TestCase {
	/**
	 * Build the controller.
	 *
	 * @param DialLineReaderInterface $reader The reader.
	 * @param string|null $uid The signed-in uid, or null for anonymous.
	 * @param bool $privileged Whether that user is in a privileged group.
	 *
	 * @return DialLineController The controller.
	 */
	private function controller(DialLineReaderInterface $reader, ?string $uid, bool $privileged = true): DialLineController {
		$request = $this->createMock(IRequest::class);
		// A uid in the request must not be read: the line asked about is the session's.
		$request->method('getParam')->willReturn('agent-2');

		$user = null;
		if ($uid !== null) {
			$user = $this->createConfiguredMock(IUser::class, ['getUID' => $uid]);
		}

		return new DialLineController(
			$request,
			new CrmPrivilegeCheck($this->createConfiguredMock(IUserSession::class, ['getUser' => $user]), $this->createConfiguredMock(ObjectOwnerAccessPolicy::class, ['isPrivileged' => $privileged])),
			$reader,
		);
	}//end controller()

	/**
	 * The route names this controller and method.
	 *
	 * @return void
	 */
	public function testTheRouteNamesThisMethod(): void {
		$routes = (require dirname(__DIR__, 3) . '/appinfo/routes.php')['routes'];
		$found = array_values(array_filter($routes, static fn (array $route): bool => $route['url'] === '/api/cti/dial-line'));

		$this->assertCount(1, $found);
		$this->assertSame('dialLine#show', $found[0]['name']);
		$this->assertSame('GET', $found[0]['verb']);
		$this->assertTrue(method_exists(DialLineController::class, 'show'));
	}//end testTheRouteNamesThisMethod()

	/**
	 * The reader is asked about the signed-in agent, and its answer goes out unchanged.
	 *
	 * @return void
	 */
	public function testTheSignedInAgentsLineIsRead(): void {
		$answer = ['enabled' => true, 'extension' => '101', 'busy' => false];
		$reader = $this->createMock(DialLineReaderInterface::class);
		$reader->expects($this->once())->method('forAgent')->with('agent-1')->willReturn($answer);

		$response = $this->controller(reader: $reader, uid: 'agent-1')->show();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($answer, $response->getData());
	}//end testTheSignedInAgentsLineIsRead()

	/**
	 * An anonymous caller is refused with 401 and the reader is not asked.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefused(): void {
		$reader = $this->createMock(DialLineReaderInterface::class);
		$reader->expects($this->never())->method('forAgent');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(reader: $reader, uid: null)->show()->getStatus());
	}//end testAnAnonymousCallerIsRefused()

	/**
	 * A user outside the privileged groups is refused with 403 and the reader is not asked.
	 *
	 * @return void
	 */
	public function testAnUnprivilegedUserIsRefused(): void {
		$reader = $this->createMock(DialLineReaderInterface::class);
		$reader->expects($this->never())->method('forAgent');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller(reader: $reader, uid: 'visitor', privileged: false)->show()->getStatus());
	}//end testAnUnprivilegedUserIsRefused()
}//end class
