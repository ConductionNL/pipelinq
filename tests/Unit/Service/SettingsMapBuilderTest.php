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
     * Test that buildSchemaSlugMap extracts slug-to-ID mappings from plain arrays.
     *
     * @return void
     */
    public function testBuildSchemaSlugMapFromArrays(): void
    {
        $schemas = [
            ['slug' => 'client', 'id' => 10, 'title' => 'Client'],
            ['slug' => 'lead', 'id' => 20, 'title' => 'Lead'],
            ['slug' => 'contact', 'id' => 30, 'title' => 'Contact'],
        ];

        $map = $this->builder->buildSchemaSlugMap($schemas);

        $this->assertArrayHasKey('client', $map);
        $this->assertArrayHasKey('lead', $map);
        $this->assertArrayHasKey('contact', $map);
        $this->assertEquals(10, $map['client']);
        $this->assertEquals(20, $map['lead']);
        $this->assertEquals(30, $map['contact']);
    }//end testBuildSchemaSlugMapFromArrays()

    /**
     * Test that buildSchemaSlugMap uses uuid if id is missing.
     *
     * @return void
     */
    public function testBuildSchemaSlugMapUsesUuid(): void
    {
        $schemas = [
            ['slug' => 'product', 'uuid' => 'abc-123', 'title' => 'Product'],
        ];

        $map = $this->builder->buildSchemaSlugMap($schemas);

        $this->assertArrayHasKey('product', $map);
        $this->assertEquals('abc-123', $map['product']);
    }//end testBuildSchemaSlugMapUsesUuid()

    /**
     * Test that buildSchemaSlugMap handles empty input.
     *
     * @return void
     */
    public function testBuildSchemaSlugMapEmpty(): void
    {
        $map = $this->builder->buildSchemaSlugMap([]);

        $this->assertEmpty($map);
    }//end testBuildSchemaSlugMapEmpty()

    /**
     * Test that findRegisterIdBySlug returns the pipelinq register ID.
     *
     * @return void
     */
    public function testFindRegisterIdBySlug(): void
    {
        $registers = [
            ['slug' => 'other', 'id' => 1],
            ['slug' => 'pipelinq', 'id' => 42],
        ];

        $result = $this->builder->findRegisterIdBySlug($registers);

        $this->assertEquals(42, $result);
    }//end testFindRegisterIdBySlug()

    /**
     * Test that findRegisterIdBySlug returns null when not found.
     *
     * @return void
     */
    public function testFindRegisterIdBySlugNotFound(): void
    {
        $registers = [
            ['slug' => 'other', 'id' => 1],
        ];

        $result = $this->builder->findRegisterIdBySlug($registers);

        $this->assertNull($result);
    }//end testFindRegisterIdBySlugNotFound()

    /**
     * Test that findRegisterIdBySlug handles empty registers.
     *
     * @return void
     */
    public function testFindRegisterIdBySlugEmpty(): void
    {
        $result = $this->builder->findRegisterIdBySlug([]);

        $this->assertNull($result);
    }//end testFindRegisterIdBySlugEmpty()

    /**
     * Test that findDefaultViewId returns default view ID.
     *
     * @return void
     */
    public function testFindDefaultViewId(): void
    {
        $views = [
            ['id' => 1, 'isDefault' => false],
            ['id' => 7, 'isDefault' => true],
        ];

        $result = $this->builder->findDefaultViewId($views);

        $this->assertEquals(7, $result);
    }//end testFindDefaultViewId()

    /**
     * Test that findDefaultViewId falls back to first view.
     *
     * @return void
     */
    public function testFindDefaultViewIdFallback(): void
    {
        $views = [
            ['id' => 5, 'isDefault' => false],
            ['id' => 8, 'isDefault' => false],
        ];

        $result = $this->builder->findDefaultViewId($views);

        $this->assertEquals(5, $result);
    }//end testFindDefaultViewIdFallback()

    /**
     * Test that findDefaultViewId returns null for empty views.
     *
     * @return void
     */
    public function testFindDefaultViewIdEmpty(): void
    {
        $result = $this->builder->findDefaultViewId([]);

        $this->assertNull($result);
    }//end testFindDefaultViewIdEmpty()
}//end class
