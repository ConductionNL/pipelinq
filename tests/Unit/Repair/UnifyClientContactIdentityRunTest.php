<?php

/**
 * Unit tests for UnifyClientContactIdentity::run().
 *
 * Verifies the guards in front of the identity pass (OpenRegister, register,
 * ObjectService) and the summary the step reports and logs after a pass.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Repair\UnifyClientContactIdentity;
use OCA\Pipelinq\Service\ContactVcardService;
use OCA\Pipelinq\Service\TicketService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The repair step around the identity pass.
 */
final class UnifyClientContactIdentityRunTest extends TestCase {

	/**
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $appManager;

	/**
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * @var IOutput&MockObject
	 */
	private IOutput&MockObject $output;

	/**
	 * Configured app values.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Set up mocks with OpenRegister installed and a register configured.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appConfig  = $this->createMock(IAppConfig::class);
		$this->container  = $this->createMock(ContainerInterface::class);
		$this->logger     = $this->createMock(LoggerInterface::class);
		$this->output     = $this->createMock(IOutput::class);
		$this->config     = ['register' => 'reg'];
		$this->appManager->method('getInstalledApps')->willReturn(['openregister']);
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($app === Application::APP_ID ? ($this->config[$key] ?? $default) : $default)
		);
	}//end setUp()

	/**
	 * The step.
	 *
	 * @return UnifyClientContactIdentity
	 */
	private function step(): UnifyClientContactIdentity {
		$tickets = $this->createMock(TicketService::class);
		$tickets->method('getSchemaId')->willReturn('');

		return new UnifyClientContactIdentity(
			$this->appManager,
			$this->appConfig,
			$this->createMock(ContactVcardService::class),
			$this->container,
			$tickets,
			$this->logger
		);
	}//end step()

	/**
	 * The step names itself.
	 *
	 * @return void
	 */
	public function testName(): void {
		$this->assertSame(
			'Unify pipelinq client/contact identity on Nextcloud contacts (idempotent, non-destructive)',
			$this->step()->getName()
		);
	}//end testName()

	/**
	 * Without OpenRegister the step warns and resolves nothing.
	 *
	 * @return void
	 */
	public function testSkipsWithoutOpenRegister(): void {
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appManager->method('getInstalledApps')->willReturn(['files']);
		$this->container->expects($this->never())->method('get');
		$this->output->expects($this->once())->method('warning')
			->with('OpenRegister not installed — skipping client/contact identity unification.');
		$this->output->expects($this->never())->method('info');

		$this->step()->run($this->output);
	}//end testSkipsWithoutOpenRegister()

	/**
	 * Without a register the step warns and resolves nothing.
	 *
	 * @return void
	 */
	public function testSkipsWithoutARegister(): void {
		$this->config = [];
		$this->container->expects($this->never())->method('get');
		$this->output->expects($this->once())->method('warning')
			->with('Pipelinq register not configured — skipping client/contact identity unification.');

		$this->step()->run($this->output);
	}//end testSkipsWithoutARegister()

	/**
	 * An unresolvable ObjectService is logged, and the step warns.
	 *
	 * @return void
	 */
	public function testSkipsWhenTheObjectServiceCannotBeResolved(): void {
		$this->container->method('get')->with('OCA\OpenRegister\Service\ObjectService')
			->willThrowException(new \RuntimeException('no container entry'));
		$this->logger->expects($this->once())->method('warning')
			->with('Identity unify: OpenRegister ObjectService not resolvable', ['exception' => 'no container entry']);
		$this->output->expects($this->once())->method('warning')
			->with('OpenRegister ObjectService unavailable — skipping client/contact identity unification.');
		$this->logger->expects($this->never())->method('info');

		$this->step()->run($this->output);
	}//end testSkipsWhenTheObjectServiceCannotBeResolved()

	/**
	 * A pass with no schemas configured reports and logs an all-zero summary.
	 *
	 * @return void
	 */
	public function testReportsAndLogsTheSummary(): void {
		$this->container->method('get')->willReturn(new \stdClass());
		$this->output->expects($this->never())->method('warning');
		$this->output->expects($this->once())->method('info')->with(
			'Client/contact identity unification: 0 clients linked, 0 contacts linked, 0 already-linked skipped, '
			. '0 contactmomenten re-keyed, 0 unresolved. No identity field, contactmoment.client reference, '
			. 'or existing contactsUid was deleted or overwritten.'
		);
		$this->logger->expects($this->once())->method('info')->with(
			'Client/contact identity unification completed',
			['clientsLinked' => 0, 'contactsLinked' => 0, 'skipped' => 0, 'unresolved' => 0, 'momentsRekeyed' => 0, 'auditMap' => []]
		);

		$this->step()->run($this->output);
	}//end testReportsAndLogsTheSummary()
}//end class
