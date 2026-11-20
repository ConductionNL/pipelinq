<?php

/**
 * Unit tests for the write paths of SystemTagService.
 *
 * Covers adding, renaming and removing a tag (names are trimmed and must be
 * unique per object type, case-insensitively, except against the tag being
 * renamed) and seeding the default tags, where one failing default does not
 * stop the others.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\SystemTagCrudService;
use OCA\Pipelinq\Service\SystemTagService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for SystemTagService write paths.
 */
class SystemTagServiceWritesTest extends TestCase {

	/**
	 * Mock tag store.
	 *
	 * @var SystemTagCrudService&MockObject
	 */
	private SystemTagCrudService&MockObject $crud;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Service under test.
	 *
	 * @var SystemTagService
	 */
	private SystemTagService $service;

	/**
	 * Wire the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->crud = $this->createMock(originalClassName: SystemTagCrudService::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->service = new SystemTagService(tagCrudService: $this->crud, logger: $this->logger);
	}//end setUp()

	/**
	 * The existing tags of the object type.
	 *
	 * @param array<int, array{id: int, name: string}> $tags The tags.
	 *
	 * @return void
	 */
	private function existing(array $tags): void {
		$this->crud->method('getTagIdsForType')->willReturn(array_column($tags, 'id'));
		$this->crud->method('resolveTagData')->willReturn($tags);
	}//end existing()

	/**
	 * A tag object as the store returns it.
	 *
	 * @param int $id The id.
	 * @param string $name The name.
	 *
	 * @return object
	 */
	private static function tag(int $id, string $name): object {
		return new class($id, $name) {
			/**
			 * Store the tag.
			 *
			 * @param int $id The id.
			 * @param string $name The name.
			 */
			public function __construct(private int $id, private string $name) {
			}

			/**
			 * The id.
			 *
			 * @return int
			 */
			public function getId(): int {
				return $this->id;
			}

			/**
			 * The name.
			 *
			 * @return string
			 */
			public function getName(): string {
				return $this->name;
			}
		};
	}//end tag()

	/**
	 * A new name is trimmed, created and assigned to the object type.
	 *
	 * @return void
	 */
	public function testAddsATrimmedTag(): void {
		$this->existing(tags: [['id' => 1, 'name' => 'Lead']]);
		$this->crud->expects(self::once())->method('createOrReuseSystemTag')->with('Prospect')
			->willReturn(self::tag(id: 7, name: 'Prospect'));
		$this->crud->expects(self::once())->method('assignTag')->with('lead', 7);

		self::assertSame(
			expected: ['id' => 7, 'name' => 'Prospect'],
			actual: $this->service->addTag(objectType: 'lead', name: '  Prospect ')
		);
	}//end testAddsATrimmedTag()

	/**
	 * A rename keeps the tag's own name available but refuses another tag's name.
	 *
	 * @return void
	 */
	public function testRenameIsUniqueExceptForItself(): void {
		$this->existing(tags: [['id' => 1, 'name' => 'Lead'], ['id' => 2, 'name' => 'Klant']]);
		$this->crud->expects(self::once())->method('renameSystemTag')->with(1, 'LEAD');

		self::assertSame(
			expected: ['id' => 1, 'name' => 'LEAD'],
			actual: $this->service->renameTag(objectType: 'lead', tagId: 1, newName: ' LEAD ')
		);

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'This tag already exists');

		$this->service->renameTag(objectType: 'lead', tagId: 1, newName: 'klant');
	}//end testRenameIsUniqueExceptForItself()

	/**
	 * Removing a tag unassigns it and cleans it up.
	 *
	 * @return void
	 */
	public function testRemovesATag(): void {
		$this->crud->expects(self::once())->method('unassignAndCleanup')->with('lead', 3);
		$this->logger->expects(self::once())->method('info')->with('Pipelinq: Removed lead tag ID: 3');

		$this->service->removeTag(objectType: 'lead', tagId: 3);
	}//end testRemovesATag()

	/**
	 * Only missing defaults are created, and a failing one is logged without stopping the rest.
	 *
	 * @return void
	 */
	public function testSeedsOnlyMissingDefaults(): void {
		$this->existing(tags: [['id' => 1, 'name' => 'Lead']]);
		$created = [];
		$this->crud->method('createOrReuseSystemTag')->willReturnCallback(
			static function (string $name) use (&$created): object {
				if ($name === 'Broken') {
					throw new RuntimeException('db down');
				}

				$created[] = $name;
				return self::tag(id: count($created) + 10, name: $name);
			}
		);
		$this->logger->expects(self::once())->method('warning')
			->with("Pipelinq: Failed to create default lead tag 'Broken': db down");

		$this->service->ensureDefaults(objectType: 'lead', defaults: ['lead', 'Broken', 'Klant']);

		self::assertSame(expected: ['Klant'], actual: $created);
	}//end testSeedsOnlyMissingDefaults()
}//end class
