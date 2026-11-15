<?php

/**
 * Unit tests for SlaPolicyController::update().
 *
 * The update path reads the existing policy before saving, so the audit entry
 * records what the policy looked like before the change. That read sits inside
 * a catch-all, so a call OpenRegister rejects does not fail the request: it
 * quietly audits every update as if it had no previous state.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Controller\SlaPolicyController;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the existing-policy read in SlaPolicyController::update().
 */
class SlaPolicyControllerUpdateTest extends TestCase {
	/**
	 * The existing policy is read with OpenRegister's find() by id, in the
	 * configured register and schema, and that state reaches the audit entry.
	 *
	 * @return void
	 */
	public function testUpdateReadsTheExistingPolicyById(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['name' => 'Gold', 'justification' => 'contract renewal']);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'sla_register' => 'sla',
				'sla_policy_schema' => 'policy',
				default => $default,
			}
		);

		$existing = $this->createMock(ObjectEntityInterface::class);
		$existing->method('getObject')->willReturn(['name' => 'Silver']);

		$saved = $this->createMock(ObjectEntityInterface::class);
		$saved->method('getObject')->willReturn(['id' => 'pol-1', 'name' => 'Gold']);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->expects($this->once())
			->method('find')
			->with('pol-1', [], false, 'sla', 'policy')
			->willReturn($existing);
		$objectService->method('saveObject')->willReturn($saved);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(ObjectService::class)->willReturn($objectService);

		$audited = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			static function (string $message, array $context = []) use (&$audited): void {
				$audited[] = $context;
			}
		);

		$controller = new SlaPolicyController(
			request: $request,
			container: $container,
			appConfig: $appConfig,
			userSession: $this->createMock(IUserSession::class),
			logger: $logger,
		);

		$response = $controller->update(id: 'pol-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertCount(1, $audited);
		$this->assertSame(['name' => 'Silver'], $audited[0]['before']);
	}//end testUpdateReadsTheExistingPolicyById()
}//end class
