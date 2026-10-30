<?php

/**
 * Unit tests for ClientHistorySummaryController: the read guard, the hidden
 * card without hermiq, and the summary on request.
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\Pipelinq\Controller\ClientHistorySummaryController;
use OCA\Pipelinq\Service\ClientHistorySummaryService;
use OCA\Pipelinq\Service\ClientReadAccess;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the history summary endpoint.
 *
 * @spec openspec/changes/clients-history-summary-and-risk/specs/client-insight/spec.md#requirement-a-clients-history-can-be-summarised-on-request-req-cin-002
 */
class ClientHistorySummaryControllerTest extends TestCase {

	/**
	 * Build the controller.
	 *
	 * @param ClientHistorySummaryService $history The summary double.
	 * @param bool $generate The `generate` query param.
	 * @param \Throwable|null $findThrows What the client read throws, if anything.
	 * @param string|null $uid The signed-in user, or null.
	 *
	 * @return ClientHistorySummaryController
	 */
	private function controller(
		ClientHistorySummaryService $history,
		bool $generate = false,
		?\Throwable $findThrows = null,
		?string $uid = 'agent-1',
	): ClientHistorySummaryController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($key === 'generate' && $generate === true) ? 'true' : $default
		);

		$userSession = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$userSession->method('getUser')->willReturn($user);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'register' => 'pipelinq',
				'client_schema' => 'client',
				default => $default,
			}
		);

		$entity = new ObjectEntity();
		$entity->setUuid('client-1');
		$entity->setObject(['name' => 'Client']);
		$objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			static function () use ($entity, $findThrows) {
				if ($findThrows !== null) {
					throw $findThrows;
				}

				return $entity;
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$logger = $this->createMock(LoggerInterface::class);

		return new ClientHistorySummaryController(
			$request,
			$userSession,
			new ClientReadAccess($container, $appConfig, $logger),
			$history,
			$logger
		);
	}//end controller()

	/**
	 * Without a signed-in user the answer is 401.
	 *
	 * @return void
	 */
	public function testAnonymousCallerIsRefused(): void {
		$history = $this->createMock(ClientHistorySummaryService::class);
		$history->expects($this->never())->method('summarise');

		$this->assertSame(401, $this->controller($history, true, null, null)->show('client-1')->getStatus());
	}//end testAnonymousCallerIsRefused()

	/**
	 * A caller who may not read the client gets 403 and nothing is summarised.
	 *
	 * @return void
	 */
	public function testFollowsTheClientsReadRights(): void {
		$history = $this->createMock(ClientHistorySummaryService::class);
		$history->expects($this->never())->method('summarise');
		$history->expects($this->never())->method('available');

		$response = $this->controller($history, true, new NotAuthorizedException('no'))->show('client-1');

		$this->assertSame(403, $response->getStatus());
	}//end testFollowsTheClientsReadRights()

	/**
	 * Without hermiq the answer is available false, so the card stays hidden.
	 *
	 * @return void
	 */
	public function testWithoutHermiqIsUnavailable(): void {
		$history = $this->createMock(ClientHistorySummaryService::class);
		$history->method('available')->willReturn(false);
		$history->expects($this->never())->method('summarise');

		$response = $this->controller($history, true)->show('client-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['available' => false], $response->getData());
	}//end testWithoutHermiqIsUnavailable()

	/**
	 * Without generate, nothing is sent to the model.
	 *
	 * @return void
	 */
	public function testOnlyGeneratesOnRequest(): void {
		$history = $this->createMock(ClientHistorySummaryService::class);
		$history->method('available')->willReturn(true);
		$history->expects($this->never())->method('summarise');

		$this->assertSame(['available' => true], $this->controller($history)->show('client-1')->getData());
	}//end testOnlyGeneratesOnRequest()

	/**
	 * Summarise returns the text, the records read and the time, as the viewer.
	 *
	 * @return void
	 */
	public function testReturnsTheSummaryAsTheViewer(): void {
		$history = $this->createMock(ClientHistorySummaryService::class);
		$history->method('available')->willReturn(true);
		$history->expects($this->once())->method('summarise')
			->with('client-1', 'agent-1')
			->willReturn(['summary' => 'Twelve calls, two open tickets.', 'recordsRead' => 14, 'generatedAt' => '2026-10-30T12:00:00+00:00']);

		$data = $this->controller($history, true)->show('client-1')->getData();

		$this->assertTrue($data['available']);
		$this->assertSame(14, $data['recordsRead']);
		$this->assertSame('Twelve calls, two open tickets.', $data['summary']);
	}//end testReturnsTheSummaryAsTheViewer()

	/**
	 * A model that does not answer is a 502, not an empty summary.
	 *
	 * @return void
	 */
	public function testModelFailureIsABadGateway(): void {
		$history = $this->createMock(ClientHistorySummaryService::class);
		$history->method('available')->willReturn(true);
		$history->method('summarise')->willThrowException(new \RuntimeException('Hermiq did not answer.'));

		$this->assertSame(502, $this->controller($history, true)->show('client-1')->getStatus());
	}//end testModelFailureIsABadGateway()
}//end class
