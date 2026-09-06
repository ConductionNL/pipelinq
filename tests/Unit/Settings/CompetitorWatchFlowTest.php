<?php

/**
 * Tests for the shipped competitor-watch flow and the schemas around it.
 *
 * A register fragment is configuration, so nothing about it fails loudly at
 * run time: a flow with no `runAs` validates and then never runs as anybody, a
 * flow shipped enabled starts polling somebody else's site the moment the app
 * is installed, and a `kind` enum that grew a network we cannot read
 * legitimately is an invitation to scrape. Each of those is asserted here
 * because none of them would show up anywhere else.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Settings
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use OCA\Pipelinq\Flow\CompetitorWatchRunNode;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Flow\CompetitorWatchRunNode
 */
class CompetitorWatchFlowTest extends TestCase {

	/**
	 * The parsed register fragment.
	 *
	 * @var array<string, mixed>
	 */
	private array $fragment;

	/**
	 * Parse the fragment once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$path = (__DIR__ . '/../../../lib/Settings/register.d/97-marketing-search-intelligence.json');
		$this->assertFileExists($path);
		$this->fragment = (array)json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
	}//end setUp()

	/**
	 * The shipped flow.
	 *
	 * @return array<string, mixed>
	 */
	private function flow(): array {
		$schema = $this->fragment['components']['schemas']['competitorWatch'];
		$flows = $schema['configuration']['x-openregister-flows'];
		$this->assertCount(1, $flows);

		return (array)$flows[0];
	}//end flow()

	/**
	 * The flow starts on a schedule and its first real step is ours, because
	 * the engine's node registry has no outbound-HTTP node to fetch with.
	 *
	 * @return void
	 */
	public function testTheShippedFlowTriggersOnAScheduleAndCallsOurNode(): void {
		$flow = $this->flow();
		$nodes = [];
		foreach ($flow['nodes'] as $node) {
			$nodes[(string)$node['id']] = $node;
		}

		$types = array_column($flow['nodes'], 'type');
		$this->assertContains('openregister.trigger-schedule', $types);
		$this->assertContains(CompetitorWatchRunNode::NODE_ID, $types);
		$this->assertContains('openregister.end', $types);

		$trigger = null;
		foreach ($flow['nodes'] as $node) {
			if ($node['type'] === 'openregister.trigger-schedule') {
				$trigger = $node;
			}
		}

		$this->assertNotNull($trigger);
		$edgeFromTrigger = null;
		foreach ($flow['edges'] as $edge) {
			if ($edge['from'] === $trigger['id']) {
				$edgeFromTrigger = $edge;
			}
		}

		$this->assertNotNull($edgeFromTrigger);
		$this->assertSame(CompetitorWatchRunNode::NODE_ID, $nodes[(string)$edgeFromTrigger['to']]['type']);
	}//end testTheShippedFlowTriggersOnAScheduleAndCallsOurNode()

	/**
	 * The schedule node names the user its runs act as. Without it the flow
	 * saves and then runs as nobody, which reads as a permissions problem at
	 * three in the morning.
	 *
	 * @return void
	 */
	public function testTheScheduleNodeCarriesAnExplicitRunAs(): void {
		foreach ($this->flow()['nodes'] as $node) {
			if ($node['type'] !== 'openregister.trigger-schedule') {
				continue;
			}

			$this->assertArrayHasKey('runAs', $node['config']);
			$this->assertNotSame('', trim((string)$node['config']['runAs']));
			$this->assertCount(5, preg_split('/\s+/', trim((string)$node['config']['cron'])) ?: []);
		}
	}//end testTheScheduleNodeCarriesAnExplicitRunAs()

	/**
	 * The flow arrives disabled, per the engine's adoption contract, so
	 * installing the app never starts polling somebody else's site.
	 *
	 * @return void
	 */
	public function testTheFlowArrivesDisabled(): void {
		$this->assertFalse($this->flow()['enabled']);
	}//end testTheFlowArrivesDisabled()

