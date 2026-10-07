<?php

/**
 * Tests for OrphanedLeadRepairService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/review-audit-fixes-b/specs/lead-management/spec.md#requirement-a-repair-step-moves-leads-off-a-deleted-pipeline-req-raf-011
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Service\OrphanedLeadPlacer;
use OCA\Pipelinq\Service\OrphanedLeadRepairService;
use OCA\Pipelinq\Service\SystemServiceAccount;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Leads on a deleted pipeline move to the default pipeline, as the system
 * account and with OpenRegister's checks on.
 *
 * @spec openspec/changes/review-audit-fixes-b/specs/lead-management/spec.md#requirement-a-repair-step-moves-leads-off-a-deleted-pipeline-req-raf-011
 */
class OrphanedLeadRepairServiceTest extends TestCase {
	/**
	 * Build the service over an in-memory store.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $store  Rows by schema id.
	 * @param array<int, array<string, mixed>>                $writes Receives every saveObject call.
	 * @param IUser|null                                      $actor  Receives the active user at the first write.
	 *
	 * @return OrphanedLeadRepairService
	 */
	private function service(array $store, array &$writes, ?IUser &$actor = null): OrphanedLeadRepairService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'register' => '20',
				'lead_schema' => '22',
				'pipeline_schema' => '24',
				default => $default,
			}
		);

		$active = null;
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(static function () use (&$active) {
			return $active;
		});
		$session->method('setVolatileActiveUser')->willReturnCallback(static function (?IUser $user) use (&$active): void {
			$active = $user;
		});
		$system = $this->createMock(IUser::class);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($system);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			static fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $store[(string)($config['filters']['schema'] ?? '')] ?? []
		);
		$objectService->method('saveObject')->willReturnCallback(
			static function (...$args) use (&$writes, &$active, &$actor): ObjectEntity {
				$writes[] = $args;
				$actor ??= $active;
				return new ObjectEntity();
			}
		);

		return new OrphanedLeadRepairService(
			appConfig: $appConfig,
			objectService: $objectService,
			systemAccount: new SystemServiceAccount(userManager: $users, userSession: $session, random: $this->createMock(ISecureRandom::class)),
			placer: new OrphanedLeadPlacer(),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end service()

	/**
	 * The pipelines in the store.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function pipelines(): array {
		return [
			[
				'id' => 'p-sales',
				'isDefault' => true,
				'propertyMappings' => [['schemaSlug' => 'lead']],
				'stages' => [
					['name' => 'New', 'order' => 0],
					['name' => 'Won', 'order' => 5, 'isClosed' => true, 'isWon' => true],
				],
			],
		];
	}//end pipelines()

	/**
	 * Only the lead on the deleted pipeline is saved, onto the default
	 * pipeline, as the system account, with RBAC left on.
	 *
	 * @return void
	 */
	public function testMovesOnlyTheLeadOnADeletedPipeline(): void {
		$writes = [];
		$actor = null;
		$store = [
			'24' => self::pipelines(),
			'22' => [
				['id' => 'l-ok', 'title' => 'On the board', 'pipeline' => 'p-sales', 'stage' => 'New'],
				['id' => 'l-gone', 'title' => 'Lost in space', 'pipeline' => '44c997c9-gone', 'stage' => 'Qualified', 'status' => 'open', '@self' => ['id' => 'l-gone']],
			],
		];

		$counts = $this->service(store: $store, writes: $writes, actor: $actor)->repair();

		$this->assertSame(['moved' => 1, 'failed' => 0], $counts);
		$this->assertCount(1, $writes);
		$this->assertSame('p-sales', $writes[0][0]['pipeline']);
		$this->assertSame('New', $writes[0][0]['stage']);
		$this->assertSame('Lost in space', $writes[0][0]['title']);
		$this->assertArrayNotHasKey('@self', $writes[0][0]);
		$this->assertSame('l-gone', $writes[0][4]);
		$this->assertTrue($writes[0][5], 'The write keeps RBAC on.');
		$this->assertNotNull($actor, 'The write runs as the system account, not as Anonymous.');
	}//end testMovesOnlyTheLeadOnADeletedPipeline()

	/**
	 * When no pipeline can be read, nothing is moved.
	 *
	 * @return void
	 */
	public function testMovesNothingWhenNoPipelineCanBeRead(): void {
		$writes = [];
		$store = [
			'22' => [['id' => 'l-1', 'pipeline' => 'p-sales']],
		];

		$counts = $this->service(store: $store, writes: $writes)->repair();

		$this->assertSame(['moved' => 0, 'failed' => 0], $counts);
		$this->assertSame([], $writes);
	}//end testMovesNothingWhenNoPipelineCanBeRead()
}//end class
