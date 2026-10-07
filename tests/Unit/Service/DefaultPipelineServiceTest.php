<?php

/**
 * Unit tests for DefaultPipelineService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\DefaultPipelineService;
use OCA\Pipelinq\Service\PipelineStageData;
use OCA\Pipelinq\Service\SystemServiceAccount;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for DefaultPipelineService.
 */
class DefaultPipelineServiceTest extends TestCase {
	/**
	 * Test createDefaultPipelines skips when register not configured.
	 *
	 * @return void
	 */
	public function testSkipsWhenNotConfigured(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		$stageData = new PipelineStageData();
		$logger = $this->createMock(LoggerInterface::class);

		$logger->expects($this->once())->method('warning');

		$service = new DefaultPipelineService($appConfig, $stageData, $logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
			systemAccount: $this->systemAccount(session: $this->createMock(IUserSession::class)),
		);
		$service->createDefaultPipelines();
	}//end testSkipsWhenNotConfigured()

	/**
	 * Test createDefaultPipelines catches exceptions.
	 *
	 * The failure is raised by the OpenRegister call itself. It used to be
	 * raised by the container lookup, but the service takes an injected
	 * ObjectServiceInterface now (ADR-083/084), so a container that refuses to
	 * resolve never reaches this method — the throw moved to the only place
	 * that can still fail at runtime.
	 *
	 * @return void
	 */
	public function testCatchesExceptions(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('1');

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willThrowException(new \RuntimeException('OpenRegister unreachable'));

		$stageData = new PipelineStageData();
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$service = new DefaultPipelineService($appConfig, $stageData, $logger,
			objectService: $objectService,
			systemAccount: $this->systemAccount(session: $this->createMock(IUserSession::class)),
		);

		// The point of the test: the throw is swallowed, not propagated.
		$service->createDefaultPipelines();
	}//end testCatchesExceptions()
	/**
	 * A real SystemServiceAccount over the given session; its account exists.
	 *
	 * @param IUserSession $session The session mock.
	 * @param IUser|null   $account The system account, when the caller wants it.
	 *
	 * @return SystemServiceAccount
	 */
	private function systemAccount(IUserSession $session, ?IUser $account=null): SystemServiceAccount {
		$account ??= $this->createMock(IUser::class);
		$users     = $this->createMock(IUserManager::class);
		$users->method('get')->with(SystemServiceAccount::USER_ID)->willReturn($account);

		return new SystemServiceAccount(
			userManager: $users,
			userSession: $session,
			random: $this->createMock(ISecureRandom::class),
		);
	}//end systemAccount()

	/**
	 * With nobody signed in (a repair step), the default pipelines are written
	 * as the system account, and the previous (empty) user is restored.
	 *
	 * Review R5: `occ upgrade` ran the repair as Anonymous and OpenRegister
	 * refused the write, so a fresh install had no default pipelines.
	 *
	 * @return void
	 */
	public function testRepairWithoutAUserWritesAsTheSystemAccount(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default) => match ($key) {
				'register' => '20',
				'pipeline_schema' => '31',
				'currency' => 'USD',
				default => $default,
			}
		);

		$system  = $this->createMock(IUser::class);
		$active  = null;
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(function () use (&$active) {
			return $active;
		});
		$session->method('setVolatileActiveUser')->willReturnCallback(function (?IUser $user) use (&$active): void {
			$active = $user;
		});

		$writers       = [];
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturn([]);
		$objectService->method('saveObject')->willReturnCallback(function (...$args) use (&$writers, &$active) {
			$writers[] = $active;
			return $this->createMock(\OCA\OpenRegister\Contract\ObjectEntityInterface::class);
		});

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');

		$service = new DefaultPipelineService($appConfig, new PipelineStageData(), $logger,
			objectService: $objectService,
			systemAccount: $this->systemAccount(session: $session, account: $system),
		);
		$service->createDefaultPipelines();

		$this->assertCount(2, $writers);
		$this->assertSame($system, $writers[0]);
		$this->assertSame($system, $writers[1]);
		$this->assertNull($active, 'the previous (empty) user is restored');
	}//end testRepairWithoutAUserWritesAsTheSystemAccount()

	/**
	 * A signed-in admin (the setup action) writes as themself.
	 *
	 * @return void
	 */
	public function testASignedInUserWritesAsThemself(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default) => match ($key) {
				'register' => '20',
				'pipeline_schema' => '31',
				default => $default,
			}
		);

		$admin   = $this->createMock(IUser::class);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($admin);
		$session->expects($this->never())->method('setVolatileActiveUser');

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturn([]);
		$objectService->expects($this->exactly(2))->method('saveObject')
			->willReturn($this->createMock(\OCA\OpenRegister\Contract\ObjectEntityInterface::class));

		$service = new DefaultPipelineService($appConfig, new PipelineStageData(), $this->createMock(LoggerInterface::class),
			objectService: $objectService,
			systemAccount: $this->systemAccount(session: $session),
		);
		$service->createDefaultPipelines();
	}//end testASignedInUserWritesAsThemself()
}//end class
