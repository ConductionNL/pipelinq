<?php

/**
 * Unit tests for ContactMomentFilingService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\ContactMomentFilingService;
use OCA\Pipelinq\Service\TicketService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for filing one contact moment onto several cases.
 */
class ContactMomentFilingServiceTest extends TestCase {
	/**
	 * The ticket service double.
	 *
	 * @var TicketService&MockObject
	 */
	private $ticketService;

	/**
	 * Every payload handed to TicketService::save().
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The stored contact moment the read returns.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $stored = null;

	/**
	 * Build the service over doubles.
	 *
	 * `onlyMethods` throughout, so no double can answer a call the real class
	 * would not have.
	 *
	 * @return ContactMomentFilingService The service under test.
	 */
	private function service(): ContactMomentFilingService {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (): ?object {
				if ($this->stored === null) {
					return null;
				}

				return $this->entity($this->stored);
			}
		);

		$this->ticketService = $this->getMockBuilder(TicketService::class)
			->disableOriginalConstructor()
			->onlyMethods(['isConfigured', 'getObjectService', 'save'])
			->getMock();
		$this->ticketService->method('isConfigured')->willReturn(true);
		$this->ticketService->method('getObjectService')->willReturn($objectService);
		$this->ticketService->method('save')->willReturnCallback(
			function (string $type, array $payload): object {
				$this->saved[] = $payload;

				return $this->entity($payload);
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('maria');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ContactMomentFilingService(
			$this->ticketService,
			$session,
			$this->createMock(LoggerInterface::class),
		);
	}//end service()

	/**
	 * Wrap an array as the serialisable entity OpenRegister hands back.
	 *
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return object The entity.
	 */
	private function entity(array $data): ObjectEntityInterface {
		// The CONTRACT, not an ad-hoc JsonSerializable: ObjectServiceInterface
		// declares `?ObjectEntityInterface`, and a double that answered
		// something else would be caught by the return type at runtime and
		// swallowed by the service's own error handling, turning a broken test
		// into a plausible-looking 404.
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn($data);
		$entity->method('getObject')->willReturn($data);
		$entity->method('getUuid')->willReturn((string)($data['id'] ?? ''));

		return $entity;
	}//end entity()

	/**
	 * Filing onto a second case creates no second contact moment.
	 *
	 * @return void
	 */
	public function testFilingOntoASecondCaseCreatesNoSecondRecord(): void {
		$this->stored = ['id' => 'cm-1', 'caseReferences' => ['case-a'], 'primaryCaseReference' => 'case-a'];
		$service = $this->service();

		$result = $service->fileOnAlsoCase(momentId: 'cm-1', caseId: 'case-b');

		$this->assertSame(200, $result['status']);
		$this->assertCount(1, $this->saved, 'The act writes ONE object, the one it was given.');
		$this->assertSame(['case-a', 'case-b'], $this->saved[0]['caseReferences']);
		$this->assertSame('case-a', $this->saved[0]['primaryCaseReference'], 'Filing elsewhere does not move the primary.');
		$this->assertSame('cm-1', $this->saved[0]['id'], 'The same record was updated, not a copy written.');
	}//end testFilingOntoASecondCaseCreatesNoSecondRecord()

	/**
	 * The act names the handler and the moment it happened.
	 *
	 * @return void
	 */
	public function testTheActIsRecordedWithItsActorAndTime(): void {
		$this->stored = ['id' => 'cm-1', 'caseReferences' => ['case-a']];
		$service = $this->service();

		$service->fileOnAlsoCase(momentId: 'cm-1', caseId: 'case-b');

		$trail = $this->saved[0]['caseFilings'];
		$this->assertCount(1, $trail);
		$this->assertSame('case-b', $trail[0]['case']);
		$this->assertSame('filed', $trail[0]['act']);
		$this->assertSame('maria', $trail[0]['by']);
		$this->assertNotSame('', $trail[0]['at']);
	}//end testTheActIsRecordedWithItsActorAndTime()

	/**
	 * A duplicate reference is refused and the set is unchanged.
	 *
	 * @return void
	 */
	public function testADuplicateReferenceIsRefused(): void {
		$this->stored = ['id' => 'cm-1', 'caseReferences' => ['case-a', 'case-b']];
		$service = $this->service();

		$result = $service->fileOnAlsoCase(momentId: 'cm-1', caseId: 'case-b');

		$this->assertSame(409, $result['status']);
		$this->assertSame([], $this->saved, 'A refused act writes nothing.');
	}//end testADuplicateReferenceIsRefused()

	/**
	 * The last reference cannot be removed.
	 *
	 * @return void
	 */
	public function testTheLastReferenceCannotBeRemoved(): void {
		$this->stored = ['id' => 'cm-1', 'caseReferences' => ['case-a'], 'primaryCaseReference' => 'case-a'];
		$service = $this->service();

		$result = $service->unfileFromCase(momentId: 'cm-1', caseId: 'case-a');

		$this->assertSame(409, $result['status']);
		$this->assertStringContainsString('at least one case', $result['error']);
		$this->assertSame([], $this->saved);
	}//end testTheLastReferenceCannotBeRemoved()

	/**
	 * Removing the primary requires naming the next one, in the same call.
	 *
	 * @return void
	 */
	public function testRemovingThePrimaryRequiresNamingTheNextOne(): void {
		$this->stored = [
			'id' => 'cm-1',
			'caseReferences' => ['case-a', 'case-b'],
			'primaryCaseReference' => 'case-a',
		];
		$service = $this->service();

		$refused = $service->unfileFromCase(momentId: 'cm-1', caseId: 'case-a');
		$this->assertSame(409, $refused['status']);
		$this->assertSame([], $this->saved);

		$accepted = $service->unfileFromCase(momentId: 'cm-1', caseId: 'case-a', newPrimary: 'case-b');
		$this->assertSame(200, $accepted['status']);
		$this->assertSame(['case-b'], $this->saved[0]['caseReferences']);
		$this->assertSame('case-b', $this->saved[0]['primaryCaseReference']);
		$this->assertSame('case-b', $this->saved[0]['caseReference'], 'The single-reference mirror follows the primary.');
	}//end testRemovingThePrimaryRequiresNamingTheNextOne()

	/**
	 * A new primary outside the remaining set is refused.
	 *
	 * @return void
	 */
	public function testANewPrimaryOutsideTheSetIsRefused(): void {
		$this->stored = [
			'id' => 'cm-1',
			'caseReferences' => ['case-a', 'case-b'],
			'primaryCaseReference' => 'case-a',
		];
		$service = $this->service();

		$result = $service->unfileFromCase(momentId: 'cm-1', caseId: 'case-a', newPrimary: 'case-c');

		$this->assertSame(409, $result['status']);
		$this->assertSame([], $this->saved);
	}//end testANewPrimaryOutsideTheSetIsRefused()

	/**
	 * The primary survives a reorder of the set.
	 *
	 * Position is not the answer: that is the whole reason the primary is a
	 * named property rather than "the first one".
	 *
	 * @return void
	 */
	public function testThePrimarySurvivesAReorder(): void {
		$service = $this->service();

		$before = ['caseReferences' => ['case-a', 'case-b', 'case-c'], 'primaryCaseReference' => 'case-b'];
		$after = ['caseReferences' => ['case-c', 'case-a', 'case-b'], 'primaryCaseReference' => 'case-b'];

		$this->assertSame('case-b', $service->primaryOf(moment: $before));
		$this->assertSame('case-b', $service->primaryOf(moment: $after));
	}//end testThePrimarySurvivesAReorder()

	/**
	 * A row the migration has not reached still answers its one case.
	 *
	 * @return void
	 */
	public function testAnUnmigratedRowStillAnswers(): void {
		$service = $this->service();

		$this->assertSame(['case-a'], $service->caseSetOf(moment: ['caseReference' => 'case-a']));
		$this->assertSame('case-a', $service->primaryOf(moment: ['caseReference' => 'case-a']));
	}//end testAnUnmigratedRowStillAnswers()

	/**
	 * A case the reader may not see is counted, not named.
	 *
	 * @return void
	 */
	public function testAnUnreadableCaseIsCountedNotNamed(): void {
		$service = $this->service();

		$marker = $service->sharedMarker(
			moment: ['caseReferences' => ['case-a', 'case-b', 'case-secret']],
			hostId: 'case-a',
			mayRead: static fn (string $reference): bool => $reference !== 'case-secret',
		);

		$this->assertTrue($marker['shared']);
		$this->assertSame(['case-b'], $marker['alsoOnCases']);
		$this->assertSame(1, $marker['hiddenCount']);
		$this->assertNotContains('case-secret', $marker['alsoOnCases']);
	}//end testAnUnreadableCaseIsCountedNotNamed()

	/**
	 * A contact moment on one case is not reported as shared.
	 *
	 * The control: without it, a marker that said "also on" about everything
	 * would pass the test above.
	 *
	 * @return void
	 */
	public function testAContactMomentOnOneCaseIsNotShared(): void {
		$service = $this->service();

		$marker = $service->sharedMarker(
			moment: ['caseReferences' => ['case-a']],
			hostId: 'case-a',
			mayRead: static fn (string $reference): bool => true,
		);

		$this->assertFalse($marker['shared']);
		$this->assertSame([], $marker['alsoOnCases']);
		$this->assertSame(0, $marker['hiddenCount']);
	}//end testAContactMomentOnOneCaseIsNotShared()
}//end class
