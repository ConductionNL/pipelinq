<?php

/**
 * Unit tests for LoyaltyProgrammeService — concept -> actief activation.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pipelinq-lifecycle-batch-a/specs/openregister-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Service\LoyaltyProgrammeService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Verifies the schema-declared concept -> actief edge is enforced (ADR-031) while
 * the cross-object business guards stay in PHP.
 */
class LoyaltyProgrammeServiceTest extends TestCase {
	/**
	 * Build a LoyaltyProgrammeService whose ObjectService is wired by callbacks.
	 *
	 * @param array<string, mixed>|null $programme The programme returned by find().
	 *
	 * @return LoyaltyProgrammeService
	 */
	private function buildService(
		?array $programme,
	): LoyaltyProgrammeService {
		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);

		// ADR-084: find()/saveObject() return an ObjectEntityInterface, not a row.
		$objectService->method('find')->willReturn(
			$programme === null ? null : $this->entity(uuid: 'prog-1', row: $programme)
		);
		$objectService->method('findAll')->willReturn([]);
		$objectService->method('saveObject')->willReturnCallback(
			callback: function (array $object): ObjectEntity {
				return $this->entity(uuid: 'prog-1', row: $object);
			}
		);

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			callback: static function (string $app, string $key, string $default = ''): string {
				$values = [
					'register' => 'pipelinq',
					'loyaltyProgramme_schema' => 'loyaltyProgramme',
					'pointsRule_schema' => 'pointsRule',
					'redemptionOption_schema' => 'redemptionOption',
				];
				return ($values[$key] ?? $default);
			}
		);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);

		return new LoyaltyProgrammeService(
			appConfig: $appConfig,
			logger: $logger,
			objectService: $objectService,
		);
	}//end buildService()

	/**
	 * Wrap a row in an ObjectEntity, as OpenRegister now returns (ADR-084).
	 *
	 * @param string $uuid The object UUID.
	 * @param array<string, mixed> $row The stored payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $row): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($row);

		return $entity;
	}//end entity()

	/**
	 * A concept programme passes the schema-declared transition guard and proceeds
	 * to the PHP business validation (it does NOT fail with a transition error).
	 *
	 * Note: the full happy path through `validateForActivation()` depends on
	 * `countByProgramme()`, whose `findAll(filters:, register:, schema:, limit:)`
	 * named-argument form does not match OpenRegister's real `findAll(array $config)`
	 * signature — a pre-existing bug deferred to the query-pushdown batch. So here we
	 * prove the transition guard admits `concept` (the subject of THIS change) by
	 * asserting the failure that surfaces is the business guard, not a transition
	 * rejection.
	 *
	 * @return void
	 */
	public function testActivateConceptPassesTransitionGuardThenBusinessGuard(): void {
		$service = $this->buildService(
			programme: [
				'status' => 'draft',
				'startDate' => '2026-01-01',
				'endDate' => '2026-12-31',
			],
		);

		try {
			$service->activate(programmeId: 'prog-1', activatedBy: 'agent-1');
			$this->fail(message: 'Expected a business-guard RuntimeException.');
		} catch (RuntimeException $e) {
			// The transition guard ADMITTED concept -> actief; the only error left
			// is the business guard. A transition rejection would mention "transition
			// from 'draft'".
			$this->assertStringNotContainsString(
				needle: "transition from 'draft'",
				haystack: $e->getMessage()
			);
			$this->assertStringContainsString(needle: 'Cannot activate', haystack: $e->getMessage());
		}
	}//end testActivateConceptPassesTransitionGuardThenBusinessGuard()

	/**
	 * The schema-declared graph admits the concept -> actief edge.
	 *
	 * Asserts the lifecycle declaration (not a hardcoded PHP map) is the source of
	 * truth for the activation edge.
	 *
	 * @return void
	 */
	public function testSchemaDeclaresConceptToActiefEdge(): void {
		$graph = (new \OCA\Pipelinq\Service\Lifecycle\SchemaLifecycleGraph(
			settingsDir: __DIR__ . '/../../../lib/Settings'
		))->adjacencyFor(schemaSlug: 'loyaltyProgramme');

		$this->assertContains(needle: 'active', haystack: ($graph['draft'] ?? []));
	}//end testSchemaDeclaresConceptToActiefEdge()

	/**
	 * Activating from a non-concept state is rejected by the schema-declared graph.
	 *
	 * @return void
	 */
	public function testActivateFromTerminalStateIsRejected(): void {
		$service = $this->buildService(
			programme: [
				'status' => 'ended',
			],
		);

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: "transition from 'ended' to 'active' is not allowed");

		$service->activate(programmeId: 'prog-1', activatedBy: 'agent-1');
	}//end testActivateFromTerminalStateIsRejected()

	/**
	 * A concept programme failing a business guard (no points rules) still raises
	 * the existing RuntimeException — that guard cannot be declarative.
	 *
	 * @return void
	 */
	public function testActivateWithoutRulesStillRaisesBusinessGuard(): void {
		$service = $this->buildService(
			programme: [
				'status' => 'draft',
			],
		);

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'no points rules configured');

		$service->activate(programmeId: 'prog-1', activatedBy: 'agent-1');
	}//end testActivateWithoutRulesStillRaisesBusinessGuard()

	/**
	 * A missing programme raises the existing "Programme not found." error.
	 *
	 * @return void
	 */
	public function testActivateMissingProgrammeRaises(): void {
		$service = $this->buildService(programme: null);

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'Programme not found.');

		$service->activate(programmeId: 'missing', activatedBy: 'agent-1');
	}//end testActivateMissingProgrammeRaises()
}//end class
