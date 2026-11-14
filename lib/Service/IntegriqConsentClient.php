<?php

/**
 * Pipelinq IntegriqConsentClient.
 *
 * The one place pipelinq asks integriq whether a person may be messaged, and
 * the one place it hands integriq a person's wish. integriq owns the fleet's
 * opt-out and consent list (hydra opt-out-before-send); pipelinq asks and
 * obeys.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
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
 *
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-asks-integriq-before-every-non-exempt-message-req-cii-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Service\Consent\ConsentAsk;
use OCA\Pipelinq\Service\Consent\ConsentQuestion;
use OCA\Pipelinq\Support\FleetAppId;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Asks and records through integriq's two public events.
 *
 * Both events are named by string and found behind class_exists(), so
 * pipelinq stays installable without integriq and imports none of its
 * classes (ADR-041, gate-27).
 *
 * Fail closed (hydra decision 1): when the event class is missing, nobody
 * answers, or the listener throws, a non-exempt message is refused with
 * `authority-unavailable` and an exempt one (besluit, statutory, account,
 * security) is sent without a link.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Every branch is a guard on an event integriq may
 *   not ship, not answer or answer partly; each one must fail closed on its own.
 *
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-asks-integriq-before-every-non-exempt-message-req-cii-002
 */
class IntegriqConsentClient {

	/**
	 * The decision event, relative to integriq's namespace.
	 */
	public const DECISION_EVENT = 'Event\\OutboundSendDecisionRequestedEvent';

	/**
	 * The change event, relative to integriq's namespace.
	 */
	public const CHANGE_EVENT = 'Event\\OptOutChangeRequestedEvent';

	/**
	 * The cutover flag: which store answers pipelinq's consent questions.
	 */
	public const CONFIG_STORE = 'consent.store';

	/**
	 * When the cutover happened, as a unix timestamp. Fallback writes after it are replayed.
	 */
	public const CONFIG_CUTOVER_AT = 'consent.cutover_at';

	/**
	 * The store value before the cutover, and after a rollback.
	 */
	public const STORE_PIPELINQ = 'pipelinq';

	/**
	 * The store value after a clean migration.
	 */
	public const STORE_INTEGRIQ = 'integriq';

	/**
	 * The code for "integriq could not answer".
	 */
	public const CODE_UNAVAILABLE = 'authority-unavailable';

	/**
	 * Decision code: send.
	 */
	public const CODE_ALLOWED = 'allowed';

	/**
	 * Decision code: an opt-out matched.
	 */
	public const CODE_OPTED_OUT = 'opted-out';

	/**
	 * Category: an appointment or another reminder. Respects opt-outs.
	 */
	public const CATEGORY_REMINDER = 'reminder';

	/**
	 * Category: transactional mail that is not one of the others.
	 */
	public const CATEGORY_SERVICE = 'service';

	/**
	 * Category: blasts, journeys and list mail. Needs consent first.
	 */
	public const CATEGORY_MARKETING = 'marketing';

	/**
	 * Category: an answer to the contact's own message. Needs `inReplyTo`.
	 */
	public const CATEGORY_REPLY = 'reply';

	/**
	 * Category: password reset, account created, data export ready. Exempt.
	 */
	public const CATEGORY_ACCOUNT = 'account';

	/**
	 * The state that clears a contact's link and evidence and keeps the opt-out.
	 */
	public const STATE_ERASE_CONTACT = 'erase-contact';

	/**
	 * How many recipients one question carries (hydra design section 9).
	 */
	public const CHUNK = 500;

	/**
	 * The exempt floor, as hydra's category list fixes it. A contract constant:
	 * with integriq absent there is nothing to read config from.
	 *
	 * @var array<int,string>
	 */
	private const EXEMPT = ['besluit', 'statutory', 'account', 'security'];

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $dispatcher     Carries both events to integriq.
	 * @param IAppConfig       $appConfig      Holds the cutover flag.
	 * @param IURLGenerator    $urlGenerator   Gives integriq the instance url for the link.
	 * @param LoggerInterface  $logger         Logs every refusal integriq could not answer.
	 * @param string           $decisionEvent  The decision event, relative to integriq's namespace.
	 * @param string           $changeEvent    The change event, relative to integriq's namespace.
	 */
	public function __construct(
		private readonly IEventDispatcher $dispatcher,
		private readonly IAppConfig $appConfig,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
		private readonly string $decisionEvent = self::DECISION_EVENT,
		private readonly string $changeEvent = self::CHANGE_EVENT,
	) {
	}//end __construct()

