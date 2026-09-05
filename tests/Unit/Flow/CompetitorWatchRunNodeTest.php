<?php

/**
 * Tests for CompetitorWatchRunNode.
 *
 * The node is an adapter, so the assertions are about the contract rather than
 * the work: it identifies itself as the type the shipped flow names, it does
 * NOT scope itself (the dispatcher already runs a contributed node inside the
 * run's identity, and self-wrapping double-scopes, which is what dossiq
 * shipped three times), and it refuses a configuration that cannot work.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Flow
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Flow;

use OCA\Pipelinq\Flow\CompetitorWatchRunNode;
use OCA\Pipelinq\Flow\PipelinqFlowNodeListener;
use OCA\Pipelinq\Service\Competitor\CompetitorWatchService;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use UnexpectedValueException;

/**
 * @covers \OCA\Pipelinq\Flow\CompetitorWatchRunNode
 * @covers \OCA\Pipelinq\Flow\PipelinqFlowNodeListener
 */
class CompetitorWatchRunNodeTest extends TestCase {

	/**
	 * The mocked watch service.
	 *
	 * @var CompetitorWatchService&MockObject
	 */
	private CompetitorWatchService $watches;

	/**
	 * The node under test.
	 *
	 * @var CompetitorWatchRunNode
	 */
	private CompetitorWatchRunNode $node;

	/**
	 * Build the node over a mocked service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->watches = $this->createMock(CompetitorWatchService::class);
		$this->node = new CompetitorWatchRunNode(
			l10n: $l10n,
			urls: $this->createMock(IURLGenerator::class),
			watches: $this->watches
		);
	}//end setUp()

	/**
	 * The node's id is the one the shipped flow names, so the flow and the
	 * palette cannot drift apart.
	 *
	 * @return void
	 */
	public function testIdentifiesItselfAsTheTypeTheShippedFlowNames(): void {
		$fragment = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/register.d/97-marketing-search-intelligence.json'),
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		$flow = $fragment['components']['schemas']['competitorWatch']['configuration']['x-openregister-flows'][0];
		$types = array_column($flow['nodes'], 'type');

		$this->assertContains($this->node->getId(), $types);
		$this->assertSame(CompetitorWatchRunNode::NODE_ID, $this->node->getId());
	}//end testIdentifiesItselfAsTheTypeTheShippedFlowNames()

	/**
	 * The listener contributes this node, so the flow's type resolves at run
	 * time rather than only in the JSON.
	 *
	 * @return void
	 */
	public function testTheListenerContributesTheNode(): void {
		$this->assertContains(CompetitorWatchRunNode::class, PipelinqFlowNodeListener::nodeClasses());
	}//end testTheListenerContributesTheNode()

	/**
	 * The node does not declare itself self-scoped, so the dispatcher wraps
	 * it in the run's acting identity exactly once.
	 *
	 * @return void
	 */
	public function testDoesNotDeclareItselfSelfScoped(): void {
		$interfaces = (new ReflectionClass(CompetitorWatchRunNode::class))->getInterfaceNames();

		foreach ($interfaces as $interface) {
			$this->assertStringNotContainsString('SelfScoped', $interface);
		}

		// It also holds no way to change the acting identity: no session, no
		// user manager, nothing to narrow with. The docblock explains why;
		// the constructor is what proves it.
		$parameters = (new ReflectionClass(CompetitorWatchRunNode::class))->getConstructor()?->getParameters() ?? [];
		foreach ($parameters as $parameter) {
			$type = (string)$parameter->getType();
			$this->assertStringNotContainsString('IUserSession', $type);
			$this->assertStringNotContainsString('IUserManager', $type);
		}
	}//end testDoesNotDeclareItselfSelfScoped()

	/**
	 * Firing runs the due watches, with the configured limit and the acting
	 * identity the run names.
	 *
	 * @return void
	 */
	public function testRunsOnlyTheWatchesThatAreDue(): void {
		$this->watches->expects($this->once())
			->method('runDue')
			->with(7, 'automation')
			->willReturn(['watches' => 2, 'events' => 3, 'failures' => []]);

		$out = $this->node->execute(items: [], config: ['limit' => 7], context: ['runAs' => 'automation']);

		$this->assertCount(1, $out);
		$this->assertSame(2, $out[0]['json'][CompetitorWatchRunNode::OUTCOME_KEY]['watches']);
	}//end testRunsOnlyTheWatchesThatAreDue()

	/**
	 * A schedule trigger hands the node no items, which is the normal case
	 * rather than nothing to do: the node's job is to go and find work.
	 *
	 * @return void
	 */
	public function testAnEmptyFiringStillRuns(): void {
		$this->watches->expects($this->once())
			->method('runDue')
			->willReturn(['watches' => 0, 'events' => 0, 'failures' => []]);

		$this->assertCount(1, $this->node->execute(items: [], config: [], context: []));
	}//end testAnEmptyFiringStillRuns()

	/**
	 * A watch that fails is reported in the summary rather than aborting the
	 * firing, so one competitor's outage does not stop the others.
	 *
	 * @return void
	 */
	public function testOneFailingWatchDoesNotStopTheRun(): void {
		$this->watches->method('runDue')->willReturn(
			['watches' => 3, 'events' => 1, 'failures' => ['watch-2' => 'answered 403']]
		);

		$out = $this->node->execute(items: [], config: [], context: []);
		$summary = $out[0]['json'][CompetitorWatchRunNode::OUTCOME_KEY];

		$this->assertSame(3, $summary['watches']);
		$this->assertArrayHasKey('watch-2', $summary['failures']);
	}//end testOneFailingWatchDoesNotStopTheRun()

	/**
	 * A limit that cannot mean anything is refused at save time, where the
	 * author can still see it.
	 *
	 * @return void
	 */
	public function testRefusesALimitThatCannotWork(): void {
		$this->expectException(UnexpectedValueException::class);

		$this->node->validateConfig(config: ['limit' => 0]);
	}//end testRefusesALimitThatCannotWork()

	/**
	 * An absent limit is fine: the documented default applies.
	 *
	 * @return void
	 */
	public function testAnAbsentLimitIsAccepted(): void {
		$this->node->validateConfig(config: []);

		$this->addToAssertionCount(1);
	}//end testAnAbsentLimitIsAccepted()

	/**
	 * The palette entry is filled in, so the node is usable in the editor
	 * rather than being an unnamed box.
	 *
	 * @return void
	 */
	public function testThePaletteEntryIsFilledIn(): void {
		$this->assertNotSame('', $this->node->getDisplayName());
		$this->assertNotSame('', $this->node->getDescription());
	}//end testThePaletteEntryIsFilledIn()
}//end class
