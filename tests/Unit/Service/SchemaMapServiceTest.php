<?php

/**
 * Unit tests for SchemaMapService.
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

use OCA\Pipelinq\Service\SchemaMapService;
use OCA\Pipelinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for SchemaMapService.
 */
class SchemaMapServiceTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var SchemaMapService
     */
    private SchemaMapService $service;

    /**
     * Mock settings service.
     *
     * @var SettingsService
     */
    private SettingsService $settingsService;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $logger                = $this->createMock(LoggerInterface::class);

        $this->service = new SchemaMapService($this->settingsService, $logger);
    }//end setUp()

    /**
     * Test resolveEntityType returns null for null input.
     *
     * @return void
     */
    public function testResolveEntityTypeReturnsNullForNull(): void
    {
        $this->assertNull($this->service->resolveEntityType(null));
    }//end testResolveEntityTypeReturnsNullForNull()

    /**
     * Test resolveEntityType returns null for empty string.
     *
     * @return void
     */
    public function testResolveEntityTypeReturnsNullForEmpty(): void
    {
        $this->assertNull($this->service->resolveEntityType(''));
    }//end testResolveEntityTypeReturnsNullForEmpty()

    /**
     * Test resolveEntityType resolves client schema.
     *
     * @return void
     */
    public function testResolveEntityTypeResolvesClient(): void
    {
        $this->settingsService->method('getSettings')->willReturn([
            'client_schema'   => '100',
            'contact_schema'  => '101',
            'lead_schema'     => '102',
            'request_schema'  => '103',
            'pipeline_schema' => '104',
        ]);

        $this->assertSame('client', $this->service->resolveEntityType('100'));
        $this->assertSame('lead', $this->service->resolveEntityType('102'));
        $this->assertSame('pipeline', $this->service->resolveEntityType('104'));
    }//end testResolveEntityTypeResolvesClient()

    /**
     * Test resolveEntityType returns null for unknown schema.
     *
     * @return void
     */
    public function testResolveEntityTypeReturnsNullForUnknown(): void
    {
        $this->settingsService->method('getSettings')->willReturn([
            'client_schema' => '100',
        ]);

        $this->assertNull($this->service->resolveEntityType('999'));
    }//end testResolveEntityTypeReturnsNullForUnknown()

    /**
     * Test resolveEntityType caches the schema map.
     *
     * @return void
     */
    public function testResolveEntityTypeCachesMap(): void
    {
        $this->settingsService->expects($this->once())->method('getSettings')->willReturn([
            'client_schema' => '100',
        ]);

        // Call twice, should only call getSettings once.
        $this->service->resolveEntityType('100');
        $this->service->resolveEntityType('100');
    }//end testResolveEntityTypeCachesMap()
}//end class
