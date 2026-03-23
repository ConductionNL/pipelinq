<?php

/**
 * Unit tests for SettingsMapBuilder.
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

use OCA\Pipelinq\Service\SettingsMapBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SettingsMapBuilder.
 */
class SettingsMapBuilderTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var SettingsMapBuilder
     */
    private SettingsMapBuilder $builder;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->builder = new SettingsMapBuilder();
    }//end setUp()

    /**
     * Test buildSchemaSlugMap returns correct mapping.
     *
     * @return void
     */
    public function testBuildSchemaSlugMapFromArrays(): void
    {
        $schemas = [
            ['slug' => 'client', 'id' => '100'],
            ['slug' => 'lead', 'id' => '101'],
        ];

        $result = $this->builder->buildSchemaSlugMap($schemas);

        $this->assertSame('100', $result['client']);
        $this->assertSame('101', $result['lead']);
    }//end testBuildSchemaSlugMapFromArrays()

    /**
     * Test buildSchemaSlugMap skips entries without slug.
     *
     * @return void
     */
    public function testBuildSchemaSlugMapSkipsNoSlug(): void
    {
        $schemas = [
            ['id' => '100'],
            ['slug' => 'lead', 'id' => '101'],
        ];

        $result = $this->builder->buildSchemaSlugMap($schemas);

        $this->assertCount(1, $result);
        $this->assertSame('101', $result['lead']);
    }//end testBuildSchemaSlugMapSkipsNoSlug()

    /**
     * Test buildSchemaSlugMap uses uuid as fallback.
     *
     * @return void
     */
    public function testBuildSchemaSlugMapUsesUuidFallback(): void
    {
        $schemas = [
            ['slug' => 'client', 'uuid' => 'abc-123'],
        ];

        $result = $this->builder->buildSchemaSlugMap($schemas);

        $this->assertSame('abc-123', $result['client']);
    }//end testBuildSchemaSlugMapUsesUuidFallback()

    /**
     * Test findRegisterIdBySlug returns matching register ID.
     *
     * @return void
     */
    public function testFindRegisterIdBySlugMatch(): void
    {
        $registers = [
            ['slug' => 'other', 'id' => '10'],
            ['slug' => 'pipelinq', 'id' => '20'],
        ];

        $result = $this->builder->findRegisterIdBySlug($registers);

        $this->assertSame('20', $result);
    }//end testFindRegisterIdBySlugMatch()

    /**
     * Test findRegisterIdBySlug returns null when not found.
     *
     * @return void
     */
    public function testFindRegisterIdBySlugNoMatch(): void
    {
        $registers = [
            ['slug' => 'other', 'id' => '10'],
        ];

        $this->assertNull($this->builder->findRegisterIdBySlug($registers));
    }//end testFindRegisterIdBySlugNoMatch()

    /**
     * Test findDefaultViewId returns marked default.
     *
     * @return void
     */
    public function testFindDefaultViewIdReturnsMarkedDefault(): void
    {
        $views = [
            ['id' => 'v1', 'isDefault' => false],
            ['id' => 'v2', 'isDefault' => true],
        ];

        $this->assertSame('v2', $this->builder->findDefaultViewId($views));
    }//end testFindDefaultViewIdReturnsMarkedDefault()

    /**
     * Test findDefaultViewId falls back to first view.
     *
     * @return void
     */
    public function testFindDefaultViewIdFallsBackToFirst(): void
    {
        $views = [
            ['id' => 'v1', 'isDefault' => false],
            ['id' => 'v2', 'isDefault' => false],
        ];

        $this->assertSame('v1', $this->builder->findDefaultViewId($views));
    }//end testFindDefaultViewIdFallsBackToFirst()

    /**
     * Test findDefaultViewId returns null for empty views.
     *
     * @return void
     */
    public function testFindDefaultViewIdEmptyReturnsNull(): void
    {
        $this->assertNull($this->builder->findDefaultViewId([]));
    }//end testFindDefaultViewIdEmptyReturnsNull()
}//end class
