<?php

/**
 * Unit tests for JourneyFlowCompiler.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\Marketing\JourneyFlowCompiler;
use PHPUnit\Framework\TestCase;

/**
 * Tests for JourneyFlowCompiler: the node types, where the condition lives,
 * and the else branch nothing may fall off.
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
 */
class JourneyFlowCompilerTest extends TestCase {

	/**
	 * The compiler under test.
	 *
	 * @var JourneyFlowCompiler
	 */
	private JourneyFlowCompiler $compiler;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->compiler = new JourneyFlowCompiler();
	}//end setUp()

	/**
	 * A journey with a wait and a condition compiles to five nodes in the
	 * order the engine walks them.
	 *
	 * @return void
	 */
	public function testCompilesTriggerWaitSwitchAndAction(): void {
		$document = $this->compiler->documentFor(
			journey: $this->journey(),
			journeyId: 'journey-1',
			runAs: 'admin'
		);

		$types = array_column($document['nodes'], 'type', 'id');

		$this->assertSame('openregister.trigger-object', $types['start']);
		$this->assertSame('openregister.wait', $types['hold']);
		$this->assertSame('openregister.switch', $types['gate']);
		$this->assertSame('pipelinq.journey-action', $types['act']);
		$this->assertSame('openregister.end', $types['end']);

		$nodes = array_column($document['nodes'], null, 'id');
		$this->assertSame('object.updated', $nodes['start']['config']['event']);
		$this->assertSame('lead', $nodes['start']['config']['schema']);
		$this->assertSame('5 days', $nodes['hold']['config']['for']);
		$this->assertSame('journey-1', $nodes['act']['config']['journey']);
	}//end testCompilesTriggerWaitSwitchAndAction()

	/**
	 * 🔴 The condition lives on the switch node's `exits`, and the edge only
	 * names which exit it leaves by. A condition written onto the edge saves,
	 * validates, and then takes every branch.
	 *
	 * @return void
	 */
	public function testTheConditionLivesOnTheSwitchExitsNotTheEdge(): void {
		$document = $this->compiler->documentFor(journey: $this->journey(), journeyId: 'journey-1');

		$gate = array_column($document['nodes'], null, 'id')['gate'];
		$exits = array_column($gate['exits'], null, 'id');

		$this->assertSame(['==' => [['var' => 'json.status'], 'open']], $exits['match']['condition']);
		$this->assertArrayNotHasKey('condition', $exits['skip']);

		foreach ($document['edges'] as $edge) {
			$this->assertArrayNotHasKey('condition', $edge, 'a condition on an edge is never evaluated');
		}
	}//end testTheConditionLivesOnTheSwitchExitsNotTheEdge()

	/**
	 * The switch carries an else exit that reaches the end. Without it an
	 * item the condition rejected has nowhere to go and the engine drops it
	 * without a word.
	 *
	 * @return void
	 */
	public function testASkipExitAlwaysReachesTheEnd(): void {
		$document = $this->compiler->documentFor(journey: $this->journey(), journeyId: 'journey-1');

		$skip = array_values(array_filter(
			$document['edges'],
			static fn (array $edge): bool => (($edge['fromExit'] ?? '') === 'skip')
		));

		$this->assertCount(1, $skip);
		$this->assertSame('gate', $skip[0]['from']);
		$this->assertSame('end', $skip[0]['to']);
	}//end testASkipExitAlwaysReachesTheEnd()

	/**
	 * A bookkeeping signal announces itself through no object event, so it
	 * compiles to a schedule. The schedule needs an explicit identity: the
	 * flow's owner is not a fallback, and a schedule node without one never
	 * fires at all.
	 *
	 * @return void
	 */
	public function testABookkeepingSignalCompilesToAScheduleWithARunAs(): void {
		$journey = $this->journey();
		$journey['trigger'] = ['kind' => 'shillinqSignal', 'cron' => '0 7 * * *'];

		$document = $this->compiler->documentFor(journey: $journey, journeyId: 'journey-2', runAs: 'admin');
		$start = array_column($document['nodes'], null, 'id')['start'];

		$this->assertSame('openregister.trigger-schedule', $start['type']);
		$this->assertSame('0 7 * * *', $start['config']['cron']);
		$this->assertSame('admin', $start['config']['runAs']);
		$this->assertSame('schedule', $document['trigger']);
		$this->assertSame('', $document['triggerSchema']);
	}//end testABookkeepingSignalCompilesToAScheduleWithARunAs()

	/**
	 * A cron that is not five fields falls back to the daily default rather
	 * than being handed to the engine to refuse.
	 *
	 * @return void
	 */
	public function testAMalformedCronFallsBackToTheDefault(): void {
		$journey = $this->journey();
		$journey['trigger'] = ['kind' => 'shillinqSignal', 'cron' => 'every morning'];

		$document = $this->compiler->documentFor(journey: $journey, journeyId: 'journey-2', runAs: 'admin');

		$this->assertSame(JourneyFlowCompiler::DEFAULT_CRON, $document['cron']);
	}//end testAMalformedCronFallsBackToTheDefault()

	/**
	 * A journey with neither a wait nor a condition compiles to the trigger,
	 * the action and the end, and nothing else.
	 *
	 * @return void
	 */
	public function testAJourneyWithoutAWaitOrAConditionIsThreeNodes(): void {
		$journey = $this->journey();
		$journey['waitFor'] = '';
		$journey['condition'] = [];

		$document = $this->compiler->documentFor(journey: $journey, journeyId: 'journey-3');

		$this->assertSame(['start', 'act', 'end'], array_column($document['nodes'], 'id'));
		$this->assertCount(2, $document['edges']);
	}//end testAJourneyWithoutAWaitOrAConditionIsThreeNodes()

	/**
	 * Only an active journey compiles to an enabled flow. A draft that
	 * enabled itself would start reaching customers the moment it was saved.
	 *
	 * @return void
	 */
	public function testOnlyAnActiveJourneyCompilesToAnEnabledFlow(): void {
		$journey = $this->journey();
		$journey['status'] = 'draft';
		$this->assertFalse($this->compiler->documentFor($journey, 'journey-4')['enabled']);

		$journey['status'] = 'active';
		$this->assertTrue($this->compiler->documentFor($journey, 'journey-4')['enabled']);
	}//end testOnlyAnActiveJourneyCompilesToAnEnabledFlow()

	/**
	 * A journey that waits five days after a lead moved stage and acts when
	 * the lead is still open.
	 *
	 * @return array<string, mixed> The journey.
	 */
	private function journey(): array {
		return [
			'name' => 'Nudge a stalled lead',
			'description' => 'Ask the owner to call.',
			'status' => 'active',
			'waitFor' => '5 days',
			'trigger' => ['kind' => 'leadStageChanged'],
			'condition' => ['field' => 'status', 'operator' => 'equals', 'value' => 'open'],
			'action' => ['kind' => 'createTask', 'taskSubject' => 'Call about this lead'],
		];
	}//end journey()
}//end class
