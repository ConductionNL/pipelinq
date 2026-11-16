<?php

/**
 * SubscriptionController: a CRM user lists a contact's subscriptions, imports
 * a soft opt-in with its objection evidence, unsubscribes a contact and asks
 * for a preference-centre link.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\SubscriptionController;
use OCA\Pipelinq\Kernel\Contracts\PreferenceCentreServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\SubscriptionQueryServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\SubscriptionServiceInterface;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Controller\SubscriptionController
 * @uses   \OCA\Pipelinq\Controller\CrmUserGuard
 */
class SubscriptionControllerTest extends TestCase {

	/**
	 * Subscription service double.
	 *
	 * @var SubscriptionServiceInterface&MockObject
	 */
	private SubscriptionServiceInterface&MockObject $subscriptions;

	/**
	 * Subscription query double.
	 *
	 * @var SubscriptionQueryServiceInterface&MockObject
	 */
	private SubscriptionQueryServiceInterface&MockObject $queries;

	/**
	 * Preference centre double.
	 *
	 * @var PreferenceCentreServiceInterface&MockObject
	 */
	private PreferenceCentreServiceInterface&MockObject $preferences;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->subscriptions = $this->createMock(SubscriptionServiceInterface::class);
		$this->queries = $this->createMock(SubscriptionQueryServiceInterface::class);
		$this->preferences = $this->createMock(PreferenceCentreServiceInterface::class);
	}//end setUp()

	/**
	 * The controller for a CRM user unless told otherwise.
	 *
	 * @param array<string, mixed> $params     The request parameters.
	 * @param bool                 $privileged Whether the session user holds CRM rights.
	 *
	 * @return SubscriptionController
	 */
	private function controller(array $params = [], bool $privileged = true): SubscriptionController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => (array_key_exists($key, $params) === true ? $params[$key] : $default)
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('marketer');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$policy = $this->createMock(ObjectOwnerAccessPolicy::class);
		$policy->method('isPrivileged')->with(uid: 'marketer')->willReturn($privileged);

		return new SubscriptionController($request, $this->subscriptions, $this->queries, $this->preferences, $session, $policy);
	}//end controller()

	/**
	 * Without CRM rights every action is forbidden and nothing is read or written.
	 *
	 * @return void
	 */
	public function testWithoutCrmRightsEveryActionIsForbidden(): void {
		$this->queries->expects($this->never())->method('listSubscriptionsForContact');
		$this->subscriptions->expects($this->never())->method($this->anything());
		$this->preferences->expects($this->never())->method('preferencesUrlFor');
		$controller = $this->controller(['contactId' => 'c-1'], privileged: false);

		foreach ([$controller->forContact('c-1'), $controller->softOptIn(), $controller->unsubscribe(), $controller->preferenceLink('c-1')] as $response) {
			$this->assertSame(403, $response->getStatus());
			$this->assertSame(['error' => 'Forbidden'], $response->getData());
		}
	}//end testWithoutCrmRightsEveryActionIsForbidden()

	/**
	 * The subscriptions of the contact asked for.
	 *
	 * @return void
	 */
	public function testForContactListsThatContactsSubscriptions(): void {
		$this->queries->expects($this->once())->method('listSubscriptionsForContact')->with(contactId: 'c-1')->willReturn([['listId' => 'l-1']]);

		$this->assertSame(['subscriptions' => [['listId' => 'l-1']]], $this->controller()->forContact('c-1')->getData());
	}//end testForContactListsThatContactsSubscriptions()

	/**
	 * A soft opt-in is imported with its evidence; objectionOffered counts only when true.
	 *
	 * @return void
	 */
	public function testSoftOptInPassesTheEvidence(): void {
		$seen = [];
		$this->subscriptions->method('importSoftOptIn')->willReturnCallback(
			static function (string $listId, string $contactId, string $email, array $evidence) use (&$seen): array {
				$seen[] = [$listId, $contactId, $email, $evidence];
				return ['status' => 'imported'];
			}
		);
		$params = ['listId' => 'l-1', 'contactId' => 'c-1', 'email' => 'a@example.org', 'objectionOfferedAt' => '2026-01-02', 'objectionText' => 'Afmelden kan altijd', 'reference' => 'order-9'];

		foreach ([true, 'true', '1'] as $offered) {
			$response = $this->controller($params + ['objectionOffered' => $offered])->softOptIn();
			$this->assertSame(201, $response->getStatus());
			$this->assertSame(['status' => 'imported'], $response->getData());
		}
		foreach (['false', '0', 'yes', null] as $notOffered) {
			$this->controller($params + ['objectionOffered' => $notOffered])->softOptIn();
		}

		$this->assertSame(['l-1', 'c-1', 'a@example.org', ['objectionOffered' => true, 'objectionOfferedAt' => '2026-01-02', 'objectionText' => 'Afmelden kan altijd', 'reference' => 'order-9']], $seen[0]);
		$this->assertSame([true, true, true, false, false, false, false], array_map(static fn (array $call): bool => $call[3]['objectionOffered'], $seen));
	}//end testSoftOptInPassesTheEvidence()

	/**
	 * A refused import answers 400 with the service's reason, or a general one.
	 *
	 * @return void
	 */
	public function testARefusedSoftOptInIs400(): void {
		$this->subscriptions->method('importSoftOptIn')->willReturnOnConsecutiveCalls(
			['status' => 'refused', 'error' => 'No objection was offered'],
			['status' => 'refused']
		);

		$first = $this->controller()->softOptIn();
		$this->assertSame(400, $first->getStatus());
		$this->assertSame(['error' => 'No objection was offered'], $first->getData());
		$this->assertSame(['error' => 'The subscription could not be imported'], $this->controller()->softOptIn()->getData());
	}//end testARefusedSoftOptInIs400()

	/**
	 * Unsubscribing needs a contact.
	 *
	 * @return void
	 */
	public function testUnsubscribeNeedsAContact(): void {
		$this->subscriptions->expects($this->never())->method('globalUnsubscribe');

		$response = $this->controller(['listId' => 'l-1'])->unsubscribe();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'Name the contact to unsubscribe'], $response->getData());
	}//end testUnsubscribeNeedsAContact()

	/**
	 * Without a list the contact leaves every list, with the reason.
	 *
	 * @return void
	 */
	public function testUnsubscribeWithoutAListIsGlobal(): void {
		$this->subscriptions->expects($this->once())->method('globalUnsubscribe')->with(contactId: 'c-1', reason: 'moved')->willReturn(3);
		$this->subscriptions->expects($this->never())->method('unsubscribeFromList');

		$this->assertSame(['count' => 3], $this->controller(['contactId' => 'c-1', 'reason' => 'moved'])->unsubscribe()->getData());
	}//end testUnsubscribeWithoutAListIsGlobal()

	/**
	 * With a list the contact leaves that list; one not found answers 404.
	 *
	 * @return void
	 */
	public function testUnsubscribeFromOneList(): void {
		$this->subscriptions->expects($this->exactly(2))->method('unsubscribeFromList')
			->with(listId: 'l-1', contactId: 'c-1', reason: '')
			->willReturnOnConsecutiveCalls(true, false);
		$this->subscriptions->expects($this->never())->method('globalUnsubscribe');

		$this->assertSame(['count' => 1], $this->controller(['contactId' => 'c-1', 'listId' => 'l-1'])->unsubscribe()->getData());
		$missing = $this->controller(['contactId' => 'c-1', 'listId' => 'l-1'])->unsubscribe();
		$this->assertSame(404, $missing->getStatus());
		$this->assertSame(['error' => 'Not found'], $missing->getData());
	}//end testUnsubscribeFromOneList()

	/**
	 * A preference link for a named contact; none for an empty name.
	 *
	 * @return void
	 */
	public function testPreferenceLink(): void {
		$this->preferences->expects($this->once())->method('preferencesUrlFor')->with(contactId: 'c-1')->willReturn('https://crm.example.org/p/abc');

		$this->assertSame(['url' => 'https://crm.example.org/p/abc'], $this->controller()->preferenceLink('c-1')->getData());
		$this->assertSame(400, $this->controller()->preferenceLink('')->getStatus());
	}//end testPreferenceLink()
}//end class
