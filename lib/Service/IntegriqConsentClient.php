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

use OCA\Pipelinq\AppInfo\Application;
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
			$store = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_STORE, self::STORE_PIPELINQ);
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
	 * or the fail-closed one when integriq could not give it.
	 *
	 * @param string                         $channel         `email`, `sms` or `whatsapp`.
	 * @param string                         $category        The message category.
	 * @param bool                           $requiresConsent True for marketing and business-initiated WhatsApp.
	 * @param array<int,array<string,mixed>> $recipients      Each `{address, contactRef?, listRef?, caseRef?}`.
	 * @param string                         $correlationId   Ties integriq's log to pipelinq's; generated when empty.
	 * @param string|null                    $inReplyTo       The inbound message a `reply` answers.
	 *
	 * @return array<string,array{send:bool,code:string,reason:string,unsubscribe:array<string,mixed>|null}> By address as given.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) requiresConsent is integriq's contract field.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-asks-integriq-before-every-non-exempt-message-req-cii-002
	 */
	public function decide(
		string $channel,
		string $category,
		bool $requiresConsent,
		array $recipients,
		string $correlationId = '',
		?string $inReplyTo = null,
	): array {
		if ($correlationId === '') {
			$correlationId = 'pipelinq-'.bin2hex(random_bytes(8));
		}

		$recipients = array_values(
			array_filter(
				$recipients,
				static fn (mixed $recipient): bool => is_array($recipient) === true && trim((string)($recipient['address'] ?? '')) !== ''
			)
		);

		$decisions = [];
		foreach (array_chunk($recipients, self::CHUNK) as $chunk) {
			$decisions += $this->decideChunk(
				channel: $channel,
				category: $category,
				requiresConsent: $requiresConsent,
				chunk: $chunk,
				correlationId: $correlationId,
				inReplyTo: $inReplyTo
			);
		}

		return $decisions;
	}//end decide()

	/**
	 * Ask for one recipient.
	 *
	 * @param string      $channel         The channel.
	 * @param string      $category        The category.
	 * @param bool        $requiresConsent Whether consent is required.
	 * @param string      $address         The address as it will be used.
	 * @param string      $contactRef      The pipelinq contact UUID, or empty.
	 * @param string|null $inReplyTo       The inbound message a `reply` answers.
	 * @param string      $listRef         The list, for a list send.
	 *
	 * @return array{send:bool,code:string,reason:string,unsubscribe:array<string,mixed>|null} The decision.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) requiresConsent is integriq's contract field.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-asks-integriq-before-every-non-exempt-message-req-cii-002
	 */
	public function decideOne(
		string $channel,
		string $category,
		bool $requiresConsent,
		string $address,
		string $contactRef = '',
		?string $inReplyTo = null,
		string $listRef = '',
	): array {
		if (trim($address) === '') {
			// No address to ask about: integriq cannot answer, so fail closed.
			return $this->unavailable(channel: $channel, category: $category, why: 'the contact has no address on this channel');
		}

		$recipient = ['address' => $address];
		if ($contactRef !== '') {
			$recipient['contactRef'] = $contactRef;
		}

		if ($listRef !== '') {
			$recipient['listRef'] = $listRef;
		}

		$decisions = $this->decide(
			channel: $channel,
			category: $category,
			requiresConsent: $requiresConsent,
			recipients: [$recipient],
			inReplyTo: $inReplyTo
		);

		return ($decisions[$address] ?? $this->unavailable(channel: $channel, category: $category, why: 'no answer for this recipient'));
	}//end decideOne()

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

		$evidence = ($request['evidence'] ?? []);
		if (is_array($evidence) === false) {
			$evidence = ['text' => (string)$evidence];
		}

		try {
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
				return $this->notRecorded(code: self::CODE_UNAVAILABLE, reason: 'the change event is not an event');
			}

			$this->dispatcher->dispatchTyped($event);
		} catch (Throwable $e) {
			return $this->notRecorded(code: self::CODE_UNAVAILABLE, reason: 'the change failed: '.$e->getMessage());
		}

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
	}//end record()

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
	 * @param string                         $channel         The channel.
	 * @param string                         $category        The category.
	 * @param bool                           $requiresConsent Whether consent is required.
	 * @param array<int,array<string,mixed>> $chunk           The recipients.
	 * @param string                         $correlationId   The correlation id.
	 * @param string|null                    $inReplyTo       The inbound message, for a reply.
	 *
	 * @return array<string,array{send:bool,code:string,reason:string,unsubscribe:array<string,mixed>|null}> By address.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) requiresConsent is integriq's contract field.
	 */
	private function decideChunk(
		string $channel,
		string $category,
		bool $requiresConsent,
		array $chunk,
		string $correlationId,
		?string $inReplyTo,
	): array {
		$eventClass = $this->eventClass(relative: $this->decisionEvent);
		$event = null;
		$why = '';
		if ($eventClass === null) {
			$why = 'integriq or its decision event is not installed';
		}

		if ($eventClass !== null) {
			try {
				$event = new $eventClass(
					'pipelinq',
					$channel,
					$category,
					$chunk,
					$correlationId,
					$this->baseUrl(),
					$requiresConsent,
					$inReplyTo,
				);
				if (($event instanceof Event) === false) {
					$event = null;
					$why = 'the decision event is not an event';
				}

				if ($event !== null) {
					$this->dispatcher->dispatchTyped($event);
				}
			} catch (Throwable $e) {
				$event = null;
				$why = 'the question failed: '.$e->getMessage();
			}
		}//end if

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

				$decisions[$address] = $this->unavailable(channel: $channel, category: $category, why: $reason);
				continue;
			}

			$decisions[$address] = $this->readAnswer(answer: $answer, category: $category);
		}//end foreach

		return $decisions;
	}//end decideChunk()

	/**
	 * Integriq's answer for one address, or null when it gave none.
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
		if (is_array($answer) === false || array_key_exists('send', $answer) === false) {
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
				'app' => Application::APP_ID,
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
			['app' => Application::APP_ID, 'code' => $code, 'reason' => $reason]
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
