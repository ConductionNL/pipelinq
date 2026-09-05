<?php

/**
 * Unit tests for the marketer-facing mailing list and subscription endpoints.
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
 * @spec openspec/specs/marketing-lists/spec.md#requirement-public-list-endpoints-are-throttled-and-fail-closed
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\MailingListController;
use OCA\Pipelinq\Controller\SubscriptionController;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\MailingListService;
use OCA\Pipelinq\Service\Marketing\PreferenceCentreService;
use OCA\Pipelinq\Service\Marketing\SubscriptionQueryService;
use OCA\Pipelinq\Service\SubscriptionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The wire contract of the authenticated half of the mailing list surface.
 *
 * These endpoints read who is on a list and who a person is, which is the most
 * sensitive projection this change adds. Authentication is not authorization,
 * so each one is asserted twice: once for a caller the CRM policy admits, and
 * once for a caller it does not. Both refusals answer identically, because an
 * unauthenticated and an unprivileged caller telling each other apart is
 * itself a disclosure.
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-public-list-endpoints-are-throttled-and-fail-closed
 */
class MailingListAccessTest extends TestCase {
	/**
	 * A session holding a user with this uid, or none at all.
	 *
	 * @param string|null $uid The uid, or null for an anonymous caller.
	 *
	 * @return IUserSession The stubbed session.
	 */
	private function session(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
			return $session;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	/**
	 * A policy that admits or refuses every caller.
	 *
	 * @param bool $privileged What `isPrivileged()` answers.
	 *
	 * @return ObjectOwnerAccessPolicy The stubbed policy.
	 */
	private function policy(bool $privileged): ObjectOwnerAccessPolicy {
		$policy = $this->createMock(ObjectOwnerAccessPolicy::class);
		$policy->method('isPrivileged')->willReturn($privileged);
		return $policy;
	}//end policy()

	/**
	 * A request answering the given parameters.
	 *
	 * @param array<string, mixed> $params Parameter map.
	 *
	 * @return IRequest The stubbed request.
	 */
	private function request(array $params = []): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);
		return $request;
	}//end request()

	/**
	 * A privileged caller reads a list's memberships with their counts.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
	 *
	 * @return void
	 */
	public function testSubscriptionsReturnsRowsAndCountsForACrmUser(): void {
		$queries = $this->createMock(SubscriptionQueryService::class);
		$queries->method('listSubscriptionsForList')->willReturn(
			['data' => [['id' => 's1', 'state' => 'confirmed']], 'pagination' => ['total' => 1]]
		);
		$queries->method('countsForList')->willReturn(
			['pending' => 0, 'confirmed' => 1, 'unsubscribed' => 0, 'bounced' => 0, 'total' => 1]
		);

		$controller = new MailingListController(
			request: $this->request(),
			lists: $this->createMock(MailingListService::class),
			queries: $queries,
			userSession: $this->session('marketeer'),
			policy: $this->policy(true),
		);

		$response = $controller->subscriptions(id: 'list-1');
		$body = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $body['data']);
		$this->assertSame(1, $body['counts']['confirmed']);
	}//end testSubscriptionsReturnsRowsAndCountsForACrmUser()

	/**
	 * An unprivileged session is refused, and the refusal names nothing.
	 *
	 * @return void
	 */
	public function testSubscriptionsRefusesAnUnprivilegedSession(): void {
		$queries = $this->createMock(SubscriptionQueryService::class);
		$queries->expects($this->never())->method('listSubscriptionsForList');

		$controller = new MailingListController(
			request: $this->request(),
			lists: $this->createMock(MailingListService::class),
			queries: $queries,
			userSession: $this->session('outsider'),
			policy: $this->policy(false),
		);

		$response = $controller->subscriptions(id: 'list-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'Forbidden'], $response->getData());
	}//end testSubscriptionsRefusesAnUnprivilegedSession()

	/**
	 * An anonymous caller gets the same refusal an unprivileged one gets.
	 *
	 * @return void
	 */
	public function testForContactRefusesAnonymousAndUnprivilegedIdentically(): void {
		$make = function (?string $uid, bool $privileged): SubscriptionController {
			return new SubscriptionController(
				request: $this->request(),
				subscriptions: $this->createMock(SubscriptionService::class),
				queries: $this->createMock(SubscriptionQueryService::class),
				preferences: $this->createMock(PreferenceCentreService::class),
				userSession: $this->session($uid),
				policy: $this->policy($privileged),
			);
		};

		$anonymous = $make(null, true)->forContact(contactId: 'contact-1');
		$unprivileged = $make('outsider', false)->forContact(contactId: 'contact-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $anonymous->getStatus());
		$this->assertSame($anonymous->getStatus(), $unprivileged->getStatus());
		$this->assertSame($anonymous->getData(), $unprivileged->getData());
	}//end testForContactRefusesAnonymousAndUnprivilegedIdentically()

	/**
	 * A privileged caller reads one contact's memberships.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-the-preference-centre-shows-and-saves-a-subscribers-lists
	 *
	 * @return void
	 */
	public function testForContactReturnsTheContactsMemberships(): void {
		$queries = $this->createMock(SubscriptionQueryService::class);
		$queries->method('listSubscriptionsForContact')->willReturn(
			[['id' => 's1', 'listId' => 'list-1', 'state' => 'confirmed']]
		);

		$controller = new SubscriptionController(
			request: $this->request(),
			subscriptions: $this->createMock(SubscriptionService::class),
			queries: $queries,
			preferences: $this->createMock(PreferenceCentreService::class),
			userSession: $this->session('marketeer'),
			policy: $this->policy(true),
		);

		$response = $controller->forContact(contactId: 'contact-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData()['subscriptions']);
	}//end testForContactReturnsTheContactsMemberships()

	/**
	 * The preference link endpoint hands back the signed URL, and refuses a
	 * request that names no contact rather than minting a link for nobody.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-the-preference-centre-shows-and-saves-a-subscribers-lists
	 *
	 * @return void
	 */
	public function testPreferenceLinkMintsAUrlAndRefusesAnEmptyContact(): void {
		$preferences = $this->createMock(PreferenceCentreService::class);
		$preferences->method('preferencesUrlFor')->willReturn('https://crm.test/prefs/token');

		$controller = new SubscriptionController(
			request: $this->request(),
			subscriptions: $this->createMock(SubscriptionService::class),
			queries: $this->createMock(SubscriptionQueryService::class),
			preferences: $preferences,
			userSession: $this->session('marketeer'),
			policy: $this->policy(true),
		);

		$minted = $controller->preferenceLink(contactId: 'contact-1');
		$empty = $controller->preferenceLink(contactId: '');

		$this->assertSame(Http::STATUS_OK, $minted->getStatus());
		$this->assertSame('https://crm.test/prefs/token', $minted->getData()['url']);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $empty->getStatus());
	}//end testPreferenceLinkMintsAUrlAndRefusesAnEmptyContact()

	/**
	 * The soft opt-in import passes the objection evidence through as a
	 * boolean, so a checkbox that arrives as the string "true" is not read as
	 * an objection that was never offered.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-soft-opt-in-records-its-ground-and-the-objection-offered
	 *
	 * @return void
	 */
	public function testSoftOptInPassesTheObjectionThroughAsABoolean(): void {
		$captured = [];
		$subscriptions = $this->createMock(SubscriptionService::class);
		$subscriptions->method('importSoftOptIn')->willReturnCallback(
			static function (string $listId, string $contactId, string $email, array $evidence) use (&$captured): array {
				$captured = $evidence;
				return ['status' => 'imported', 'subscription' => ['id' => 's1']];
			}
		);

		$controller = new SubscriptionController(
			request: $this->request([
				'listId' => 'list-1',
				'contactId' => 'contact-1',
				'email' => 'iemand@example.test',
				'objectionOffered' => 'true',
			]),
			subscriptions: $subscriptions,
			queries: $this->createMock(SubscriptionQueryService::class),
			preferences: $this->createMock(PreferenceCentreService::class),
			userSession: $this->session('marketeer'),
			policy: $this->policy(true),
		);

		$controller->softOptIn();

		$this->assertTrue($captured['objectionOffered']);
	}//end testSoftOptInPassesTheObjectionThroughAsABoolean()

	/**
	 * A soft opt-in import from an unprivileged session stores nothing.
	 *
	 * @return void
	 */
	public function testSoftOptInRefusesAnUnprivilegedSession(): void {
		$subscriptions = $this->createMock(SubscriptionService::class);
		$subscriptions->expects($this->never())->method('importSoftOptIn');

		$controller = new SubscriptionController(
			request: $this->request(['listId' => 'list-1']),
			subscriptions: $subscriptions,
			queries: $this->createMock(SubscriptionQueryService::class),
			preferences: $this->createMock(PreferenceCentreService::class),
			userSession: $this->session('outsider'),
			policy: $this->policy(false),
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->softOptIn()->getStatus());
	}//end testSoftOptInRefusesAnUnprivilegedSession()
}//end class
