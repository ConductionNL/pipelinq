<?php

/**
 * Unit tests for SystemTagActions.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\SystemTagActions;
use OCA\Pipelinq\Controller\SystemTagKind;
use OCA\Pipelinq\Kernel\Contracts\SystemTagServiceInterface;
use OCP\AppFramework\Http;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * SystemTagActions answers the tag endpoints for one object type.
 */
class SystemTagActionsTest extends TestCase {

	/**
	 * Actions for the "lead" tags over a service double.
	 *
	 * @param SystemTagServiceInterface $service The tag service.
	 *
	 * @return SystemTagActions
	 */
	private static function actions(SystemTagServiceInterface $service): SystemTagActions {
		return new SystemTagActions(systemTagService: $service, kind: new SystemTagKind(objectType: 'lead', logLabel: 'LeadTagController'));
	}//end actions()

	/**
	 * The list needs a user and asks for this object type's tags.
	 *
	 * @return void
	 */
	public function testTheList(): void {
		$service = $this->createMock(SystemTagServiceInterface::class);
		$service->method('getTags')->with('lead')->willReturn([['id' => 1, 'name' => 'warm']]);

		$response = self::actions($service)->index(user: $this->createMock(IUser::class));
		self::assertSame(['success' => true, 'tags' => [['id' => 1, 'name' => 'warm']]], $response->getData());

		$anonymous = self::actions($service)->index(user: null);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $anonymous->getStatus());
	}//end testTheList()

	/**
	 * Creating and renaming answer the tag, or a 400 with the service's reason.
	 *
	 * @return void
	 */
	public function testCreateAndRename(): void {
		$service = $this->createMock(SystemTagServiceInterface::class);
		$service->method('addTag')->willReturnCallback(static function (string $objectType, string $name): array {
			if ($name === '') {
				throw new \InvalidArgumentException('Tag name required');
			}

			return ['id' => 2, 'name' => $name, 'type' => $objectType];
		});
		$service->method('renameTag')->willReturnCallback(static function (string $objectType, int $tagId, string $newName): array {
			if ($tagId === 99) {
				throw new RuntimeException('Tag not found');
			}

			return ['id' => $tagId, 'name' => $newName];
		});
		$actions = self::actions($service);

		self::assertSame(['success' => true, 'tag' => ['id' => 2, 'name' => 'koud', 'type' => 'lead']], $actions->create(name: 'koud')->getData());
		$refused = $actions->create(name: '');
		self::assertSame(400, $refused->getStatus());
		self::assertSame(['success' => false, 'message' => 'Tag name required'], $refused->getData());

		self::assertSame(['success' => true, 'tag' => ['id' => 5, 'name' => 'heet']], $actions->rename(id: '5', name: 'heet')->getData());
		self::assertSame(['success' => false, 'message' => 'Tag not found'], $actions->rename(id: '99', name: 'x')->getData());
	}//end testCreateAndRename()

	/**
	 * Deleting answers success, or logs and hides an unexpected failure.
	 *
	 * @return void
	 */
	public function testDelete(): void {
		$service = $this->createMock(SystemTagServiceInterface::class);
		$service->method('removeTag')->willReturnCallback(static function (string $objectType, int $tagId): void {
			if ($tagId === 7) {
				throw new \Exception('database gone');
			}
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error')->with('LeadTagController::destroy failed', self::anything());

		self::assertSame(['success' => true], self::actions($service)->delete(id: '3', logger: $logger)->getData());
		$failed = self::actions($service)->delete(id: '7', logger: $logger);
		self::assertSame(500, $failed->getStatus());
		self::assertSame(['success' => false, 'message' => 'An unexpected error occurred'], $failed->getData());
	}//end testDelete()
}//end class
