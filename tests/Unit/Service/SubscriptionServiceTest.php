<?php

/**
 * Unit tests for SubscriptionService and SubscriptionQueryService.
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
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-self-service-subscribe-creates-a-pending-subscription
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ComplianceService;
use OCA\Pipelinq\Service\ListTokenService;
use OCA\Pipelinq\Service\MailingListService;
use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCA\Pipelinq\Service\Marketing\SubscriptionQueryService;
use OCA\Pipelinq\Service\SubscriptionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the membership lifecycle.
 *
 * The object store is a real in-memory subclass rather than a mock returning
 * canned rows: subscribe, confirm and unsubscribe each read back what the step
 * before them WROTE, so a mock that answered from a fixture would agree with
 * the caller no matter what the service actually stored.
 *
 * The token service is the real one over an in-memory app config, for the same
 * reason: a stubbed verifier cannot show that the digest a subscribe stored is
 * the digest a confirm checks.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A membership test needs the
 *  store, the token service, the list, the consent ledger and the mailer,
 *  because the behaviour under test is what happens between them.
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-self-service-subscribe-creates-a-pending-subscription
 */
class SubscriptionServiceTest extends TestCase {
	/**
	 * The in-memory object store the services read and write.
	 *
	 * @var ListObjectStore
	 */
	private ListObjectStore $store;

	/**
	 * The list service over the same store.
	 *
	 * @var MailingListService
	 */
	private MailingListService $lists;

	/**
	 * The real token service over an in-memory config.
	 *
	 * @var ListTokenService
	 */
	private ListTokenService $tokens;

	/**
	 * The consent ledger, recorded rather than exercised.
	 *
	 * @var ComplianceService
	 */
	private ComplianceService $compliance;

	/**
	 * The service under test.
	 *
	 * @var SubscriptionService
	 */
	private SubscriptionService $service;

	/**
	 * The read side, over the same store.
	 *
	 * @var SubscriptionQueryService
	 */
	private SubscriptionQueryService $queries;

	/**
	 * Messages the mailer was asked to send.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $sent = [];

	/**
	 * Which (contact, list) pairs the consent ledger holds open.
	 *
	 * @var array<string, bool>
	 */
	private array $listConsent = [];

	/**
	 * Consent writes the service made.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $consentWrites = [];

	/**
	 * Withdrawals the service recorded.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $withdrawals = [];

	/**
	 * Wire the services over one shared in-memory store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = $this->makeStore();
		$this->lists = new MailingListService(store: $this->store);
		$this->tokens = $this->makeTokenService();
		$this->compliance = $this->makeCompliance();

		$this->service = new SubscriptionService(
			store: $this->store,
			tokens: $this->tokens,
			lists: $this->lists,
			compliance: $this->compliance,
			mailer: $this->makeMailer(),
			l10n: $this->makeL10n(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->queries = new SubscriptionQueryService(
			store: $this->store,
			compliance: $this->compliance,
		);

		$this->seedList(slug: 'list-news', optInMode: 'double', publicSignup: true);
		$this->seedList(slug: 'list-updates', optInMode: 'soft', publicSignup: false);
	}//end setUp()

	/**
	 * A public signup lands as pending, never confirmed, and mails one link.
	 *
	 * @return void
	 */
	public function testSubscribeCreatesPendingAndMailsOneLink(): void {
		$result = $this->service->subscribe(listId: 'list-news', email: 'p.jansen@amsterdam.nl');

		$this->assertSame('accepted', $result['status']);
		$rows = $this->store->findAll(schemaSlug: 'subscription', filters: ['listId' => 'list-news']);
		$this->assertCount(1, $rows);
		$this->assertSame('pending', $rows[0]['state']);
		$this->assertSame('public-signup', $rows[0]['source']);
		$this->assertCount(1, $this->sent);
		$this->assertSame('p.jansen@amsterdam.nl', $this->sent[0]['to']);
	}//end testSubscribeCreatesPendingAndMailsOneLink()

