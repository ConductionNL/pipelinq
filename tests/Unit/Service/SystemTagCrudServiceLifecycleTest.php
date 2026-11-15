<?php

/**
 * Unit tests for the create, unassign and rename paths of SystemTagCrudService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\SystemTagCrudService;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagAlreadyExistsException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Creating, reusing, unassigning and renaming system tags.
 *
 * @covers \OCA\Pipelinq\Service\SystemTagCrudService
 */
class SystemTagCrudServiceLifecycleTest extends TestCase {

	/**
	 * Mock tag manager.
	 *
	 * @var ISystemTagManager&MockObject
	 */
	private ISystemTagManager&MockObject $tagManager;

	/**
	 * Mock tag mapper.
	 *
	 * @var ISystemTagObjectMapper&MockObject
	 */
	private ISystemTagObjectMapper&MockObject $tagMapper;

	/**
	 * The service under test.
	 *
	 * @var SystemTagCrudService
	 */
	private SystemTagCrudService $service;

	/**
	 * Set up the test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->tagManager = $this->createMock(ISystemTagManager::class);
		$this->tagMapper = $this->createMock(ISystemTagObjectMapper::class);
		$this->service = new SystemTagCrudService($this->tagManager, $this->tagMapper);
	}//end setUp()

	/**
	 * A new name creates a visible, non-assignable tag.
	 *
	 * @return void
	 */
	public function testCreateOrReuseCreatesANewTag(): void {
		$tag = $this->createMock(ISystemTag::class);
		$this->tagManager->expects($this->once())->method('createTag')
			->with('Website', true, false)
			->willReturn($tag);
		$this->tagManager->expects($this->never())->method('getTag');

		$this->assertSame($tag, $this->service->createOrReuseSystemTag('Website'));
	}//end testCreateOrReuseCreatesANewTag()

	/**
	 * An existing name returns the existing tag instead of failing.
	 *
	 * @return void
	 */
	public function testCreateOrReuseReturnsTheExistingTag(): void {
		$existing = $this->createMock(ISystemTag::class);
		$this->tagManager->method('createTag')->willThrowException(new TagAlreadyExistsException('exists'));
		$this->tagManager->expects($this->once())->method('getTag')
			->with('Website', true, false)
			->willReturn($existing);

		$this->assertSame($existing, $this->service->createOrReuseSystemTag('Website'));
	}//end testCreateOrReuseReturnsTheExistingTag()

	/**
	 * A tag no other pipelinq type uses is deleted after unassigning.
	 *
	 * @return void
	 */
	public function testUnassignDeletesATagNoOtherTypeUses(): void {
		$this->tagMapper->expects($this->once())->method('unassignTags')
			->with('pipelinq_lead_source', 'pipelinq_lead_source', ['7']);
		$this->tagMapper->expects($this->once())->method('getTagIdsForObjects')
			->with(['pipelinq_request_channel'], 'pipelinq_request_channel')
			->willReturn(['pipelinq_request_channel' => [3, 8]]);
		$this->tagManager->expects($this->once())->method('deleteTags')->with(['7']);

		$this->service->unassignAndCleanup('pipelinq_lead_source', 7);
	}//end testUnassignDeletesATagNoOtherTypeUses()

	/**
	 * A tag still used by another pipelinq type is kept.
	 *
	 * @return void
	 */
	public function testUnassignKeepsATagAnotherTypeStillUses(): void {
		$this->tagMapper->expects($this->once())->method('unassignTags')
			->with('pipelinq_request_channel', 'pipelinq_request_channel', ['7']);
		$this->tagMapper->expects($this->once())->method('getTagIdsForObjects')
			->with(['pipelinq_lead_source'], 'pipelinq_lead_source')
			->willReturn(['pipelinq_lead_source' => [7]]);
		$this->tagManager->expects($this->never())->method('deleteTags');

		$this->service->unassignAndCleanup('pipelinq_request_channel', 7);
	}//end testUnassignKeepsATagAnotherTypeStillUses()

	/**
	 * The usage check compares ids strictly, so a string id is no match.
	 *
	 * @return void
	 */
	public function testUnassignComparesTagIdsStrictly(): void {
		$this->tagMapper->method('getTagIdsForObjects')
			->willReturn(['pipelinq_request_channel' => ['7']]);
		$this->tagManager->expects($this->once())->method('deleteTags')->with(['7']);

		$this->service->unassignAndCleanup('pipelinq_lead_source', 7);
	}//end testUnassignComparesTagIdsStrictly()

	/**
	 * Unassigning from a type outside the known list checks both known types.
	 *
	 * @return void
	 */
	public function testUnassignFromAnUnknownTypeChecksEveryKnownType(): void {
		$this->tagMapper->expects($this->exactly(2))->method('getTagIdsForObjects')
			->willReturn([]);
		$this->tagManager->expects($this->once())->method('deleteTags')->with(['4']);

		$this->service->unassignAndCleanup('other_type', 4);
	}//end testUnassignFromAnUnknownTypeChecksEveryKnownType()

	/**
	 * Renaming keeps the tag's existing assignable flag.
	 *
	 * @return void
	 */
	public function testRenameKeepsTheAssignableFlag(): void {
		$tag = $this->createMock(ISystemTag::class);
		$tag->method('isUserAssignable')->willReturn(true);
		$this->tagManager->method('getTagsByIds')->with(['5'])->willReturn(['5' => $tag]);
		$this->tagManager->expects($this->once())->method('updateTag')
			->with('5', 'Renamed', true, true, null);

		$this->service->renameSystemTag(5, 'Renamed');
	}//end testRenameKeepsTheAssignableFlag()

	/**
	 * Renaming an unknown tag treats it as not assignable.
	 *
	 * @return void
	 */
	public function testRenameOfAnUnknownTagIsNotAssignable(): void {
		$this->tagManager->method('getTagsByIds')->willReturn([]);
		$this->tagManager->expects($this->once())->method('updateTag')
			->with('5', 'Renamed', true, false, null);

		$this->service->renameSystemTag(5, 'Renamed');
	}//end testRenameOfAnUnknownTagIsNotAssignable()
}//end class
