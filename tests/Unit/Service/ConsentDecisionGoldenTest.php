<?php

/**
 * Golden test for the consent decisions pipelinq takes before a send.
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
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Integriq\Event\OptOutChangeRequestedEvent;
use OCA\Integriq\Event\OutboundSendDecisionRequestedEvent;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\Pipelinq\Controller\MessagingController;
use OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\ChannelProviderRepository;
use OCA\Pipelinq\Service\ConsentService;
use OCA\Pipelinq\Service\ContactAddressLookup;
use OCA\Pipelinq\Service\IntegriqConsentClient;
use OCA\Pipelinq\Service\IntegriqMarketingConsent;
use OCA\Pipelinq\Service\MessagingService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

if (class_exists('OCA\\Integriq\\Event\\ConsentDecisionGoldenNotAnEvent') === false) {
	class_alias(ConsentDecisionGoldenNotAnEvent::class, 'OCA\\Integriq\\Event\\ConsentDecisionGoldenNotAnEvent');
}

/**
 * Every decision ConsentService, IntegriqMarketingConsent and the messaging
 * consent endpoint take for granted, refused, unknown, expired, malformed and
 * unreachable integriq answers: what they return, what they ask integriq,
 * what they store and what they log. Compared with ConsentDecisionGolden.json;
 * set CONSENT_DECISION_GOLDEN_RECORD=1 to rewrite it, then run once more
 * without it.
 *
 * @covers \OCA\Pipelinq\Service\IntegriqConsentClient
 * @covers \OCA\Pipelinq\Service\ConsentService
 * @covers \OCA\Pipelinq\Service\IntegriqMarketingConsent
 * @uses   \OCA\Pipelinq\AppInfo\OpenRegisterAutoloader
 * @uses   \OCA\Pipelinq\Controller\MessagingController
 * @uses   \OCA\Pipelinq\Service\Consent\ConsentAsk
 * @uses   \OCA\Pipelinq\Service\Consent\ConsentQuestion
 * @uses   \OCA\Pipelinq\Service\Consent\ConsentSubject
 * @uses   \OCA\Pipelinq\Service\Consent\ConsentWish
 * @uses   \OCA\Pipelinq\Service\ContactAddressLookup
 * @uses   \OCA\Pipelinq\Support\FleetAppId
 * @uses \OCA\Pipelinq\Util\EntityPayload
 * @uses \OCA\Pipelinq\Util\PayloadToArray
 * @uses \OCA\Pipelinq\Util\SerialisedPayload
 * @uses \OCA\Pipelinq\Util\ObjectToPlainArray
 * @uses \OCA\Pipelinq\Util\SerialisedToArray
 * @uses \OCA\Pipelinq\Util\AnyPayloadToArray
 * @uses \OCA\Pipelinq\Util\CastingPayloadToArray
 * @uses \OCA\Pipelinq\Util\NullablePayloadToArray
 * @uses \OCA\Pipelinq\Util\OptionalPayloadToArray
 *
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md
 * @uses \OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck
 */
class ConsentDecisionGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/ConsentDecisionGolden.json';

	private const UNSUBSCRIBE = ['url' => 'https://integriq.example/u/tok', 'oneClickUrl' => 'https://integriq.example/u/tok/one-click'];

	/**
	 * Every log call of the current scenario.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $logs = [];

	/**
	 * Run every scenario and compare it with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$actual = [];
		foreach ($this->scenarios() as $name => $scenario) {
			$actual[$name] = $this->runScenario(scenario: $scenario, forged: false);
		}

		$json = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)) . "\n";
		if (getenv('CONSENT_DECISION_GOLDEN_RECORD') !== false) {
			file_put_contents(self::GOLDEN, $json);
			$this->markTestSkipped('recorded');
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		$actual = json_decode($json, true);
		foreach ($expected as $name => $want) {
			$this->assertSame($want, ($actual[$name] ?? null), $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testGolden()

	/**
	 * Forged user, actor, contact and address values in the request change nothing.
	 *
	 * @return void
	 */
	public function testForgedRequestValuesChangeNothing(): void {
		foreach ($this->scenarios() as $name => $scenario) {
			if ($scenario['call'] !== 'controller.consent') {
				continue;
			}

			$plain = $this->runScenario(scenario: $scenario, forged: false);
			$forged = $this->runScenario(scenario: $scenario, forged: true);
			$this->assertSame($plain, $forged, $name);
		}
	}//end testForgedRequestValuesChangeNothing()

	/**
	 * The raw answers integriq can give for one address.
	 *
	 * @return array<string, mixed>
	 */
	private function answers(): array {
		return [
			'granted' => ['send' => true, 'overridden' => false, 'code' => 'allowed', 'reason' => '', 'unsubscribe' => self::UNSUBSCRIBE],
			'refused' => ['send' => false, 'overridden' => false, 'code' => 'opted-out', 'reason' => 'This address opted out (channel).', 'unsubscribe' => null],
			'unknown' => ['send' => false, 'overridden' => false, 'code' => 'no-consent', 'reason' => 'No recorded consent permits this message.', 'unsubscribe' => null],
			'expired' => ['send' => false, 'overridden' => false, 'code' => 'consent-expired', 'reason' => 'The consent ran out.', 'unsubscribe' => null],
			'override' => ['send' => true, 'overridden' => true, 'code' => 'exempt-override', 'reason' => '', 'unsubscribe' => self::UNSUBSCRIBE],
			'no send key' => ['code' => 'allowed', 'reason' => '', 'unsubscribe' => self::UNSUBSCRIBE],
			'send as text' => ['send' => 'true', 'code' => 'allowed', 'reason' => '', 'unsubscribe' => self::UNSUBSCRIBE],
			'send as int' => ['send' => 1, 'code' => 'allowed'],
			'send null' => ['send' => null, 'code' => 'allowed'],
			'odd types' => ['send' => true, 'code' => 7, 'reason' => null, 'unsubscribe' => 'https://not-an-array'],
		];
	}//end answers()

	/**
	 * The scenarios.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function scenarios(): array {
		$scenarios = [];
		foreach (array_keys($this->answers()) as $answer) {
			$scenarios['canSend: ' . $answer] = ['call' => 'canSend', 'args' => ['contactId' => 'contact-1', 'channel' => 'sms'], 'answer' => $answer];
			$scenarios['business: ' . $answer] = ['call' => 'canSendBusinessInitiated', 'args' => ['contactId' => 'contact-1', 'channel' => 'whatsapp'], 'answer' => $answer];
			$scenarios['latestState: ' . $answer] = ['call' => 'latestState', 'args' => ['contactId' => 'contact-1', 'channel' => 'sms'], 'answer' => $answer];
			$scenarios['marketing decide: ' . $answer] = ['call' => 'marketing.decide', 'args' => ['contactId' => 'contact-1', 'channel' => 'email', 'category' => 'marketing', 'requiresConsent' => true, 'listId' => 'list-1'], 'answer' => $answer];
		}

		foreach (['unhandled', 'throw', 'handled, no decisions', 'handled, then dropped', 'no event class', 'not an event'] as $mode) {
			$scenarios['canSend: integriq ' . $mode] = ['call' => 'canSend', 'args' => ['contactId' => 'contact-1', 'channel' => 'sms'], 'mode' => $mode];
			$scenarios['latestState: integriq ' . $mode] = ['call' => 'latestState', 'args' => ['contactId' => 'contact-1', 'channel' => 'whatsapp'], 'mode' => $mode];
			$scenarios['marketing decide, exempt: integriq ' . $mode] = ['call' => 'marketing.decide', 'args' => ['contactId' => 'contact-1', 'channel' => 'email', 'category' => 'account', 'requiresConsent' => false], 'mode' => $mode];
			$scenarios['members: integriq ' . $mode] = ['call' => 'marketing.decideMembers', 'args' => ['members' => $this->members(), 'channel' => 'email', 'category' => 'marketing', 'requiresConsent' => true], 'mode' => $mode];
		}

		return $scenarios + [
			'canSend: reply in a thread'              => ['call' => 'canSend', 'args' => ['contactId' => 'contact-1', 'channel' => 'whatsapp', 'inReplyTo' => 'in-1'], 'answer' => 'granted'],
			'canSend: empty reply id'                 => ['call' => 'canSend', 'args' => ['contactId' => 'contact-1', 'channel' => 'sms', 'inReplyTo' => ''], 'answer' => 'refused'],
			'canSend: address given'                  => ['call' => 'canSend', 'args' => ['contactId' => 'contact-1', 'channel' => 'sms', 'address' => '+31 6 0000 0009'], 'answer' => 'granted'],
			'canSend: contact not in openregister'    => ['call' => 'canSend', 'args' => ['contactId' => 'contact-missing', 'channel' => 'sms'], 'answer' => 'granted'],
			'canSend: contact without a phone'        => ['call' => 'canSend', 'args' => ['contactId' => 'contact-3', 'channel' => 'sms'], 'answer' => 'granted'],
			'canSend: no contact'                     => ['call' => 'canSend', 'args' => ['contactId' => '', 'channel' => 'sms'], 'answer' => 'granted'],
			'canSend: no channel'                     => ['call' => 'canSend', 'args' => ['contactId' => 'contact-1', 'channel' => ''], 'answer' => 'granted'],
			'canSend: before cutover, no record'      => ['call' => 'canSend', 'args' => ['contactId' => 'contact-1', 'channel' => 'sms'], 'answer' => 'refused', 'cutover' => false],
			'canSend: before cutover, opted out'      => ['call' => 'canSend', 'args' => ['contactId' => 'contact-1', 'channel' => 'sms'], 'answer' => 'granted', 'cutover' => false, 'stored' => [['uuid' => 'r-1', 'contactId' => 'contact-1', 'channel' => 'sms', 'state' => 'opted-out', 'recordedAt' => '2030-01-01T00:00:00.000000Z']]],
			'business: contact by email id'           => ['call' => 'canSendBusinessInitiated', 'args' => ['contactId' => 'Jan@Example.nl', 'channel' => 'whatsapp'], 'answer' => 'granted'],
			'business: before cutover, opted in'      => ['call' => 'canSendBusinessInitiated', 'args' => ['contactId' => 'contact-1', 'channel' => 'sms'], 'answer' => 'refused', 'cutover' => false, 'stored' => [['uuid' => 'r-2', 'contactId' => 'contact-1', 'channel' => 'sms', 'state' => 'opted-in', 'recordedAt' => '2030-01-01T00:00:00.000000Z']]],
			'latestState: contact without a phone'    => ['call' => 'latestState', 'args' => ['contactId' => 'contact-3', 'channel' => 'sms'], 'answer' => 'granted'],
			'latestState: email of an email contact'  => ['call' => 'latestState', 'args' => ['contactId' => 'contact-3', 'channel' => 'email'], 'answer' => 'refused'],
			'marketing decide: no list'               => ['call' => 'marketing.decide', 'args' => ['contactId' => 'contact-1', 'channel' => 'sms', 'category' => 'service', 'requiresConsent' => false], 'answer' => 'granted'],
			'marketing decide: no address'            => ['call' => 'marketing.decide', 'args' => ['contactId' => 'contact-3', 'channel' => 'sms', 'category' => 'marketing', 'requiresConsent' => true], 'answer' => 'granted'],
			'marketing decide: email contact id'      => ['call' => 'marketing.decide', 'args' => ['contactId' => 'a@example.nl', 'channel' => 'email', 'category' => 'service', 'requiresConsent' => false, 'listId' => ''], 'answer' => 'granted'],
			'members: mixed answers'                  => ['call' => 'marketing.decideMembers', 'args' => ['members' => $this->members(), 'channel' => 'email', 'category' => 'marketing', 'requiresConsent' => true], 'byAddress' => ['one@example.nl' => 'granted', 'shared@example.nl' => 'expired', 'three@example.nl' => 'send as text']],
			'members: exempt keeps no link'           => ['call' => 'marketing.decideMembers', 'args' => ['members' => $this->members(), 'channel' => 'email', 'category' => 'security', 'requiresConsent' => false], 'answer' => 'override'],
			'members: sms'                            => ['call' => 'marketing.decideMembers', 'args' => ['members' => $this->members(), 'channel' => 'sms', 'category' => 'service', 'requiresConsent' => false], 'answer' => 'granted'],
			'members: nobody'                         => ['call' => 'marketing.decideMembers', 'args' => ['members' => [], 'channel' => 'email', 'category' => 'service', 'requiresConsent' => false], 'answer' => 'granted'],
			'controller consent: opt-in, granted'     => ['call' => 'controller.consent', 'args' => ['contactId' => 'contact-1', 'channel' => 'sms', 'action' => 'opt-in', 'source' => 'manual', 'evidence' => 'call 12', 'legalBasis' => 'consent'], 'answer' => 'granted'],
			'controller consent: opt-out, refused'    => ['call' => 'controller.consent', 'args' => ['contactId' => 'contact-1', 'channel' => 'whatsapp', 'action' => 'opt-out', 'source' => 'manual', 'evidence' => 'mail', 'legalBasis' => 'consent'], 'answer' => 'refused'],
			'controller consent: expired'             => ['call' => 'controller.consent', 'args' => ['contactId' => 'contact-1', 'channel' => 'sms', 'action' => 'opt-in', 'source' => 'manual', 'evidence' => 'call', 'legalBasis' => 'consent'], 'answer' => 'expired'],
			'controller consent: integriq down'       => ['call' => 'controller.consent', 'args' => ['contactId' => 'contact-1', 'channel' => 'sms', 'action' => 'opt-in', 'source' => 'manual', 'evidence' => 'call', 'legalBasis' => 'consent'], 'mode' => 'throw'],
			'controller consent: malformed'           => ['call' => 'controller.consent', 'args' => ['contactId' => 'contact-1', 'channel' => 'sms', 'action' => 'opt-out', 'source' => 'manual', 'evidence' => 'call', 'legalBasis' => 'consent'], 'answer' => 'send as text'],
			'controller consent: contact unknown'     => ['call' => 'controller.consent', 'args' => ['contactId' => 'contact-missing', 'channel' => 'sms', 'action' => 'opt-in', 'source' => 'manual', 'evidence' => 'call', 'legalBasis' => 'consent'], 'answer' => 'granted'],
			'controller consent: not privileged'      => ['call' => 'controller.consent', 'args' => ['contactId' => 'contact-1', 'channel' => 'sms', 'action' => 'opt-in', 'source' => 'manual', 'evidence' => 'call', 'legalBasis' => 'consent'], 'answer' => 'granted', 'sessionUid' => 'someone-else'],
		];
	}//end scenarios()

	/**
	 * Members of a list send: with and without their own email, a shared address, one without any.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function members(): array {
		return [
			['contactId' => 'contact-1'],
			['contactId' => 'contact-2', 'email' => ' shared@example.nl '],
			['contactId' => 'contact-4', 'email' => 'shared@example.nl'],
			['contactId' => 'contact-3'],
			['contactId' => '', 'email' => 'nobody@example.nl'],
			['contactId' => 'contact-missing'],
		];
	}//end members()

	/**
	 * Run one scenario.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 * @param bool                 $forged   Whether the request carries forged values.
	 *
	 * @return array<string, mixed>
	 */
	private function runScenario(array $scenario, bool $forged): array {
		$this->logs = [];
		$integriq = new ConsentDecisionGoldenIntegriq(mode: ($scenario['mode'] ?? 'answer'));
		$answers = $this->answers();
		if (isset($scenario['answer']) === true) {
			$integriq->fallback = $answers[$scenario['answer']];
		}

		foreach (($scenario['byAddress'] ?? []) as $address => $answer) {
			$integriq->byAddress[$address] = $answers[$answer];
		}

		$store = new ConsentDecisionGoldenStore();
		foreach (($scenario['stored'] ?? []) as $row) {
			$store->rows[$row['uuid']] = $row;
		}

		$cutover = ($scenario['cutover'] ?? true);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'consent.store' => ($cutover === true ? 'integriq' : 'pipelinq'),
				'consent.cutover_at' => '1700000000',
				default => $default,
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($store): object {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $store;
				}

				throw new RuntimeException('not registered: ' . $id);
			}
		);
		$logger = $this->logger();
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturn('https://pipelinq.example/');

		$decisionEvent = IntegriqConsentClient::DECISION_EVENT;
		if (($scenario['mode'] ?? '') === 'no event class') {
			$decisionEvent = 'Event\\NoSuchDecisionEvent';
		}

		if (($scenario['mode'] ?? '') === 'not an event') {
			$decisionEvent = 'Event\\ConsentDecisionGoldenNotAnEvent';
		}

		$client = new IntegriqConsentClient(
			dispatcher: $integriq,
			appConfig: $appConfig,
			urlGenerator: $urls,
			logger: $logger,
			decisionEvent: $decisionEvent,
		);
		$addresses = new ContactAddressLookup($container, $appConfig, $logger);
		$service = new ConsentService(container: $container, appConfig: $appConfig, logger: $logger, integriq: $client, addresses: $addresses);

		$result = match ($scenario['call']) {
			'marketing.decide' => (new IntegriqMarketingConsent($client, $addresses))->decide(...$scenario['args']),
			'marketing.decideMembers' => (new IntegriqMarketingConsent($client, $addresses))->decideMembers(...$scenario['args']),
			'controller.consent' => $this->callConsentEndpoint(service: $service, scenario: $scenario + ['forged' => $forged]),
			default => $service->{$scenario['call']}(...$scenario['args']),
		};

		return [
			'result' => $result,
			'questions' => $integriq->questions,
			'changes' => $integriq->changes,
			'written' => $store->written,
			'logs' => $this->logs,
		];
	}//end runScenario()

	/**
	 * Call MessagingController::consent with a session user and maybe forged request values.
	 *
	 * @param ConsentService       $service  The real consent service.
	 * @param array<string, mixed> $scenario The scenario, with `forged`.
	 *
	 * @return array<string, mixed>
	 */
	private function callConsentEndpoint(ConsentService $service, array $scenario): array {
		$request = $this->createMock(IRequest::class);
		if ($scenario['forged'] === true) {
			$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => 'forged-' . $key);
			$request->method('getParams')->willReturn(
				[
					'userId' => 'forged-user',
					'uid' => 'forged-user',
					'actor' => 'forged-user',
					'contactRef' => 'contact-2',
					'contactId' => 'contact-2',
					'address' => '+31699999999',
					'channel' => 'email',
					'category' => 'account',
					'requiresConsent' => false,
				]
			);
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn(($scenario['sessionUid'] ?? 'agent-session'));
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$policy = $this->createMock(ObjectOwnerAccessPolicy::class);
		$policy->method('isPrivileged')->willReturnCallback(static fn (string $uid): bool => $uid === 'agent-session');
		$messaging = $this->createMock(MessagingService::class);
		$messaging->method('loadContact')->willReturnCallback(
			static fn (string $contactId): ?array => ($contactId === 'contact-missing' ? null : ['uuid' => $contactId])
		);

		$controller = new MessagingController(
			$request,
			$messaging,
			$this->createMock(ChannelProviderRepository::class),
			$service,
			new CrmPrivilegeCheck($session, $policy),
		);
		$response = $controller->consent(...$scenario['args']);

		return ['status' => $response->getStatus(), 'data' => $response->getData()];
	}//end callConsentEndpoint()

	/**
	 * A logger that keeps every call of every level.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					$this->logs[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
				}
			);
		}

		return $logger;
	}//end logger()
}//end class

/**
 * Integriq as a dispatcher that answers from a script, and fails the way the real one fails.
 */
