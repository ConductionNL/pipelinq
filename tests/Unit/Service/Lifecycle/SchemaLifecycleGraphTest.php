<?php

/**
 * Unit tests for SchemaLifecycleGraph — schema-declared transition resolution.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Lifecycle
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

namespace OCA\Pipelinq\Tests\Unit\Service\Lifecycle;

use OCA\Pipelinq\Service\Lifecycle\SchemaLifecycleGraph;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the helper resolves the `x-openregister-lifecycle` transition graph
 * from the bundled register JSON for all three Batch A schemas, and degrades to
 * an empty map for an unknown schema (so callers fall back safely).
 */
class SchemaLifecycleGraphTest extends TestCase
{
    /**
     * Build a helper pointed at the real bundled Settings directory.
     *
     * @return SchemaLifecycleGraph
     */
    private function graph(): SchemaLifecycleGraph
    {
        return new SchemaLifecycleGraph(settingsDir: __DIR__.'/../../../../lib/Settings');
    }//end graph()

    /**
     * The Task schema graph matches the prior hardcoded ALLOWED_TRANSITIONS.
     *
     * @return void
     */
    public function testTaskAdjacencyMatchesDeclaredGraph(): void
    {
        $this->assertSame(
            expected: [
                'open'           => ['in_behandeling'],
                'in_behandeling' => ['afgerond', 'verlopen'],
                'afgerond'       => ['open'],
                'verlopen'       => ['open'],
            ],
            actual: $this->graph()->adjacencyFor(schemaSlug: 'task')
        );
    }//end testTaskAdjacencyMatchesDeclaredGraph()

    /**
     * The walkInTicket FULL graph includes terminal states as empty-array keys.
     *
     * @return void
     */
    public function testWalkInTicketFullAdjacencyIncludesTerminalStates(): void
    {
        $this->assertSame(
            expected: [
                'waiting'   => ['called', 'abandoned'],
                'called'    => ['served', 'abandoned'],
                'served'    => [],
                'abandoned' => [],
            ],
            actual: $this->graph()->fullAdjacencyFor(schemaSlug: 'walkInTicket')
        );
    }//end testWalkInTicketFullAdjacencyIncludesTerminalStates()

    /**
     * The loyaltyProgramme graph declares the activate edge plus operational edges.
     *
     * @return void
     */
    public function testLoyaltyProgrammeAdjacencyDeclaresActivateEdge(): void
    {
        $graph = $this->graph()->adjacencyFor(schemaSlug: 'loyaltyProgramme');
        $this->assertArrayHasKey(key: 'concept', array: $graph);
        $this->assertSame(expected: ['actief'], actual: $graph['concept']);
        $this->assertContains(needle: 'gepauzeerd', haystack: $graph['actief']);
        $this->assertContains(needle: 'beeindigd', haystack: $graph['actief']);
    }//end testLoyaltyProgrammeAdjacencyDeclaresActivateEdge()

    /**
     * An unknown schema yields an empty map (callers fall back).
     *
     * @return void
     */
    public function testUnknownSchemaYieldsEmptyMap(): void
    {
        $this->assertSame(expected: [], actual: $this->graph()->adjacencyFor(schemaSlug: 'doesNotExist'));
        $this->assertSame(expected: [], actual: $this->graph()->fullAdjacencyFor(schemaSlug: 'doesNotExist'));
    }//end testUnknownSchemaYieldsEmptyMap()

    /**
     * An unreadable settings directory yields an empty map (safe fallback path).
     *
     * @return void
     */
    public function testUnreadableSettingsDirYieldsEmptyMap(): void
    {
        $graph = new SchemaLifecycleGraph(settingsDir: '/nonexistent/path/Settings');
        $this->assertSame(expected: [], actual: $graph->adjacencyFor(schemaSlug: 'task'));
    }//end testUnreadableSettingsDirYieldsEmptyMap()
}//end class