	/**
	 * The kind vocabulary is exactly the five sources that can be read
	 * legitimately, and names no network whose posts cannot be.
	 *
	 * @return void
	 */
	public function testTheKindEnumIsExactlyTheFiveSupportedKinds(): void {
		$enum = $this->fragment['components']['schemas']['competitorWatch']['properties']['kind']['enum'];

		$this->assertSame(['rss', 'sitemap', 'page', 'fediverse', 'search'], $enum);
		foreach (['linkedin', 'facebook', 'instagram', 'threads', 'meta'] as $forbidden) {
			$this->assertNotContains($forbidden, $enum);
		}
	}//end testTheKindEnumIsExactlyTheFiveSupportedKinds()

	/**
	 * No background job drives competitor watches: the flow does.
	 *
	 * @return void
	 */
	public function testNoTimedJobDrivesCompetitorWatches(): void {
		$directory = (__DIR__ . '/../../../lib/BackgroundJob');
		$this->assertDirectoryExists($directory);

		foreach ((array)glob($directory . '/*.php') as $file) {
			$this->assertStringNotContainsString(
				'CompetitorWatch',
				(string)file_get_contents((string)$file),
				basename((string)$file) . ' must not drive competitor watches'
			);
		}
	}//end testNoTimedJobDrivesCompetitorWatches()

	/**
	 * Every seeded object satisfies its schema's required list. OpenRegister
	 * refuses one that does not, and the import drops it SILENTLY, which
	 * this programme has already paid a debugging cycle for.
	 *
	 * @return void
	 */
	public function testEverySeededObjectSatisfiesItsRequiredList(): void {
		$schemas = $this->fragment['components']['schemas'];
		foreach ($this->fragment['components']['objects'] as $object) {
			$slug = (string)$object['@self']['schema'];
			$this->assertArrayHasKey($slug, $schemas, 'seeded object names an unknown schema');
			foreach (($schemas[$slug]['required'] ?? []) as $field) {
				$this->assertArrayHasKey(
					$field,
					$object,
					$object['@self']['slug'] . ' is missing the required field ' . $field
				);
			}
		}
	}//end testEverySeededObjectSatisfiesItsRequiredList()

	/**
	 * The relevance score has no default. An absent score and a zero must
	 * not be the same value, which a schema default would make them.
	 *
	 * @return void
	 */
	public function testTheRelevanceScoreHasNoDefault(): void {
		$property = $this->fragment['components']['schemas']['watchEvent']['properties']['relevanceScore'];

		$this->assertArrayNotHasKey('default', $property);
		$this->assertSame(0, $property['minimum']);
		$this->assertSame(100, $property['maximum']);
	}//end testTheRelevanceScoreHasNoDefault()

	/**
	 * Both audit directions are a three-value vocabulary, not a boolean, so
	 * "the network will not say" cannot be stored as "no".
	 *
	 * @return void
	 */
	public function testTheAuditDirectionsAreThreeValued(): void {
		$properties = $this->fragment['components']['schemas']['socialConnection']['properties'];

		foreach (['weFollowThem', 'theyFollowUs'] as $field) {
			$this->assertSame('string', $properties[$field]['type']);
			$this->assertSame(['yes', 'no', 'unknown'], $properties[$field]['enum']);
			$this->assertSame('unknown', $properties[$field]['default']);
		}
	}//end testTheAuditDirectionsAreThreeValued()

	/**
	 * The keyword target's volume and difficulty carry no default, because
	 * nothing in this change fills them and a zero would read as a
	 * measurement of no demand.
	 *
	 * @return void
	 */
	public function testVolumeAndDifficultyHaveNoDefault(): void {
		$properties = $this->fragment['components']['schemas']['keywordTarget']['properties'];

		$this->assertArrayNotHasKey('default', $properties['volume']);
		$this->assertArrayNotHasKey('default', $properties['difficulty']);
	}//end testVolumeAndDifficultyHaveNoDefault()

	/**
	 * The fragment declares no second copy of `searchQueryDaily`: this phase
	 * reads the schema phase 2 shipped rather than adding another one.
	 *
	 * @return void
	 */
	public function testNoSecondSearchQuerySchemaIsDeclared(): void {
		$slugs = array_keys($this->fragment['components']['schemas']);

		$this->assertNotContains('searchQueryDaily', $slugs);
		$this->assertNotContains('searchQueryStat', $slugs);
	}//end testNoSecondSearchQuerySchemaIsDeclared()
}//end class
