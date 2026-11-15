<?php

/**
 * JourneyActionNode: identity, configuration and how items reach the runner.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Flow
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

namespace OCA\Pipelinq\Tests\Unit\Flow;

use OCA\Pipelinq\Flow\JourneyActionNode;
use OCA\Pipelinq\Service\Marketing\JourneyStepRunner;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * The node runs the configured journey once per item, or once for its
 * audience when no item is attached, and refuses a flow without a journey.
 *
 * @covers \OCA\Pipelinq\Flow\JourneyActionNode
 */
class JourneyActionNodeTest extends TestCase {

	/**
	 * The journey runner.
	 *
	 * @var JourneyStepRunner&MockObject
	 */
	private JourneyStepRunner&MockObject $runner;

	/**
	 * The URL generator.
	 *
	 * @var IURLGenerator&MockObject
	 */
	private IURLGenerator&MockObject $urls;

	/**
	 * The node under test.
	 *
	 * @var JourneyActionNode
	 */
	private JourneyActionNode $node;

	/**
	 * Build the node.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->runner = $this->createMock(JourneyStepRunner::class);
		$this->urls = $this->createMock(IURLGenerator::class);
		$this->node = new JourneyActionNode($this->runner, $this->urls);
	}//end setUp()

	/**
	 * The node's id, name, description, scope and config keys.
	 *
	 * @return void
	 */
	public function testIdentity(): void {
		$this->assertSame('pipelinq.journey-action', $this->node->getId());
		$this->assertSame(JourneyActionNode::NODE_ID, $this->node->getId());
		$this->assertSame('Journey action', $this->node->getDisplayName());
		$this->assertSame('Send the journey\'s mailing or create its task, after the consent gate.', $this->node->getDescription());
		$this->assertTrue($this->node->isAvailableForScope(0));
		$this->assertTrue($this->node->isAvailableForScope(1));
		$this->assertSame(['journey'], $this->node->configKeys());
	}//end testIdentity()

	/**
	 * The icon is the app icon, made absolute.
	 *
	 * @return void
	 */
	public function testIcon(): void {
		$this->urls->expects($this->once())->method('imagePath')->with('pipelinq', 'app.svg')->willReturn('/apps/pipelinq/img/app.svg');
		$this->urls->expects($this->once())->method('getAbsoluteURL')->with('/apps/pipelinq/img/app.svg')->willReturn('https://cloud/apps/pipelinq/img/app.svg');

		$this->assertSame('https://cloud/apps/pipelinq/img/app.svg', $this->node->getIcon());
	}//end testIcon()

	/**
	 * A missing or blank journey is refused; a set one passes.
	 *
	 * @return void
	 */
	public function testValidateConfig(): void {
		foreach ([[], ['journey' => '  '], ['journey' => null]] as $config) {
			try {
				$this->node->validateConfig($config);
				$this->fail('Expected a refusal for ' . json_encode($config));
			} catch (UnexpectedValueException $e) {
				$this->assertSame('A journey action needs a journey.', $e->getMessage());
			}
		}

		$this->node->validateConfig(['journey' => 'j-1']);
		$this->addToAssertionCount(1);
	}//end testValidateConfig()

	/**
	 * A flow that never passed validation is still refused at execution, before the runner runs.
	 *
	 * @return void
	 */
	public function testExecuteRefusesAMissingJourney(): void {
		$this->runner->expects($this->never())->method('run');

		$this->expectException(UnexpectedValueException::class);
		$this->node->execute([['json' => ['id' => 1]]], [], []);
	}//end testExecuteRefusesAMissingJourney()

	/**
	 * With no items the journey runs once for its audience, under the run uuid.
	 *
	 * @return void
	 */
	public function testExecuteWithoutItemsRunsTheAudience(): void {
		$this->runner->expects($this->once())->method('run')->with('j-1', [], 'run-9')->willReturn([]);

		$this->assertSame([], $this->node->execute([], ['journey' => ' j-1 '], ['runUuid' => 'run-9', 'uuid' => 'other']));
	}//end testExecuteWithoutItemsRunsTheAudience()

	/**
	 * Each item's json runs the journey; a non-array json becomes an empty subject; items pass through.
	 *
	 * @return void
	 */
	public function testExecuteRunsEachItem(): void {
		$calls = [];
		$this->runner->expects($this->exactly(3))->method('run')->willReturnCallback(
			static function (string $journeyId, array $subject = [], string $flowRunUuid = '') use (&$calls): array {
				$calls[] = [$journeyId, $subject, $flowRunUuid];
				return [];
			}
		);
		$items = [['json' => ['id' => 1]], ['json' => 'text'], ['binary' => 'x']];

		$this->assertSame($items, $this->node->execute($items, ['journey' => 'j-1'], ['uuid' => 'flow-uuid']));
		$this->assertSame([['j-1', ['id' => 1], 'flow-uuid'], ['j-1', [], 'flow-uuid'], ['j-1', [], 'flow-uuid']], $calls);
	}//end testExecuteRunsEachItem()

	/**
	 * Without a run uuid in the context the runner gets an empty one.
	 *
	 * @return void
	 */
	public function testExecuteWithoutRunUuid(): void {
		$this->runner->expects($this->once())->method('run')->with('j-1', ['id' => 2], '')->willReturn([]);

		$this->node->execute([['json' => ['id' => 2]]], ['journey' => 'j-1'], []);
	}//end testExecuteWithoutRunUuid()
}//end class
