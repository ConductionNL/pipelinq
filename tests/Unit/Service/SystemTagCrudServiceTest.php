<?php

/**
 * Unit tests for SystemTagCrudService.
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

use OCA\Pipelinq\Service\SystemTagCrudService;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SystemTagCrudService.
 */
class SystemTagCrudServiceTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var SystemTagCrudService
     */
    private SystemTagCrudService $service;

    /**
     * Mock tag manager.
     *
     * @var ISystemTagManager
     */
    private ISystemTagManager $tagManager;

    /**
     * Mock tag mapper.
     *
     * @var ISystemTagObjectMapper
     */
    private ISystemTagObjectMapper $tagMapper;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->tagManager = $this->createMock(ISystemTagManager::class);
        $this->tagMapper  = $this->createMock(ISystemTagObjectMapper::class);

        $this->service = new SystemTagCrudService($this->tagManager, $this->tagMapper);
    }//end setUp()

    /**
     * Test getTagIdsForType returns empty for no tags.
     *
     * @return void
     */
    public function testGetTagIdsForTypeEmpty(): void
    {
        $this->tagMapper->method('getTagIdsForObjects')
            ->willReturn(['pipelinq_lead_source' => []]);

        $this->assertSame([], $this->service->getTagIdsForType('pipelinq_lead_source'));
    }//end testGetTagIdsForTypeEmpty()

    /**
     * Test getTagIdsForType returns tag IDs.
     *
     * @return void
     */
    public function testGetTagIdsForTypeReturnsIds(): void
    {
        $this->tagMapper->method('getTagIdsForObjects')
            ->willReturn(['pipelinq_lead_source' => [1, 2, 3]]);

        $result = $this->service->getTagIdsForType('pipelinq_lead_source');

        $this->assertSame([1, 2, 3], $result);
    }//end testGetTagIdsForTypeReturnsIds()

    /**
     * Test resolveTagData returns tag data.
     *
     * @return void
     */
    public function testResolveTagDataReturnsData(): void
    {
        $tag1 = new class {
            public function getId(): string { return '1'; }
            public function getName(): string { return 'Website'; }
        };
        $tag2 = new class {
            public function getId(): string { return '2'; }
            public function getName(): string { return 'Referral'; }
        };

        $this->tagManager->method('getTagsByIds')->willReturn([$tag1, $tag2]);

        $result = $this->service->resolveTagData([1, 2]);

        $this->assertCount(2, $result);
        $this->assertSame(1, $result[0]['id']);
        $this->assertSame('Website', $result[0]['name']);
    }//end testResolveTagDataReturnsData()

    /**
     * Test assignTag delegates to mapper.
     *
     * @return void
     */
    public function testAssignTagDelegates(): void
    {
        $this->tagMapper->expects($this->once())
            ->method('assignTags')
            ->with('pipelinq_lead_source', 'pipelinq_lead_source', [5]);

        $this->service->assignTag('pipelinq_lead_source', 5);
    }//end testAssignTagDelegates()

    /**
     * Test renameSystemTag delegates to manager.
     *
     * @return void
     */
    public function testRenameSystemTagDelegates(): void
    {
        $this->tagManager->expects($this->once())
            ->method('updateTag')
            ->with('5', 'NewName', true, false, null);

        $this->service->renameSystemTag(5, 'NewName');
    }//end testRenameSystemTagDelegates()
}//end class