	/**
	 * Whether a category is exempt from opt-outs. Exact match only.
	 *
	 * @param string $category The category.
	 *
	 * @return bool True when it is.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-asks-integriq-before-every-non-exempt-message-req-cii-002
	 */
	public static function isExempt(string $category): bool {
		return in_array($category, self::EXEMPT, true);
	}//end isExempt()

	/**
	 * Whether pipelinq's consent questions go to integriq.
	 *
	 * @return bool True after a clean migration set `consent.store=integriq`.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-s-consent-records-are-migrated-into-integriq-once-req-cii-001
	 */
	public function isCutover(): bool {
		try {
			$store = $this->appConfig->getValueString(AppIdentity::APP_ID, self::CONFIG_STORE, self::STORE_PIPELINQ);
		} catch (Throwable $e) {
			return false;
		}

		return strtolower(trim($store)) === self::STORE_INTEGRIQ;
	}//end isCutover()

	/**
	 * Whether integriq's change event exists on this instance.
	 *
	 * @return bool True when it does.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-s-consent-records-are-migrated-into-integriq-once-req-cii-001
	 */
	public function canRecord(): bool {
		return $this->eventClass(relative: $this->changeEvent) !== null;
	}//end canRecord()

	/**
	 * Ask whether each recipient may be sent this message.
	 *
	 * One event per chunk of 500. Every recipient gets an answer: integriq's,
	 * or the fail-closed one when integriq could not give it. An empty
	 * correlation id is generated.
	 *
	 * @param ConsentQuestion                $question   The kind of message, correlation id, reply and probe flag.
	 * @param array<int,array<string,mixed>> $recipients Each `{address, contactRef?, listRef?, caseRef?}`.
	 *
	 * @return array<string,array{send:bool,code:string,reason:string,unsubscribe:array<string,mixed>|null}> By address as given.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-asks-integriq-before-every-non-exempt-message-req-cii-002
	 */
	public function decide(ConsentQuestion $question, array $recipients): array {
		if ($question->correlationId === '') {
			$question = new ConsentQuestion(
				ask: $question->ask,
				correlationId: 'pipelinq-'.bin2hex(random_bytes(8)),
				inReplyTo: $question->inReplyTo,
				probe: $question->probe,
			);
		}

		$recipients = array_values(
			array_filter(
				$recipients,
				static fn (mixed $recipient): bool => is_array($recipient) === true && trim((string)($recipient['address'] ?? '')) !== ''
			)
		);

		$decisions = [];
		foreach (array_chunk($recipients, self::CHUNK) as $chunk) {
			$decisions += $this->decideChunk(question: $question, chunk: $chunk);
		}

		return $decisions;
	}//end decide()

	/**
	 * Ask for one recipient only to show their state, never before a send.
	 *
	 * The answer is the one a send would get, without unsubscribe material,
	 * and integriq writes nothing to its decision log. An older integriq ignores the
	 * flag and logs the ask, which is the safe way to fail.
	 *
	 * @param ConsentAsk          $ask       The channel, category and whether consent is required.
	 * @param array<string,mixed> $recipient `{address, contactRef?}`; the contact ref is the pipelinq contact UUID.
	 *
	 * @return array{send:bool,code:string,reason:string,unsubscribe:array<string,mixed>|null} The decision.
	 *
	 * @spec openspec/specs/consent-in-integriq/spec.md#requirement-lateststate-asks-integriq-as-a-probe-req-cii-007
	 */
	public function probeOne(ConsentAsk $ask, array $recipient): array {
		return $this->askOne(
			question: new ConsentQuestion(ask: $ask, correlationId: '', inReplyTo: null, probe: true),
			recipient: ['address' => (string)($recipient['address'] ?? ''), 'contactRef' => (string)($recipient['contactRef'] ?? '')]
		);
	}//end probeOne()

