<?php

/**
 * SlaPolicyController: the justification gate, configuration, saving and the audit entry.
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

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Controller\SlaPolicyController;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A change without a justification is refused before anything is read; a
 * saved change is audited with actor, justification, before and after.
 *
 * @covers \OCA\Pipelinq\Controller\SlaPolicyController
 */
class SlaPolicyControllerTest extends TestCase {

	/**
	 * The object store.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objects;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The audit entries logged at info.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $audited = [];

	/**
	 * Build the store and a logger that records audit entries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->logger->method('info')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->audited[] = ['message' => $message] + $context;
			}
		);
	}//end setUp()

	/**
	 * A missing or blank justification is a 400 on create and update, and nothing is saved.
	 *
	 * @return void
	 */
	public function testJustificationIsRequired(): void {
		$this->objects->expects($this->never())->method('saveObject');

		foreach ([['name' => 'Gold'], ['name' => 'Gold', 'justification' => '   ']] as $params) {
			$controller = $this->controller(params: $params);
			foreach ([$controller->create(), $controller->update(id: 'p1')] as $response) {
				$this->assertSame(400, $response->getStatus());
				$this->assertSame(
					['error' => 'justificationRequired', 'message' => 'Justification is required to modify SLA policies'],
					$response->getData()
				);
			}
		}

		$this->assertSame([], $this->audited);
	}//end testJustificationIsRequired()

	/**
	 * Without a register or policy schema both writes are a 503.
	 *
	 * @return void
	 */
	public function testUnconfiguredRegisterIs503(): void {
		$this->objects->expects($this->never())->method('saveObject');

		foreach ([['sla_register' => 'sla'], ['sla_policy_schema' => 'policy']] as $config) {
			$controller = $this->controller(params: ['justification' => 'why'], config: $config);
			foreach ([$controller->create(), $controller->update(id: 'p1')] as $response) {
				$this->assertSame(503, $response->getStatus());
				$this->assertSame(['error' => 'slaRegisterNotConfigured'], $response->getData());
			}
		}
	}//end testUnconfiguredRegisterIs503()

	/**
	 * Create saves the payload without the route, returns the serialised object and audits it.
	 *
	 * @return void
	 */
	public function testCreateSavesAndAudits(): void {
		$saved = $this->createMock(ObjectEntityInterface::class);
		$saved->method('jsonSerialize')->willReturn(['id' => 'p9', 'name' => 'Gold']);
		$this->objects->expects($this->once())->method('saveObject')
			->with(['name' => 'Gold', 'justification' => 'contract'], [], 'sla', 'policy')
			->willReturn($saved);

		$response = $this->controller(params: ['name' => 'Gold', 'justification' => 'contract', '_route' => 'x'], uid: 'admin')->create();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['id' => 'p9', 'name' => 'Gold'], $response->getData());
		$this->assertCount(1, $this->audited);
		$entry = $this->audited[0];
		$this->assertSame('SlaPolicyController: policy audited', $entry['message']);
		$this->assertSame('create', $entry['action']);
		$this->assertSame('admin', $entry['actor']);
		$this->assertSame('contract', $entry['justification']);
		$this->assertNull($entry['before']);
		$this->assertSame(['name' => 'Gold', 'justification' => 'contract'], $entry['after']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $entry['timestamp']);
	}//end testCreateSavesAndAudits()

	/**
	 * A failing save is logged and answered with a 500, and nothing is audited.
	 *
	 * @return void
	 */
	public function testCreateFailureIs500(): void {
		$this->objects->method('saveObject')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('error')->with('SlaPolicyController: create failed', ['error' => 'db down']);

		$response = $this->controller(params: ['justification' => 'why'])->create();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'saveFailed'], $response->getData());
		$this->assertSame([], $this->audited);
	}//end testCreateFailureIs500()

	/**
	 * Updating a policy that cannot be read saves it under its id, audited by the system with no before state.
	 *
	 * @return void
	 */
	public function testUpdateOfAnUnreadablePolicyStillSaves(): void {
		$this->objects->method('find')->willThrowException(new RuntimeException('gone'));
		$this->logger->expects($this->once())->method('debug')->with(
			'SlaPolicyController: existing policy not found, treating as create',
			['id' => 'p1']
		);
		$saved = $this->createMock(ObjectEntityInterface::class);
		$saved->method('jsonSerialize')->willReturn('not an array');
		$this->objects->expects($this->once())->method('saveObject')
			->with(['name' => 'Gold', 'justification' => 'why'], [], 'sla', 'policy', 'p1')
			->willReturn($saved);

		$response = $this->controller(params: ['name' => 'Gold', 'justification' => 'why', 'id' => 'other', '_route' => 'r'])->update(id: 'p1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame([], $response->getData());
		$this->assertSame('update', $this->audited[0]['action']);
		$this->assertSame('system', $this->audited[0]['actor']);
		$this->assertNull($this->audited[0]['before']);
	}//end testUpdateOfAnUnreadablePolicyStillSaves()

	/**
	 * A failing update save is logged with its id and answered with a 500.
	 *
	 * @return void
	 */
	public function testUpdateFailureIs500(): void {
		$this->objects->method('find')->willReturn(null);
		$this->objects->method('saveObject')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('error')->with('SlaPolicyController: update failed', ['error' => 'db down', 'id' => 'p1']);

		$response = $this->controller(params: ['justification' => 'why'])->update(id: 'p1');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'saveFailed'], $response->getData());
	}//end testUpdateFailureIs500()

	/**
	 * Build the controller.
	 *
	 * @param array<string, mixed>  $params The request params.
	 * @param array<string, string> $config The app config; defaults to a configured register and schema.
	 * @param string|null           $uid    The signed-in user, or null for none.
	 *
	 * @return SlaPolicyController
	 */
	private function controller(array $params, ?array $config = null, ?string $uid = null): SlaPolicyController {
		$config = ($config ?? ['sla_register' => 'sla', 'sla_policy_schema' => 'policy']);
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(ObjectService::class)->willReturn($this->objects);
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new SlaPolicyController(
			request: $request,
			container: $container,
			appConfig: $appConfig,
			userSession: $session,
			logger: $this->logger,
		);
	}//end controller()
}//end class
