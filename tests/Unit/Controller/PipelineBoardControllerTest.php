<?php

/**
 * Unit tests for PipelineBoardController: the pipeline board's cards endpoint.
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
 * @spec openspec/specs/pipeline/spec.md#requirement-kanban-board-view-mvp
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\PipelineBoardController;
use OCA\Pipelinq\Service\PipelineBoardService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Asserts the /api/pipeline-items statuses and envelopes.
 */
class PipelineBoardControllerTest extends TestCase {

	/**
	 * Build the controller.
	 *
	 * @param string|null          $pipeline The `pipeline` parameter.
	 * @param PipelineBoardService $service  The board reader.
	 * @param bool                 $signedIn Whether a user is signed in.
	 *
	 * @return PipelineBoardController
	 */
	private function controller(?string $pipeline, PipelineBoardService $service, bool $signedIn = true): PipelineBoardController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => $key === 'pipeline' ? ($pipeline ?? $default) : $default
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $this->createMock(IUser::class) : null);

		return new PipelineBoardController(
			request: $request,
			boardService: $service,
			userSession: $session,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end controller()

	/**
	 * The cards come back as the service gives them.
	 *
	 * @return void
	 */
	public function testItReturnsTheCards(): void {
		$service = $this->createMock(PipelineBoardService::class);
		$service->expects($this->once())->method('items')->with('p1', [])->willReturn(['results' => [['id' => 'l1']], 'total' => 1, 'pipeline' => 'p1']);

		$response = $this->controller(pipeline: 'p1', service: $service)->items();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1, $response->getData()['total']);
	}//end testItReturnsTheCards()

	/**
	 * An unreadable pipeline is not found, and a failure says nothing about
	 * its cause.
	 *
	 * @return void
	 */
	public function testErrorsAreStatusesWithStaticMessages(): void {
		$service = $this->createMock(PipelineBoardService::class);

		$missing = $this->createMock(PipelineBoardService::class);
		$missing->method('items')->willReturn(null);
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller(pipeline: 'p9', service: $missing)->items()->getStatus());

		$broken = $this->createMock(PipelineBoardService::class);
		$broken->method('items')->willThrowException(new \RuntimeException('secret detail'));
		$response = $this->controller(pipeline: 'p1', service: $broken)->items();
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['message' => 'Pipeline board unavailable'], $response->getData());

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(pipeline: 'p1', service: $service, signedIn: false)->items()->getStatus());
	}//end testErrorsAreStatusesWithStaticMessages()
}//end class