	/**
	 * Ask for one recipient.
	 *
	 * @param ConsentAsk          $ask       The channel, category and whether consent is required.
	 * @param array<string,mixed> $recipient `{address, contactRef?, listRef?, inReplyTo?}`: the address as it will
	 *                                       be used, the pipelinq contact UUID, the list for a list send, and the
	 *                                       inbound message a `reply` answers.
	 *
	 * @return array{send:bool,code:string,reason:string,unsubscribe:array<string,mixed>|null} The decision.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-asks-integriq-before-every-non-exempt-message-req-cii-002
	 */
	public function decideOne(ConsentAsk $ask, array $recipient): array {
		return $this->askOne(
			question: new ConsentQuestion(ask: $ask, correlationId: '', inReplyTo: ($recipient['inReplyTo'] ?? null), probe: false),
			recipient: [
				'address' => (string)($recipient['address'] ?? ''),
				'contactRef' => (string)($recipient['contactRef'] ?? ''),
				'listRef' => (string)($recipient['listRef'] ?? ''),
			]
		);
	}//end decideOne()

	/**
	 * Put one question about one recipient; fail closed when there is no address or no answer.
	 *
	 * @param ConsentQuestion      $question  The question.
	 * @param array<string,string> $recipient The address and refs; empty refs are left out.
	 *
	 * @return array{send:bool,code:string,reason:string,unsubscribe:array<string,mixed>|null} The decision.
	 */
	private function askOne(ConsentQuestion $question, array $recipient): array {
		$ask = $question->ask;
		$address = $recipient['address'];
		if (trim($address) === '') {
			// No address to ask about: integriq cannot answer, so fail closed.
			return $this->unavailable(channel: $ask->channel, category: $ask->category, why: 'the contact has no address on this channel');
		}

		$decisions = $this->decide(
			question: $question,
			recipients: [array_filter($recipient, static fn (string $value): bool => $value !== '')]
		);

		return ($decisions[$address] ?? $this->unavailable(channel: $ask->channel, category: $ask->category, why: 'no answer for this recipient'));
	}//end askOne()

	/**
	 * Hand integriq one wish: an opt-out, a consent or a contact erasure.
	 *
	 * @param array<string,mixed> $request `address`, `state`, `scope`, `channel`, `ref`,
	 *                                     `contactRef`, `lawfulBasis`, `evidence`, `source`,
	 *                                     `legacyRef`, `purpose`, `correlationId`.
	 *
	 * @return array{recorded:bool,recordId:int|null,code:string,reason:string} `recorded` false keeps the wish in pipelinq.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-writes-every-wish-to-integriq-req-cii-003
	 */
	public function record(array $request): array {
		$eventClass = $this->eventClass(relative: $this->changeEvent);
		if ($eventClass === null) {
			return $this->notRecorded(code: self::CODE_UNAVAILABLE, reason: 'integriq or its change event is not installed');
		}

		try {
			$event = $this->newChangeEvent(eventClass: $eventClass, request: $request);
			if ($event === null) {
				return $this->notRecorded(code: self::CODE_UNAVAILABLE, reason: 'the change event is not an event');
			}

			$this->dispatcher->dispatchTyped($event);
		} catch (Throwable $e) {
			return $this->notRecorded(code: self::CODE_UNAVAILABLE, reason: 'the change failed: '.$e->getMessage());
		}

		return $this->readChange(event: $event);
	}//end record()

	/**
	 * Build integriq's change event from a request, in its constructor order.
	 *
	 * @param string              $eventClass The event class.
	 * @param array<string,mixed> $request    The request.
	 *
	 * @return Event|null The event, or null when the class is no event.
	 */
	private function newChangeEvent(string $eventClass, array $request): ?Event {
		$evidence = ($request['evidence'] ?? []);
		if (is_array($evidence) === false) {
			$evidence = ['text' => (string)$evidence];
		}

		$event = new $eventClass(
				'pipelinq',
				(string)($request['address'] ?? ''),
				(string)($request['state'] ?? ''),
				(string)($request['scope'] ?? 'channel'),
				(string)($request['channel'] ?? ''),
				(string)($request['ref'] ?? ''),
				(string)($request['contactRef'] ?? ''),
				(string)($request['lawfulBasis'] ?? ''),
				$evidence,
				(string)($request['source'] ?? ''),
				(string)($request['correlationId'] ?? ('pipelinq-'.bin2hex(random_bytes(8)))),
				(string)($request['legacyRef'] ?? ''),
				(string)($request['purpose'] ?? ''),
		);
		if (($event instanceof Event) === false) {
			return null;
		}

		return $event;
	}//end newChangeEvent()

