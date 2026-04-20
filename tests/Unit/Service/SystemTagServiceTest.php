<?php

/**
 * Unit tests for SystemTagService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\SystemTagCrudService;
use OCA\Pipelinq\Service\SystemTagService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for SystemTagService.
 */
class SystemTagServiceTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var SystemTagService
     */
    private SystemTagService $service;

    /**
     * Mock CRUD service.
     *
     * @var SystemTagCrudService
     */
    private SystemTagCrudService $crudService;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->crudService = $this->createMock(SystemTagCrudService::class);
        $logger            = $this->createMock(LoggerInterface::class);

        $this->service = new SystemTagService($this->crudService, $logger);
    }//end setUp()

    /**
     * Test getTags returns empty for no tags.
     *
     * @return void
     */
    public function testGetTagsReturnsEmptyForNoTags(): void
    {
        $this->crudService->method('getTagIdsForType')->willReturn([]);

        $this->assertSame([], $this->service->getTags('pipelinq_lead_source'));
    }//end testGetTagsReturnsEmptyForNoTags()

    /**
     * Test getTags returns sorted tags.
     *
     * @return void
     */
    public function testGetTagsReturnsSorted(): void
    {
        $this->crudService->method('getTagIdsForType')->willReturn([1, 2]);
        $this->crudService->method('resolveTagData')->willReturn([
            ['id' => 2, 'name' => 'Zebra'],
            ['id' => 1, 'name' => 'Alpha'],
        ]);

        $result = $this->service->getTags('pipelinq_lead_source');

        $this->assertSame('Alpha', $result[0]['name']);
        $this->assertSame('Zebra', $result[1]['name']);
    }//end testGetTagsReturnsSorted()

    /**
     * Test addTag throws on empty name.
     *
     * @return void
     */
    public function testAddTagThrowsOnEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->addTag('pipelinq_lead_source', '');
    }//end testAddTagThrowsOnEmptyName()

    /**
     * Test addTag throws on duplicate name.
     *
     * @return void
     */
    public function testAddTagThrowsOnDuplicate(): void
    {
        $this->crudService->method('getTagIdsForType')->willReturn([1]);
        $this->crudService->method('resolveTagData')->willReturn([
            ['id' => 1, 'name' => 'Website'],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('This tag already exists');

        $this->service->addTag('pipelinq_lead_source', 'website');
    }//end testAddTagThrowsOnDuplicate()

    /**
     * Test renameTag throws on empty name.
     *
     * @return void
     */
    public function testRenameTagThrowsOnEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->renameTag('pipelinq_lead_source', 1, '  ');
    }//end testRenameTagThrowsOnEmptyName()
}//end class