class ConsentDecisionGoldenIntegriq implements IEventDispatcher {
	/**
	 * The answer for any address not in $byAddress, or null for none.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $fallback = null;

	/**
	 * Answers by address.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $byAddress = [];

	/**
	 * Every decision question, flattened.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $questions = [];

	/**
	 * Every change request.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $changes = [];

	/**
	 * Constructor.
	 *
	 * @param string $mode answer, unhandled, throw, `handled, no decisions` or `handled, then dropped`.
	 */
	public function __construct(private readonly string $mode) {
	}//end __construct()

	public function addListener(string $eventName, callable $listener, int $priority = 0): void {
	}//end addListener()

	public function removeListener(string $eventName, callable $listener): void {
	}//end removeListener()

	public function addServiceListener(string $eventName, string $className, int $priority = 0): void {
	}//end addServiceListener()

	public function hasListeners(string $eventName): bool {
		return true;
	}//end hasListeners()

	public function dispatch(string $eventName, Event $event): void {
		$this->dispatchTyped(event: $event);
	}//end dispatch()

	public function dispatchTyped(Event $event): void {
		if ($event instanceof OptOutChangeRequestedEvent) {
			$request = $event->toRequest();
			$request['correlationId'] = preg_replace('/^pipelinq-[0-9a-f]{16}$/', 'pipelinq-<random>', (string)($request['correlationId'] ?? ''));
			$this->changes[] = $request;
			$event->setRecordId(count($this->changes));
			$event->setHandled(true);
			return;
		}

		if (($event instanceof OutboundSendDecisionRequestedEvent) === false) {
			return;
		}

		$this->questions[] = [
			'sourceApp' => $event->getSourceApp(),
			'channel' => $event->getChannel(),
			'category' => $event->getCategory(),
			'recipients' => $event->getRecipients(),
			'correlationId' => preg_replace('/^pipelinq-[0-9a-f]{16}$/', 'pipelinq-<random>', $event->getCorrelationId()),
			'baseUrl' => $event->getBaseUrl(),
			'requiresConsent' => $event->requiresConsent(),
			'probe' => $event->isProbe(),
			'inReplyTo' => $event->getInReplyTo(),
		];
		$this->answer(event: $event);
	}//end dispatchTyped()