	/**
	 * Read integriq's answer to a change event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return array{recorded:bool,recordId:int|null,code:string,reason:string} The outcome.
	 */
	private function readChange(Event $event): array {
		if (method_exists($event, 'isHandled') === false || $event->isHandled() !== true) {
			return $this->notRecorded(code: self::CODE_UNAVAILABLE, reason: 'integriq did not answer');
		}

		$refusal = null;
		if (method_exists($event, 'getRefusal') === true) {
			$refusal = $event->getRefusal();
		}

		if (is_array($refusal) === true) {
			return $this->notRecorded(code: (string)($refusal['code'] ?? 'refused'), reason: (string)($refusal['reason'] ?? ''));
		}

		$recordId = null;
		if (method_exists($event, 'getRecordId') === true) {
			$recordId = $event->getRecordId();
		}

		return ['recorded' => true, 'recordId' => $recordId, 'code' => '', 'reason' => ''];
	}//end readChange()

	/**
	 * Ask integriq to drop a contact's link and evidence and keep the opt-out.
	 *
	 * @param string $contactRef The pipelinq contact UUID.
	 *
	 * @return array{recorded:bool,recordId:int|null,code:string,reason:string} The outcome.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-contact-erasure-keeps-the-opt-out-in-integriq-req-cii-005
	 */
	public function eraseContact(string $contactRef): array {
		return $this->record(
			request: [
				'address' => '',
				'state' => self::STATE_ERASE_CONTACT,
				'scope' => 'instance',
				'contactRef' => $contactRef,
				'source' => 'contact-erased',
			]
		);
	}//end eraseContact()

	/**
	 * Ask integriq for one chunk.
	 *
	 * @param ConsentQuestion                $question The question.
	 * @param array<int,array<string,mixed>> $chunk    The recipients.
	 *
	 * @return array<string,array{send:bool,code:string,reason:string,unsubscribe:array<string,mixed>|null}> By address.
	 */
	private function decideChunk(ConsentQuestion $question, array $chunk): array {
		[$event, $why] = $this->askChunk(question: $question, chunk: $chunk);

		$ask = $question->ask;
		$decisions = [];
		foreach ($chunk as $recipient) {
			$address = (string)($recipient['address'] ?? '');
			$answer = null;
			if ($event !== null) {
				$answer = $this->answerFor(event: $event, address: $address);
			}

			if ($answer === null) {
				$reason = $why;
				if ($reason === '') {
					$reason = 'integriq did not answer for this recipient';
				}

				$decisions[$address] = $this->unavailable(channel: $ask->channel, category: $ask->category, why: $reason);
				continue;
			}

			$decisions[$address] = $this->readAnswer(answer: $answer, category: $ask->category);
		}//end foreach

		return $decisions;
	}//end decideChunk()

	/**
	 * Dispatch the decision event for one chunk.
	 *
	 * @param ConsentQuestion                $question The question.
	 * @param array<int,array<string,mixed>> $chunk    The recipients.
	 *
	 * @return array{0:Event|null,1:string} The dispatched event, or null and why not.
	 */
	private function askChunk(ConsentQuestion $question, array $chunk): array {
		$eventClass = $this->eventClass(relative: $this->decisionEvent);
		if ($eventClass === null) {
			return [null, 'integriq or its decision event is not installed'];
		}

		try {
			// The ninth argument, probe, is ignored by an integriq that predates it.
			$ask = $question->ask;
			$event = new $eventClass(
				'pipelinq',
				$ask->channel,
				$ask->category,
				$chunk,
				$question->correlationId,
				$this->baseUrl(),
				$ask->requiresConsent,
				$question->inReplyTo,
				$question->probe
			);
			if (($event instanceof Event) === false) {
				return [null, 'the decision event is not an event'];
			}

			$this->dispatcher->dispatchTyped($event);
		} catch (Throwable $e) {
			return [null, 'the question failed: '.$e->getMessage()];
		}

		return [$event, ''];
	}//end askChunk()

