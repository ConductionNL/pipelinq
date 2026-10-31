<?php

/**
 * Unit tests for StageLimitGuard.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Pipelinq\Listener\StageLimitGuard;
use OCA\Pipelinq\Service\SchemaMapService;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A full stage refuses one item more; leaving or staying never is refused.
 *
 * @spec openspec/changes/pipeline-stage-limit/specs/pipeline-stage-limits/spec.md#requirement-no-write-puts-an-item-into-a-full-stage-req-psl-002
 */
class StageLimitGuardTest extends TestCase {
	/**
	 * Filters every count call saw.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $counted = [];

	/**
	 * Build the guard over a Sales pipeline with Proposal limited to 8.
	 *
	 * @param int $inStage How many items the count answers per mapped schema.
	 * @param array<int, array<string, string>> $mappings The pipeline's propertyMappings.
	 *
	 * @return StageLimitGuard The guard.
	 */
	private function guard(int $inStage, array $mappings = [['schemaSlug' => 'lead', 'columnProperty' => 'stage']]): StageLimitGuard {
		$this->counted = [];
		$counted = &$this->counted;

		$pipeline = new ObjectEntity();
		$pipeline->setUuid('p-1');
		$pipeline->setObject(
			[
				'title' => 'Sales',
				'propertyMappings' => $mappings,
				'stages' => [
					['name' => 'New', 'order' => 1],
					['name' => 'Proposal', 'order' => 2, 'maxItems' => 8],
					['name' => 'Won', 'order' => 3, 'isClosed' => true, 'maxItems' => 1],
				],
			]
		);

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturn($pipeline);
		$objects->method('count')->willReturnCallback(
			static function (array $config) use (&$counted, $inStage): int {
				$counted[] = $config['filters'];
				return $inStage;
			}
		);

		$schemaMap = $this->createMock(SchemaMapService::class);
		$schemaMap->method('resolveEntityType')->willReturnCallback(
			static fn (?string $id): ?string => ['s-lead' => 'lead', 's-ticket' => 'ticket'][$id] ?? null
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => [
				'register' => 'r-1',
				'pipeline_schema' => 's-pipeline',
				'lead_schema' => 's-lead',
				'ticket_schema' => 's-ticket',
			][$key] ?? $default
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		return new StageLimitGuard($objects, $schemaMap, $config, $l10n, $this->createMock(LoggerInterface::class));
	}//end guard()

	/**
	 * An object entity.
	 *
	 * @param string $schema The schema id.
	 * @param array<string, mixed> $data The body.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('l-9');
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * The ninth lead moved into a stage of 8 is refused with the stage named.
	 *
	 * @return void
	 */
	public function testRefusesTheNinthItem(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity('s-lead', ['pipeline' => 'p-1', 'stage' => 'Proposal']),
			$this->entity('s-lead', ['pipeline' => 'p-1', 'stage' => 'New'])
		);
		$this->guard(8)->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('Stage Proposal is full (8 of 8)', $event->getErrors()['message']);
		$this->assertSame(['schema' => 's-lead', 'register' => 'r-1', 'pipeline' => 'p-1', 'stage' => 'Proposal'], $this->counted[0]);
	}//end testRefusesTheNinthItem()

	/**
	 * A create straight into a full stage is refused as well.
	 *
	 * @return void
	 */
	public function testRefusesACreateIntoAFullStage(): void {
		$event = new ObjectCreatingEvent($this->entity('s-lead', ['pipeline' => 'p-1', 'stage' => 'Proposal']));
		$this->guard(8)->handle($event);
		$this->assertTrue($event->isPropagationStopped());
	}//end testRefusesACreateIntoAFullStage()

	/**
	 * Under the limit the move passes.
	 *
	 * @return void
	 */
	public function testAllowsAMoveUnderTheLimit(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity('s-lead', ['pipeline' => 'p-1', 'stage' => 'Proposal']),
			$this->entity('s-lead', ['pipeline' => 'p-1', 'stage' => 'New'])
		);
		$this->guard(7)->handle($event);
		$this->assertFalse($event->isPropagationStopped());
	}//end testAllowsAMoveUnderTheLimit()

	/**
	 * Moving out of a full stage, and editing without changing the stage, pass without a count.
	 *
	 * @return void
	 */
	public function testAllowsAMoveOutAndAnEditThatKeepsTheStage(): void {
		$guard = $this->guard(20);

		$out = new ObjectUpdatingEvent(
			$this->entity('s-lead', ['pipeline' => 'p-1', 'stage' => 'New']),
			$this->entity('s-lead', ['pipeline' => 'p-1', 'stage' => 'Proposal'])
		);
		$guard->handle($out);
		$this->assertFalse($out->isPropagationStopped());

		$keep = new ObjectUpdatingEvent(
			$this->entity('s-lead', ['pipeline' => 'p-1', 'stage' => 'Proposal', 'title' => 'Renamed']),
			$this->entity('s-lead', ['pipeline' => 'p-1', 'stage' => 'Proposal', 'title' => 'Old'])
		);
		$guard->handle($keep);
		$this->assertFalse($keep->isPropagationStopped());
		$this->assertSame([], $this->counted);
	}//end testAllowsAMoveOutAndAnEditThatKeepsTheStage()

	/**
	 * Closed stages and schemas no pipeline boards are ignored.
	 *
	 * @return void
	 */
	public function testIgnoresClosedStagesAndUnboardedSchemas(): void {
		$guard = $this->guard(50);

		$won = new ObjectCreatingEvent($this->entity('s-lead', ['pipeline' => 'p-1', 'stage' => 'Won']));
		$guard->handle($won);
		$this->assertFalse($won->isPropagationStopped());

		$other = new ObjectCreatingEvent($this->entity('s-other', ['pipeline' => 'p-1', 'stage' => 'Proposal']));
		$guard->handle($other);
		$this->assertFalse($other->isPropagationStopped());

		$ticket = new ObjectCreatingEvent($this->entity('s-ticket', ['pipeline' => 'p-1', 'stage' => 'Proposal', 'ticketType' => 'request']));
		$guard->handle($ticket);
		$this->assertFalse($ticket->isPropagationStopped());
		$this->assertSame([], $this->counted);
	}//end testIgnoresClosedStagesAndUnboardedSchemas()

	/**
	 * The count runs across every mapped type, a ticket subtype narrowing the ticket schema.
	 *
	 * @return void
	 */
	public function testCountsAcrossEveryMappedType(): void {
		$guard = $this->guard(
			4,
			[
				['schemaSlug' => 'lead', 'columnProperty' => 'stage'],
				['schemaSlug' => 'request', 'columnProperty' => 'stage'],
			]
		);

		$event = new ObjectCreatingEvent($this->entity('s-ticket', ['pipeline' => 'p-1', 'stage' => 'Proposal', 'ticketType' => 'request']));
		$guard->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('Stage Proposal is full (8 of 8)', $event->getErrors()['message']);
		$this->assertSame('request', $this->counted[1]['ticketType']);
		$this->assertSame('s-ticket', $this->counted[1]['schema']);
	}//end testCountsAcrossEveryMappedType()
}//end class