	/**
	 * Answer one decision event in the scripted mode.
	 *
	 * @param OutboundSendDecisionRequestedEvent $event The event.
	 *
	 * @return void
	 */
	private function answer(OutboundSendDecisionRequestedEvent $event): void {
		if ($this->mode === 'throw') {
			throw new RuntimeException('integriq listener exploded');
		}

		if ($this->mode === 'unhandled') {
			return;
		}

		if ($this->mode !== 'handled, no decisions') {
			foreach ($event->getRecipients() as $recipient) {
				$address = (string)($recipient['address'] ?? '');
				$answer = ($this->byAddress[$address] ?? $this->fallback);
				if ($answer !== null) {
					$event->setDecision($address, $answer);
				}
			}
		}

		$event->setHandled(true);
		if ($this->mode === 'handled, then dropped') {
			// The real listener un-handles the event when one address got no decision.
			$event->setHandled(false);
		}
	}//end answer()
}//end class

/**
 * OpenRegister's ObjectService as the consent path uses it. A missing object throws.
 */
class ConsentDecisionGoldenStore {
	/**
	 * Stored consent rows by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $rows = [];

	/**
	 * Every write.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $written = [];

	/**
	 * The contacts and clients.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $contacts = [
		'contact-1' => ['uuid' => 'contact-1', 'phone' => '+31612345678', 'email' => 'one@example.nl'],
		'contact-2' => ['uuid' => 'contact-2', 'phone' => '+31622222222', 'email' => 'two@example.nl'],
		'contact-3' => ['uuid' => 'contact-3', 'email' => 'three@example.nl'],
		'contact-4' => ['uuid' => 'contact-4', 'phone' => '06 4444 4444'],
	];

	/**
	 * Find one object.
	 *
	 * @param string $id       The id.
	 * @param mixed  $register The register.
	 * @param mixed  $schema   The schema.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws DoesNotExistException When there is no such object.
	 */
	public function find(string $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true): array {
		$schema = $options?->schema;
		if ($schema === 'contact' && isset($this->contacts[$id]) === true) {
			return $this->contacts[$id];
		}

		throw new DoesNotExistException('Object not found');
	}//end find()

