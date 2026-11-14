<?php

/**
 * Pipelinq CallReviewControllerTest.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
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
 * @spec openspec/changes/contact-moments-call-review/specs/call-review/spec.md#requirement-the-agent-agrees-the-coaching-points-req-crv-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Controller\CallReviewController;
use OCA\Pipelinq\Service\CallReview\CoachingPointAgreement;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The agent agrees a coaching point of a review of their call.
 *
 * @covers \OCA\Pipelinq\Controller\CallReviewController
 * @uses   \OCA\Pipelinq\Service\CallReview\CoachingPointAgreement
 */
class CallReviewControllerTest extends TestCase {

	private const NOW = '2026-11-14T10:00:00+00:00';

	/**
	 * The OpenRegister object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The controller for a caller, with a stored review.
	 *
	 * @param string|null       $uid    The caller, null for nobody logged in.
	 * @param array<string,mixed>|null $review The stored review, null for none.
	 *
	 * @return CallReviewController
	 */
	private function controller(?string $uid, ?array $review): CallReviewController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => ['register' => '7', 'callReview_schema' => '42'][$key] ?? ''
		);

		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$entity = null;
		if ($review !== null) {
			$entity = new ObjectEntity();
			$entity->setUuid('r1');
			$entity->setObject($review);
		}

		$this->objects->method('find')->willReturn($entity);

		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('now')->willReturn(new DateTimeImmutable(self::NOW));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new CallReviewController(
			request: $this->createMock(IRequest::class),
			userSession: $session,
			appConfig: $config,
			objectService: $this->objects,
			agreement: new CoachingPointAgreement(),
			timeFactory: $clock,
			l10n: $l10n,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end controller()

	/**
	 * A shared review of Sanne's call with two points.
	 *
	 * @return array<string, mixed>
	 */
	private static function review(): array {
		return [
			'agent'          => 'sanne',
			'status'         => 'shared',
			'coachingPoints' => [['text' => 'Name the next step'], ['text' => 'Close warmly']],
		];
	}//end review()

	/**
	 * Scenario "Sanne agrees both points": the point is dated and saved as the caller, in the review's register and schema.
	 *
	 * @return void
	 */
	public function testTheAgentAgreesAPoint(): void {
		$controller = $this->controller(uid: 'sanne', review: self::review());
		$stored = new ObjectEntity();
		$stored->setUuid('r1');
		$stored->setObject(['status' => 'shared']);
		$this->objects->expects($this->once())->method('saveObject')
			->with(
				$this->callback(static function (array $object): bool {
					return $object['coachingPoints'][0] === ['text' => 'Name the next step', 'agreedAt' => self::NOW]
						&& $object['coachingPoints'][1] === ['text' => 'Close warmly']
						&& isset($object['@self']) === false;
				}),
				$this->anything(),
				'7',
				'42',
				'r1'
			)
			->willReturn($stored);

		$response = $controller->agree(id: 'r1', index: 0);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('shared', $response->getData()['status']);
		$this->assertSame('r1', $response->getData()['id']);
	}//end testTheAgentAgreesAPoint()

	/**
	 * Another agent is refused with 403 and nothing is written.
	 *
	 * @return void
	 */
	public function testAnotherAgentIsRefused(): void {
		$controller = $this->controller(uid: 'mehmet', review: self::review());
		$this->objects->expects($this->never())->method('saveObject');

		$response = $controller->agree(id: 'r1', index: 0);

		$this->assertSame(403, $response->getStatus());
		$this->assertSame('Only the agent whose call was reviewed can agree a coaching point.', $response->getData()['message']);
	}//end testAnotherAgentIsRefused()

	/**
	 * A draft is 409, a missing point and a missing review 404, nobody logged in 401; nothing is written.
	 *
	 * @return void
	 */
	public function testRefusalsWriteNothing(): void {
		$draft = $this->controller(uid: 'sanne', review: ['status' => 'draft'] + self::review());
		$this->objects->expects($this->never())->method('saveObject');
		$this->assertSame(409, $draft->agree(id: 'r1', index: 0)->getStatus());

		$point = $this->controller(uid: 'sanne', review: self::review());
		$this->objects->expects($this->never())->method('saveObject');
		$this->assertSame(404, $point->agree(id: 'r1', index: 3)->getStatus());

		$missing = $this->controller(uid: 'sanne', review: null);
		$this->objects->expects($this->never())->method('saveObject');
		$this->assertSame('This review was not found.', $missing->agree(id: 'r1', index: 0)->getData()['message']);

		$this->assertSame(401, $this->controller(uid: null, review: self::review())->agree(id: 'r1', index: 0)->getStatus());
	}//end testRefusalsWriteNothing()

	/**
	 * A write OpenRegister refuses answers 403 with a message, not an error page.
	 *
	 * @return void
	 */
	public function testARefusedWriteIsReported(): void {
		$controller = $this->controller(uid: 'sanne', review: self::review());
		$this->objects->method('saveObject')->willThrowException(new RuntimeException('denied'));

		$response = $controller->agree(id: 'r1', index: 1);

		$this->assertSame(403, $response->getStatus());
		$this->assertSame('The coaching point could not be agreed.', $response->getData()['message']);
	}//end testARefusedWriteIsReported()
}//end class