	/**
	 * The subscription stores the DIGEST of the nonce, never the token.
	 *
	 * @return void
	 */
	public function testSubscribeStoresADigestNotTheToken(): void {
		$this->service->subscribe(listId: 'list-news', email: 'p.jansen@amsterdam.nl');

		$row = $this->store->findAll(schemaSlug: 'subscription', filters: ['listId' => 'list-news'])[0];
		$digest = (string)$row['confirmTokenHash'];

		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $digest);
		$this->assertStringNotContainsString($digest, $this->sent[0]['body']);
	}//end testSubscribeStoresADigestNotTheToken()

	/**
	 * A filled honeypot is discarded, and answered exactly like a real one.
	 *
	 * @return void
	 */
	public function testHoneypotIsDiscardedAndIndistinguishable(): void {
		$result = $this->service->subscribe(
			listId: 'list-news',
			email: 'bot@example.org',
			honeypot: 'http://spam.example',
		);

		$this->assertSame('accepted', $result['status']);
		$this->assertSame([], $this->store->findAll(schemaSlug: 'subscription', filters: ['listId' => 'list-news']));
		$this->assertSame([], $this->sent);
	}//end testHoneypotIsDiscardedAndIndistinguishable()

	/**
	 * A list closed to public signup answers not-found and writes nothing.
	 *
	 * @return void
	 */
	public function testClosedListRefusesPublicSignup(): void {
		$result = $this->service->subscribe(listId: 'list-updates', email: 's.bakker@utrecht.nl');

		$this->assertSame('not-found', $result['status']);
		$this->assertSame([], $this->store->findAll(schemaSlug: 'subscription'));
	}//end testClosedListRefusesPublicSignup()

	/**
	 * Subscribing twice reuses the membership rather than making a second one.
	 *
	 * @return void
	 */
	public function testSubscribingTwiceKeepsOneMembership(): void {
		$this->service->subscribe(listId: 'list-news', email: 'p.jansen@amsterdam.nl');
		$this->service->subscribe(listId: 'list-news', email: 'p.jansen@amsterdam.nl');

		$this->assertCount(1, $this->store->findAll(schemaSlug: 'subscription', filters: ['listId' => 'list-news']));
		$this->assertCount(2, $this->sent);
	}//end testSubscribingTwiceKeepsOneMembership()

	/**
	 * The link mailed by subscribe confirms the membership and writes consent.
	 *
	 * @return void
	 */
	public function testConfirmMovesToConfirmedAndWritesConsent(): void {
		$this->service->subscribe(listId: 'list-news', email: 'p.jansen@amsterdam.nl');

		$result = $this->service->confirm(token: $this->tokenFromLastMail(), ipAddress: '198.51.100.7');

		$this->assertSame('confirmed', $result['status']);
		$row = $this->store->findAll(schemaSlug: 'subscription', filters: ['listId' => 'list-news'])[0];
		$this->assertSame('confirmed', $row['state']);
		$this->assertNotSame('', (string)$row['confirmedAt']);
		$this->assertCount(1, $this->consentWrites);
		$this->assertSame('consent', $this->consentWrites[0]['lawfulBasis']);
		$this->assertSame('list-news', $this->consentWrites[0]['listId']);
	}//end testConfirmMovesToConfirmedAndWritesConsent()

	/**
	 * Confirming clears the digest, so the link cannot be spent twice.
	 *
	 * @return void
	 */
	public function testConfirmationLinkCannotBeSpentTwice(): void {
		$this->service->subscribe(listId: 'list-news', email: 'p.jansen@amsterdam.nl');
		$token = $this->tokenFromLastMail();

		$this->assertSame('confirmed', $this->service->confirm(token: $token)['status']);
		$this->assertSame('invalid', $this->service->confirm(token: $token)['status']);
		$this->assertCount(1, $this->consentWrites);
	}//end testConfirmationLinkCannotBeSpentTwice()

	/**
	 * A well-signed token whose nonce does not match the stored digest is
	 * refused. This is the half a stolen signing key alone cannot forge.
	 *
	 * @return void
	 */
	public function testConfirmRefusesAWrongNonceEvenWhenWellSigned(): void {
		$this->service->subscribe(listId: 'list-news', email: 'p.jansen@amsterdam.nl');
		$id = $this->store->idOf(
			$this->store->findAll(schemaSlug: 'subscription', filters: ['listId' => 'list-news'])[0]
		);

		$forged = $this->tokens->signConfirmToken(subscriptionId: $id, nonce: 'not-the-nonce');

		$this->assertSame('invalid', $this->service->confirm(token: $forged)['status']);
		$row = $this->store->findAll(schemaSlug: 'subscription', filters: ['listId' => 'list-news'])[0];
		$this->assertSame('pending', $row['state']);
	}//end testConfirmRefusesAWrongNonceEvenWhenWellSigned()

	/**
	 * An unsubscribe closes the membership and records the withdrawal against
	 * the LIST, not the whole channel.
	 *
	 * @return void
	 */
	public function testUnsubscribeClosesTheMembershipAndWithdrawsListConsent(): void {
		$id = $this->seedConfirmed(listId: 'list-news', contactId: 'contact-1', email: 'p@a.nl');
		$token = $this->tokens->signUnsubscribeToken(subscriptionId: $id, contactId: 'contact-1');

		$result = $this->service->unsubscribeByToken(token: $token);

		$this->assertSame('unsubscribed', $result['status']);
		$this->assertSame(1, $result['count']);
		$row = $this->store->find(schemaSlug: 'subscription', id: $id);
		$this->assertSame('unsubscribed', $row['state']);
		$this->assertCount(1, $this->withdrawals);
		$this->assertSame('list-news', $this->withdrawals[0]['listId']);
		$this->assertSame('user-unsubscribed', $this->withdrawals[0]['reason']);
	}//end testUnsubscribeClosesTheMembershipAndWithdrawsListConsent()

	/**
	 * Unsubscribing twice is idempotent, because the link is clicked twice
	 * more often than once.
	 *
	 * @return void
	 */
	public function testUnsubscribeIsIdempotent(): void {
		$id = $this->seedConfirmed(listId: 'list-news', contactId: 'contact-1', email: 'p@a.nl');
		$token = $this->tokens->signUnsubscribeToken(subscriptionId: $id, contactId: 'contact-1');

		$this->service->unsubscribeByToken(token: $token);
		$second = $this->service->unsubscribeByToken(token: $token);

		$this->assertSame('unsubscribed', $second['status']);
		$this->assertCount(1, $this->withdrawals);
	}//end testUnsubscribeIsIdempotent()

	/**
	 * A global unsubscribe leaves every list the contact is on.
	 *
	 * @return void
	 */
	public function testGlobalUnsubscribeLeavesEveryList(): void {
		$id = $this->seedConfirmed(listId: 'list-news', contactId: 'contact-1', email: 'p@a.nl');
		$this->seedConfirmed(listId: 'list-updates', contactId: 'contact-1', email: 'p@a.nl');
		$token = $this->tokens->signUnsubscribeToken(subscriptionId: $id, contactId: 'contact-1');

		$result = $this->service->unsubscribeByToken(token: $token, global: true);

		$this->assertSame(2, $result['count']);
		foreach ($this->store->findAll(schemaSlug: 'subscription', filters: ['contactId' => 'contact-1']) as $row) {
			$this->assertSame('unsubscribed', $row['state']);
		}
		$this->assertCount(2, $this->withdrawals);
	}//end testGlobalUnsubscribeLeavesEveryList()

	/**
	 * A soft opt-in import records its ground and its evidence.
	 *
	 * @return void
	 */
	public function testSoftOptInRecordsItsGround(): void {
		$result = $this->service->importSoftOptIn(
			listId: 'list-updates',
			contactId: 'contact-1',
			email: 's.bakker@utrecht.nl',
			evidence: ['objectionOffered' => true, 'objectionOfferedAt' => '2026-06-04T10:00:00Z'],
		);

		$this->assertSame('imported', $result['status']);
		$row = $this->store->findAll(schemaSlug: 'subscription', filters: ['listId' => 'list-updates'])[0];
		$this->assertSame('confirmed', $row['state']);
		$this->assertSame('soft-opt-in', $row['lawfulBasis']);
		$this->assertSame('soft-opt-in', $this->consentWrites[0]['lawfulBasis']);
		$this->assertTrue($this->consentWrites[0]['evidence']['objectionOffered']);
	}//end testSoftOptInRecordsItsGround()

	/**
	 * A soft opt-in without the objection recorded is refused outright.
	 *
	 * @return void
	 */
	public function testSoftOptInWithoutTheObjectionIsRefused(): void {
		$result = $this->service->importSoftOptIn(
			listId: 'list-updates',
			contactId: 'contact-1',
			email: 's.bakker@utrecht.nl',
			evidence: ['objectionOffered' => false],
		);

		$this->assertSame('refused', $result['status']);
		$this->assertStringContainsString('object', $result['error']);
		$this->assertSame([], $this->store->findAll(schemaSlug: 'subscription'));
		$this->assertSame([], $this->consentWrites);
	}//end testSoftOptInWithoutTheObjectionIsRefused()

	/**
	 * Soft opt-in is refused on a double opt-in list, whatever the evidence.
	 *
	 * @return void
	 */
	public function testSoftOptInIsRefusedOnADoubleOptInList(): void {
		$result = $this->service->importSoftOptIn(
			listId: 'list-news',
			contactId: 'contact-1',
			email: 'p.jansen@amsterdam.nl',
			evidence: ['objectionOffered' => true],
		);

		$this->assertSame('refused', $result['status']);
		$this->assertSame([], $this->store->findAll(schemaSlug: 'subscription'));
	}//end testSoftOptInIsRefusedOnADoubleOptInList()

	/**
	 * A pending membership is never in a blast audience, and neither is an
	 * unsubscribed or bounced one.
	 *
	 * @return void
	 */
	public function testOnlyConfirmedMembersReachABlast(): void {
		$this->seedConfirmed(listId: 'list-news', contactId: 'contact-1', email: 'a@a.nl');
		$this->seedSubscription(listId: 'list-news', contactId: 'contact-2', email: 'b@a.nl', state: 'pending');
		$this->seedSubscription(listId: 'list-news', contactId: 'contact-3', email: 'c@a.nl', state: 'unsubscribed');
		$this->seedSubscription(listId: 'list-news', contactId: 'contact-4', email: 'd@a.nl', state: 'bounced');

		$audience = $this->queries->getBlastAudienceForList(listId: 'list-news');

		$this->assertCount(1, $audience['members']);
		$this->assertSame('contact-1', $audience['members'][0]['contactId']);
		$this->assertCount(3, $audience['skipped']);
	}//end testOnlyConfirmedMembersReachABlast()

	/**
	 * A confirmed membership whose list consent was withdrawn is skipped too:
	 * the state machine and the ledger are two gates, not one.
	 *
	 * @return void
	 */
	public function testConfirmedMemberWithWithdrawnConsentIsSkipped(): void {
		$this->seedConfirmed(listId: 'list-news', contactId: 'contact-1', email: 'a@a.nl');
		$this->listConsent['contact-1|list-news'] = false;

		$audience = $this->queries->getBlastAudienceForList(listId: 'list-news');

		$this->assertSame([], $audience['members']);
		$this->assertSame(['contact-1'], $audience['skipped']);
	}//end testConfirmedMemberWithWithdrawnConsentIsSkipped()

	/**
	 * The per-state counts name every state, even at zero.
	 *
	 * @return void
	 */
	public function testCountsNameEveryStateEvenAtZero(): void {
		$this->seedConfirmed(listId: 'list-news', contactId: 'contact-1', email: 'a@a.nl');
		$this->seedSubscription(listId: 'list-news', contactId: 'contact-2', email: 'b@a.nl', state: 'pending');

		$counts = $this->queries->countsForList(listId: 'list-news');

		$this->assertSame(1, $counts['confirmed']);
		$this->assertSame(1, $counts['pending']);
		$this->assertSame(0, $counts['unsubscribed']);
		$this->assertSame(0, $counts['bounced']);
		$this->assertSame(2, $counts['total']);
	}//end testCountsNameEveryStateEvenAtZero()

	/**
	 * A store backed by a plain array, so each step reads what the last wrote.
	 *
	 * @return ListObjectStore The in-memory store.
	 */
	private function makeStore(): ListObjectStore {
		return new class(
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class),
		) extends ListObjectStore {
			/** @var array<string, array<string, array<string, mixed>>> */
			public array $rows = [];

			/** @var int */
			private int $seq = 0;

			/**
			 * @param string $configKey Ignored.
			 * @param string $default The slug.
			 * @return string The slug.
			 */
			public function schemaSlug(string $configKey, string $default): string {
				return $default;
			}

			/**
			 * @param string $schemaSlug The schema.
			 * @param string $id The id.
			 * @return array<string, mixed>|null The row.
			 */
			public function find(string $schemaSlug, string $id): ?array {
				return ($this->rows[$schemaSlug][$id] ?? null);
			}

			/**
			 * @param string $schemaSlug The schema.
			 * @param array<string, string> $filters Field-value pairs.
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function findAll(string $schemaSlug, array $filters = []): array {
				$out = [];
				foreach (($this->rows[$schemaSlug] ?? []) as $row) {
					foreach ($filters as $field => $value) {
						if ((string)($row[$field] ?? '') !== (string)$value) {
							continue 2;
						}
					}
					$out[] = $row;
				}
				return $out;
			}

			/**
			 * @param string $schemaSlug The schema.
			 * @param array<string, mixed> $payload The payload.
			 * @param string|null $id Existing id.
			 * @return array<string, mixed>|null The saved row.
			 */
			public function save(string $schemaSlug, array $payload, ?string $id = null): ?array {
				$key = $id;
				if ($key === null || $key === '') {
					$this->seq++;
					$key = $schemaSlug . '-' . $this->seq;
				}
				$payload['id'] = $key;
				$this->rows[$schemaSlug][$key] = $payload;
				return $payload;
			}

			/**
			 * @param array<string, mixed>|null $payload The row.
			 * @return string The id.
			 */
			public function idOf(?array $payload): string {
				return (string)($payload['id'] ?? '');
			}
		};
	}//end makeStore()

	/**
	 * The real token service over an in-memory app config.
	 *
	 * @return ListTokenService The token service.
	 */
	private function makeTokenService(): ListTokenService {
		$secrets = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$secrets): string {
				return ($secrets[$key] ?? $default);
			}
		);
		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$secrets): bool {
				$secrets[$key] = $value;
				return true;
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1750000000);

		$counter = 0;
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(
			static function (int $length) use (&$counter): string {
				$counter++;
				return str_pad('n' . $counter, $length, 'z');
			}
		);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $args = []): string => 'https://crm.test/' . ($args['token'] ?? '')
		);

		return new ListTokenService(
			appConfig: $appConfig,
			time: $time,
			secureRandom: $random,
			urlGenerator: $urls,
		);
	}//end makeTokenService()

	/**
	 * A consent ledger that records what it was told and answers from a map.
	 *
	 * @return ComplianceService The recording double.
	 */
	private function makeCompliance(): ComplianceService {
		$compliance = $this->createMock(ComplianceService::class);
		$compliance->method('recordListConsent')->willReturnCallback(
			function (
				string $contactId,
				string $listId,
				string $channel,
				string $lawfulBasis,
				string $consentSource,
				array $evidence,
			): void {
				$this->consentWrites[] = [
					'contactId' => $contactId,
					'listId' => $listId,
					'lawfulBasis' => $lawfulBasis,
					'consentSource' => $consentSource,
					'evidence' => $evidence,
				];
				$this->listConsent[$contactId . '|' . $listId] = true;
			}
		);
		$compliance->method('recordConsentWithdrawal')->willReturnCallback(
			function (
				string $contactId,
				string $channel,
				string $reason,
				?string $sourceBlastId = null,
				?string $listId = null,
			): void {
				$this->withdrawals[] = [
					'contactId' => $contactId,
					'reason' => $reason,
					'listId' => $listId,
				];
				$this->listConsent[$contactId . '|' . (string)$listId] = false;
			}
		);
		$compliance->method('hasConsentForList')->willReturnCallback(
			function (string $contactId, string $listId, string $channel = 'email'): bool {
				return ($this->listConsent[$contactId . '|' . $listId] ?? false);
			}
		);

		return $compliance;
	}//end makeCompliance()

	/**
	 * A mailer that records rather than sends.
	 *
	 * @return IMailer The recording mailer.
	 */
	private function makeMailer(): IMailer {
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('validateMailAddress')->willReturnCallback(
			static fn (string $address): bool => filter_var($address, FILTER_VALIDATE_EMAIL) !== false
		);

		$message = $this->createMock(IMessage::class);
		$message->method('setTo')->willReturnCallback(
			function (array $to) use ($message): IMessage {
				$this->sent[] = ['to' => (string)($to[0] ?? ''), 'body' => ''];
				return $message;
			}
		);
		$message->method('setPlainBody')->willReturnCallback(
			function (string $body) use ($message): IMessage {
				$last = (count($this->sent) - 1);
				if ($last >= 0) {
					$this->sent[$last]['body'] = $body;
				}
				return $message;
			}
		);
		$message->method('setSubject')->willReturn($message);
		$message->method('setFrom')->willReturn($message);
		$message->method('setReplyTo')->willReturn($message);

		$mailer->method('createMessage')->willReturn($message);
		$mailer->method('send')->willReturn([]);

		return $mailer;
	}//end makeMailer()

	/**
	 * A localiser that returns its source string with the arguments filled in.
	 *
	 * @return IL10N The localiser.
	 */
	private function makeL10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, array $parameters = []): string {
				if ($parameters === []) {
					return $text;
				}
				return vsprintf(str_replace('%s', '%1$s', $text), $parameters);
			}
		);
		$l10n->method('getLanguageCode')->willReturn('en');

		return $l10n;
	}//end makeL10n()

	/**
	 * Seed a mailing list.
	 *
	 * @param string $slug The list id.
	 * @param string $optInMode "double" or "soft".
	 * @param bool $publicSignup Whether public signup is open.
	 *
	 * @return void
	 */
	private function seedList(string $slug, string $optInMode, bool $publicSignup): void {
		$this->store->save(
			schemaSlug: 'mailingList',
			payload: [
				'name' => ucfirst($slug),
				'optInMode' => $optInMode,
				'publicSignup' => $publicSignup,
				'status' => 'active',
				'senderName' => 'Conduction',
				'senderEmail' => 'nieuwsbrief@conduction.nl',
				'footerAddress' => 'Turfmarkt 147, Den Haag',
			],
			id: $slug,
		);
	}//end seedList()

	/**
	 * Seed a membership in a given state.
	 *
	 * @param string $listId The list.
	 * @param string $contactId The contact.
	 * @param string $email The address.
	 * @param string $state The state.
	 *
	 * @return string The membership id.
	 */
	private function seedSubscription(string $listId, string $contactId, string $email, string $state): string {
		$saved = $this->store->save(
			schemaSlug: 'subscription',
			payload: [
				'listId' => $listId,
				'contactId' => $contactId,
				'email' => $email,
				'state' => $state,
				'source' => 'seed',
				'lawfulBasis' => 'consent',
			],
		);

		return $this->store->idOf($saved);
	}//end seedSubscription()

	/**
	 * Seed a confirmed membership whose list consent stands.
	 *
	 * @param string $listId The list.
	 * @param string $contactId The contact.
	 * @param string $email The address.
	 *
	 * @return string The membership id.
	 */
	private function seedConfirmed(string $listId, string $contactId, string $email): string {
		$this->listConsent[$contactId . '|' . $listId] = true;
		return $this->seedSubscription(
			listId: $listId,
			contactId: $contactId,
			email: $email,
			state: 'confirmed',
		);
	}//end seedConfirmed()

	/**
	 * Pull the confirmation token out of the last mail the service sent.
	 *
	 * @return string The token.
	 */
	private function tokenFromLastMail(): string {
		$body = (string)($this->sent[(count($this->sent) - 1)]['body'] ?? '');
		$matched = preg_match('#https://crm\.test/(\S+)#', $body, $matches);
		$this->assertSame(1, $matched, 'The confirmation mail must carry a link');

		return $matches[1];
	}//end tokenFromLastMail()
}//end class