	/**
	 * Integriq's answer for one address, or null when it gave none or its `send` is not a boolean.
	 *
	 * @param object $event   The dispatched event.
	 * @param string $address The address as given.
	 *
	 * @return array<string,mixed>|null The answer.
	 */
	private function answerFor(object $event, string $address): ?array {
		if (method_exists($event, 'isHandled') === false || $event->isHandled() !== true) {
			return null;
		}

		if (method_exists($event, 'getDecision') === false) {
			return null;
		}

		$answer = $event->getDecision($address);
		if (is_array($answer) === false || is_bool($answer['send'] ?? null) === false) {
			return null;
		}

		return $answer;
	}//end answerFor()

	/**
	 * One decision in the shape callers read.
	 *
	 * @param array<string,mixed> $answer   Integriq's answer.
	 * @param string              $category The category.
	 *
	 * @return array{send:bool,code:string,reason:string,unsubscribe:array<string,mixed>|null} The decision.
	 */
	private function readAnswer(array $answer, string $category): array {
		$unsubscribe = null;
		if (is_array($answer['unsubscribe'] ?? null) === true && self::isExempt(category: $category) === false) {
			$unsubscribe = $answer['unsubscribe'];
		}

		return [
			'send' => ($answer['send'] === true),
			'code' => (string)($answer['code'] ?? ''),
			'reason' => (string)($answer['reason'] ?? ''),
			'unsubscribe' => $unsubscribe,
		];
	}//end readAnswer()

	/**
	 * The fail-closed answer: exempt goes out without a link, the rest is refused and logged.
	 *
	 * @param string $channel  The channel, for the log.
	 * @param string $category The category.
	 * @param string $why      What went wrong, for the log.
	 *
	 * @return array{send:bool,code:string,reason:string,unsubscribe:array<string,mixed>|null} The decision.
	 */
	private function unavailable(string $channel, string $category, string $why): array {
		$exempt = self::isExempt(category: $category);
		$outcome = 'not sent';
		if ($exempt === true) {
			$outcome = 'sent, the category is exempt';
		}

		$this->logger->warning(
			'Pipelinq: integriq could not say whether this person may be messaged; '.$outcome,
			[
				'app' => AppIdentity::APP_ID,
				'channel' => $channel,
				'category' => $category,
				'code' => self::CODE_UNAVAILABLE,
				'why' => $why,
			]
		);

		return [
			'send' => $exempt,
			'code' => self::CODE_UNAVAILABLE,
			'reason' => 'integriq is not available, so pipelinq cannot check whether this person may be messaged.',
			'unsubscribe' => null,
		];
	}//end unavailable()

	/**
	 * A wish integriq did not take.
	 *
	 * @param string $code   Why, as a code.
	 * @param string $reason Why, in words.
	 *
	 * @return array{recorded:bool,recordId:int|null,code:string,reason:string} The outcome.
	 */
	private function notRecorded(string $code, string $reason): array {
		$this->logger->warning(
			'Pipelinq: integriq did not record the consent change; pipelinq keeps it and replays it',
			['app' => AppIdentity::APP_ID, 'code' => $code, 'reason' => $reason]
		);

		return ['recorded' => false, 'recordId' => null, 'code' => $code, 'reason' => $reason];
	}//end notRecorded()

	/**
	 * The integriq class for a relative name, or null when integriq lacks it.
	 *
	 * @param string $relative The class below integriq's namespace root.
	 *
	 * @return string|null The class.
	 */
	private function eventClass(string $relative): ?string {
		foreach (FleetAppId::classCandidates(canonical: 'integriq', relative: $relative) as $candidate) {
			if (class_exists($candidate) === true) {
				return $candidate;
			}
		}

		return null;
	}//end eventClass()

	/**
	 * The instance url integriq builds the link on.
	 *
	 * @return string The url, or empty for integriq's own.
	 */
	private function baseUrl(): string {
		try {
			return rtrim($this->urlGenerator->getAbsoluteURL('/'), '/');
		} catch (Throwable $e) {
			return '';
		}
	}//end baseUrl()
}//end class
