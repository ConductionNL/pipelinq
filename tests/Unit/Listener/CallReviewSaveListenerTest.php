<?php

/**
 * Pipelinq CallReviewSaveListenerTest.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/contact-moments-call-review/specs/call-review/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Pipelinq\Listener\CallReviewSaveListener;
use OCA\Pipelinq\Service\CallReview\CallReviewTotal;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A call review gets its total and status on every save; other objects are left alone.
 *
 * @covers \OCA\Pipelinq\Listener\CallReviewSaveListener
 * @uses \OCA\Pipelinq\Service\CallReview\CallReviewTotal
 */
class CallReviewSaveListenerTest extends TestCase {

	/**
	 * The find() calls made: [id, register, schema].
	 *
	 * @var list<array{0: mixed, 1: mixed, 2: mixed}>
	 */
	private array $finds = [];

	/**
	 * The listener over a scorecard store.
	 *
	 * @param bool $scorecardReadable Whether reading the scorecard works.
	 *
	 * @return CallReviewSaveListener
	 */
	private function listener(bool $scorecardReadable = true): CallReviewSaveListener {
		$this->finds = [];
		$scorecard = new ObjectEntity();
		$scorecard->setUuid('sc-1');
		$scorecard->setObject(
			[
				'title' => 'Klantcontact',
				'criteria' => [
					['key' => 'greeting', 'weight' => 1],
					['key' => 'answer', 'weight' => 2],
					['key' => 'closing', 'weight' => 1],
				],
			]
		);

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null) use ($scorecard, $scorecardReadable): ?ObjectEntity {
				$this->finds[] = [$id, $register, $schema];
				if ($scorecardReadable === false) {
					throw new RuntimeException('register down');
				}

				return $id === 'sc-1' ? $scorecard : null;
			}
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'register' => '5',
				'callReview_schema' => '41',
				'callScorecard_schema' => '40',
				default => $default,
			}
		);

		return new CallReviewSaveListener(
			objectService: $objects,
			appConfig: $config,
			rules: new CallReviewTotal(),
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end listener()

	/**
	 * An object of a schema.
	 *
	 * @param string               $schema The schema id.
	 * @param array<string, mixed> $data   The object data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * A new review gets the weighted total of its scores on the scorecard read from the register.
	 *
	 * @return void
	 */
	public function testANewReviewGetsItsTotal(): void {
		$event = new ObjectCreatingEvent(
			$this->entity(
				schema: '41',
				data: [
					'scorecard' => 'sc-1',
					'scores' => [['key' => 'greeting', 'score' => 4], ['key' => 'answer', 'score' => 3], ['key' => 'closing', 'score' => 5]],
					'status' => 'shared',
					'total' => 99,
				]
			)
		);

		$this->listener()->handle($event);

		$this->assertSame(['total' => 3.8, 'status' => 'shared'], $event->getModifiedData());
		$this->assertSame([['sc-1', '5', '40']], $this->finds);
	}//end testANewReviewGetsItsTotal()

	/**
	 * An update merges its changes over the stored review: the agent agreeing
	 * the last point makes the review agreed, and other modified data stays.
	 *
	 * @return void
	 */
	public function testAnUpdateAgreeingEveryPointMakesTheReviewAgreed(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity(
				schema: '41',
				data: [
					'scorecard' => 'sc-1',
					'scores' => [['key' => 'answer', 'score' => 4]],
					'status' => 'shared',
					'coachingPoints' => [['text' => 'Noem je naam', 'agreedAt' => '2026-11-20T10:00:00+01:00'], ['text' => 'Vat samen']],
				]
			)
		);
		$event->setModifiedData(['coachingPoints' => [['text' => 'Noem je naam', 'agreedAt' => '2026-11-20T10:00:00+01:00'], ['text' => 'Vat samen', 'agreedAt' => '2026-11-20T10:05:00+01:00']]]);

		$this->listener()->handle($event);

		$modified = $event->getModifiedData();
		$this->assertSame('agreed', $modified['status']);
		$this->assertSame(4.0, $modified['total']);
		$this->assertCount(2, $modified['coachingPoints']);
	}//end testAnUpdateAgreeingEveryPointMakesTheReviewAgreed()

	/**
	 * An object of another schema is left alone and nothing is read.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsLeftAlone(): void {
		$event = new ObjectCreatingEvent($this->entity(schema: '7', data: ['scorecard' => 'sc-1', 'scores' => [['key' => 'answer', 'score' => 4]]]));

		$this->listener()->handle($event);

		$this->assertSame([], $event->getModifiedData());
		$this->assertSame([], $this->finds);
	}//end testAnotherSchemaIsLeftAlone()

	/**
	 * A scorecard that cannot be read, or none at all, gives no total; the save goes on.
	 *
	 * @return void
	 */
	public function testAnUnreadableScorecardGivesNoTotal(): void {
		$event = new ObjectCreatingEvent($this->entity(schema: '41', data: ['scorecard' => 'sc-1', 'scores' => [['key' => 'answer', 'score' => 4]]]));
		$this->listener(scorecardReadable: false)->handle($event);
		$this->assertSame(['total' => null, 'status' => 'draft'], $event->getModifiedData());

		$event = new ObjectCreatingEvent($this->entity(schema: '41', data: ['scores' => [['key' => 'answer', 'score' => 4]]]));
		$listener = $this->listener();
		$listener->handle($event);
		$this->assertNull($event->getModifiedData()['total']);
		$this->assertSame([], $this->finds);
	}//end testAnUnreadableScorecardGivesNoTotal()
}//end class
