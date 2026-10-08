<?php

/**
 * Unit tests for TaskCreatedByCreatingListener.
 *
 * Drives the listener with OpenRegister's ObjectCreatingEvent (the stub that
 * mirrors production member for member) and reads back what MagicMapper
 * would merge: getModifiedData().
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/round3-review-points/specs/user-fields/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\Pipelinq\Listener\TaskCreatedByCreatingListener;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * A new task records who created it.
 */
class TaskCreatedByCreatingListenerTest extends TestCase {

	/**
	 * The listener with `cluade` signed in, or nobody.
	 *
	 * @param bool $signedIn Whether a user is signed in.
	 *
	 * @return TaskCreatedByCreatingListener The listener.
	 */
	private function listener(bool $signedIn = true): TaskCreatedByCreatingListener {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => [
				'task_schema' => '35',
			][$key] ?? $default
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('cluade');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $user : null);

		return new TaskCreatedByCreatingListener($config, $session);
	}//end listener()

	/**
	 * An entity for the given schema and data.
	 *
	 * @param string               $schema The schema id.
	 * @param array<string, mixed> $data   The object data.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('task-1');
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * A task created without a creator gets the signed-in user, and an earlier hook's change survives.
	 *
	 * @return void
	 */
	public function testNewTaskGetsTheCreatingUser(): void {
		$event = new ObjectCreatingEvent($this->entity('35', ['subject' => 'Call back']));
		$event->setModifiedData(['status' => 'open']);

		$this->listener()->handle($event);

		$this->assertSame('cluade', $event->getModifiedData()['createdBy'] ?? null);
		$this->assertSame('open', $event->getModifiedData()['status']);
	}//end testNewTaskGetsTheCreatingUser()

	/**
	 * A creator that is already named is kept.
	 *
	 * @return void
	 */
	public function testAGivenCreatorIsKept(): void {
		$event = new ObjectCreatingEvent($this->entity('35', ['subject' => 'Visit', 'createdBy' => 'agent']));

		$this->listener()->handle($event);

		$this->assertArrayNotHasKey('createdBy', $event->getModifiedData());
	}//end testAGivenCreatorIsKept()

	/**
	 * Other schemas, and a create without a signed-in user, are not touched.
	 *
	 * @return void
	 */
	public function testOtherSchemasAndBackgroundCreatesAreIgnored(): void {
		$other = new ObjectCreatingEvent($this->entity('30', ['title' => 'A lead']));
		$this->listener()->handle($other);
		$this->assertSame([], $other->getModifiedData());

		$background = new ObjectCreatingEvent($this->entity('35', ['subject' => 'Job']));
		$this->listener(signedIn: false)->handle($background);
		$this->assertSame([], $background->getModifiedData());
	}//end testOtherSchemasAndBackgroundCreatesAreIgnored()

	/**
	 * The application registers the listener for OpenRegister's creating event.
	 *
	 * @return void
	 */
	public function testTheApplicationRegistersTheListener(): void {
		$source = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/AppInfo/Application.php');
		$this->assertMatchesRegularExpression(
			'/event:\s*ObjectCreatingEvent::class,\s*listener:\s*TaskCreatedByCreatingListener::class/',
			$source
		);
	}//end testTheApplicationRegistersTheListener()
}//end class
