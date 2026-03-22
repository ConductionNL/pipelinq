<?php

/**
 * Unit tests for PipelineStageData.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\PipelineStageData;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PipelineStageData.
 */
class PipelineStageDataTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var PipelineStageData
     */
    private PipelineStageData $stageData;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->stageData = new PipelineStageData();
    }//end setUp()

    /**
     * Test sales pipeline has correct title and is default.
     *
     * @return void
     */
    public function testSalesPipelineStructure(): void
    {
        $data = $this->stageData->getSalesPipelineData();

        $this->assertSame('Sales Pipeline', $data['title']);
        $this->assertTrue($data['isDefault']);
        $this->assertSame('EUR', $data['totalsLabel']);
        $this->assertNull($data['viewId']);
        $this->assertIsArray($data['stages']);
        $this->assertIsArray($data['propertyMappings']);
    }//end testSalesPipelineStructure()

    /**
     * Test sales pipeline has 7 stages in correct order.
     *
     * @return void
     */
    public function testSalesPipelineHasSevenStages(): void
    {
        $data   = $this->stageData->getSalesPipelineData();
        $stages = $data['stages'];

        $this->assertCount(7, $stages);
        $this->assertSame('New', $stages[0]['name']);
        $this->assertSame('Won', $stages[5]['name']);
        $this->assertSame('Lost', $stages[6]['name']);
    }//end testSalesPipelineHasSevenStages()

    /**
     * Test sales pipeline stages have sequential order values.
     *
     * @return void
     */
    public function testSalesPipelineStagesHaveSequentialOrder(): void
    {
        $data   = $this->stageData->getSalesPipelineData();
        $stages = $data['stages'];

        foreach ($stages as $index => $stage) {
            $this->assertSame($index, $stage['order']);
        }
    }//end testSalesPipelineStagesHaveSequentialOrder()

    /**
     * Test Won stage is marked as closed and won.
     *
     * @return void
     */
    public function testWonStageIsClosedAndWon(): void
    {
        $data   = $this->stageData->getSalesPipelineData();
        $stages = $data['stages'];

        $wonStage = null;
        foreach ($stages as $stage) {
            if ($stage['name'] === 'Won') {
                $wonStage = $stage;
                break;
            }
        }

        $this->assertNotNull($wonStage);
        $this->assertTrue($wonStage['isClosed']);
        $this->assertTrue($wonStage['isWon']);
        $this->assertSame(100, $wonStage['probability']);
    }//end testWonStageIsClosedAndWon()

    /**
     * Test Lost stage is closed but not won.
     *
     * @return void
     */
    public function testLostStageIsClosedNotWon(): void
    {
        $data   = $this->stageData->getSalesPipelineData();
        $stages = $data['stages'];

        $lostStage = null;
        foreach ($stages as $stage) {
            if ($stage['name'] === 'Lost') {
                $lostStage = $stage;
                break;
            }
        }

        $this->assertNotNull($lostStage);
        $this->assertTrue($lostStage['isClosed']);
        $this->assertFalse($lostStage['isWon']);
        $this->assertSame(0, $lostStage['probability']);
    }//end testLostStageIsClosedNotWon()

    /**
     * Test service requests pipeline structure.
     *
     * @return void
     */
    public function testServiceRequestsPipelineStructure(): void
    {
        $data = $this->stageData->getServiceRequestsPipelineData();

        $this->assertSame('Service Requests', $data['title']);
        $this->assertFalse($data['isDefault']);
        $this->assertNull($data['totalsLabel']);
        $this->assertIsArray($data['stages']);
    }//end testServiceRequestsPipelineStructure()

    /**
     * Test service requests pipeline has 5 stages.
     *
     * @return void
     */
    public function testServiceRequestsPipelineHasFiveStages(): void
    {
        $data   = $this->stageData->getServiceRequestsPipelineData();
        $stages = $data['stages'];

        $this->assertCount(5, $stages);
        $this->assertSame('New', $stages[0]['name']);
        $this->assertSame('In Progress', $stages[1]['name']);
        $this->assertSame('Completed', $stages[2]['name']);
        $this->assertSame('Rejected', $stages[3]['name']);
        $this->assertSame('Converted to Case', $stages[4]['name']);
    }//end testServiceRequestsPipelineHasFiveStages()

    /**
     * Test viewId is propagated when provided.
     *
     * @return void
     */
    public function testViewIdIsPropagated(): void
    {
        $data = $this->stageData->getSalesPipelineData('view-123');
        $this->assertSame('view-123', $data['viewId']);

        $data2 = $this->stageData->getServiceRequestsPipelineData('view-456');
        $this->assertSame('view-456', $data2['viewId']);
    }//end testViewIdIsPropagated()

    /**
     * Test all stages have required keys.
     *
     * @return void
     */
    public function testAllStagesHaveRequiredKeys(): void
    {
        $allStages = array_merge(
            $this->stageData->getSalesPipelineData()['stages'],
            $this->stageData->getServiceRequestsPipelineData()['stages']
        );

        foreach ($allStages as $stage) {
            $this->assertArrayHasKey('name', $stage);
            $this->assertArrayHasKey('order', $stage);
            $this->assertArrayHasKey('color', $stage);
            $this->assertArrayHasKey('isClosed', $stage);
            $this->assertArrayHasKey('isWon', $stage);
        }
    }//end testAllStagesHaveRequiredKeys()

    /**
     * Test all stage colors are valid hex colors.
     *
     * @return void
     */
    public function testStageColorsAreValidHex(): void
    {
        $allStages = array_merge(
            $this->stageData->getSalesPipelineData()['stages'],
            $this->stageData->getServiceRequestsPipelineData()['stages']
        );

        foreach ($allStages as $stage) {
            $this->assertMatchesRegularExpression('/^#[0-9a-fA-F]{6}$/', $stage['color']);
        }
    }//end testStageColorsAreValidHex()

    /**
     * Test property mappings for sales pipeline.
     *
     * @return void
     */
    public function testSalesPipelinePropertyMappings(): void
    {
        $data     = $this->stageData->getSalesPipelineData();
        $mappings = $data['propertyMappings'];

        $this->assertCount(2, $mappings);
        $this->assertSame('lead', $mappings[0]['schemaSlug']);
        $this->assertSame('stage', $mappings[0]['columnProperty']);
        $this->assertSame('value', $mappings[0]['totalsProperty']);
    }//end testSalesPipelinePropertyMappings()
}//end class