	/**
	 * Save one object.
	 *
	 * @param array<string, mixed> $object   The object.
	 * @param mixed                $register The register.
	 * @param mixed                $schema   The schema.
	 * @param string|null          $uuid     The uuid.
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(array $object, $register = null, $schema = null, ?string $uuid = null): array {
		$object['uuid'] = ($uuid ?? ('row-' . (count($this->written) + 1)));
		if (isset($object['recordedAt']) === true) {
			$object['recordedAt'] = '<now>';
		}

		$this->rows[$object['uuid']] = $object;
		$this->written[] = $object + ['_register' => $register, '_schema' => $schema];
		return $object;
	}//end saveObject()

	/**
	 * Find all objects matching the filters.
	 *
	 * @param array<string, mixed> $config The search config.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function findAll(array $config = []): array {
		$filters = ($config['filters'] ?? []);
		unset($filters['register'], $filters['schema']);
		$out = [];
		foreach ($this->rows as $row) {
			foreach ($filters as $key => $value) {
				if (($row[$key] ?? null) !== $value) {
					continue 2;
				}
			}

			$out[] = $row;
		}

		return $out;
	}//end findAll()
}//end class

/**
 * A class integriq might ship under the decision event's name that is no event.
 */
class ConsentDecisionGoldenNotAnEvent {
	/**
	 * Constructor.
	 *
	 * @param mixed ...$args The decision event's arguments.
	 */
	public function __construct(mixed ...$args) {
	}//end __construct()
}//end class
