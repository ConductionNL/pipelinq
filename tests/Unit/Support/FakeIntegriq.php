<?php

/**
 * An in-memory integriq behind a real IEventDispatcher.
 *
 * Answers the two opt-out events the way integriq's listeners do at
 * development b2005341 (OptOutRegistry::decideMany() and record()): reply
 * passes, the exempt floor sends, an opt-out refuses, consent rules apply when
 * consent is required, a known legacyRef writes nothing and erase-contact
 * keeps the row. It can also be switched to "unhandled" or "throwing" to
 * exercise the fail-closed path.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Support;

use OCA\Integriq\Event\OptOutChangeRequestedEvent;
use OCA\Integriq\Event\OutboundSendDecisionRequestedEvent;
use OCP\EventDispatcher\Event;
use OCA\Pipelinq\Service\IntegriqConsentClient;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Fake integriq. `$rows` is its opt-out table, keyed by dedupe key.
 */
class FakeIntegriq implements IEventDispatcher {

	public const MODE_ANSWER = 'answer';
	public const MODE_UNHANDLED = 'unhandled';
	public const MODE_THROW = 'throw';

	/** @var string */
	public string $mode = self::MODE_ANSWER;

	/** @var string Separate mode for the change event, so a refused write can be staged. */
	public string $changeMode = self::MODE_ANSWER;

	/** @var array<string, array<string, mixed>> */
	public array $rows = [];

	/** @var array<int, OutboundSendDecisionRequestedEvent> */
	public array $decisionEvents = [];

	/** @var array<int, OptOutChangeRequestedEvent> */
	public array $changeEvents = [];

	/** @var array<int, Event> Every other event dispatched. */
	public array $otherEvents = [];

	/** @var int */
	private int $nextId = 1;

	/**
	 * A client wired to this fake (or a fresh one), reading the flag from $appConfig.
	 *
	 * @param IAppConfig           $appConfig The config the flag is read from.
	 * @param FakeIntegriq|null    $integriq  The fake; a new one when null.
	 * @param LoggerInterface|null $logger    The logger.
	 *
	 * @return IntegriqConsentClient The client.
	 */
	public static function client(IAppConfig $appConfig, ?self $integriq = null, ?LoggerInterface $logger = null): IntegriqConsentClient {
		$urls = new class implements IURLGenerator {
			public function linkToRoute(string $routeName, array $arguments = []): string {
				return '/'.$routeName;
			}

			public function linkToRouteAbsolute(string $routeName, array $arguments = []): string {
				return 'https://pipelinq.example/'.$routeName;
			}

			public function linkToOCSRouteAbsolute(string $routeName, array $arguments = []): string {
				return 'https://pipelinq.example/ocs/'.$routeName;
			}

			public function linkTo(string $appName, string $file, array $args = []): string {
				return '/'.$appName.'/'.$file;
			}

			public function imagePath(string $appName, string $file): string {
				return '/'.$appName.'/img/'.$file;
			}

			public function getAbsoluteURL(string $url): string {
				return 'https://pipelinq.example'.$url;
			}

			public function linkToDocs(string $key): string {
				return '';
			}

			public function linkToDefaultPageUrl(): string {
				return '/';
			}

			public function getBaseUrl(): string {
				return 'https://pipelinq.example';
			}

			public function getWebroot(): string {
				return '';
			}

			public function linkToRemote(string $service): string {
				return 'https://pipelinq.example/remote.php/'.$service;
			}

			public function getLogoutUrl(): string {
				return '/logout';
			}
		};

		return new IntegriqConsentClient(
			dispatcher: ($integriq ?? new self()),
			appConfig: $appConfig,
			urlGenerator: $urls,
			logger: ($logger ?? new NullLogger()),
		);
	}//end client()

	/**
	 * Seed a row as if integriq had recorded it.
	 *
	 * @param array<string, mixed> $request The change request.
	 *
	 * @return void
	 */
	public function seed(array $request): void {
		$this->store(request: $request);
	}//end seed()

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
		if ($event instanceof OutboundSendDecisionRequestedEvent) {
			$this->decisionEvents[] = $event;
			$this->answerDecision(event: $event);
			return;
		}

		if ($event instanceof OptOutChangeRequestedEvent) {
			$this->changeEvents[] = $event;
			$this->answerChange(event: $event);
			return;
		}

		$this->otherEvents[] = $event;
	}//end dispatchTyped()

	/**
	 * The rows for one address.
	 *
	 * @param string $address The normalised address.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	public function rowsFor(string $address): array {
		return array_values(array_filter($this->rows, static fn (array $row): bool => $row['address'] === self::normalise($row['channel'], $address)));
	}//end rowsFor()

	/**
	 * Answer a decision event as integriq's listener does.
	 *
	 * @param OutboundSendDecisionRequestedEvent $event The event.
	 *
	 * @return void
	 */
	private function answerDecision(OutboundSendDecisionRequestedEvent $event): void {
		if ($this->mode === self::MODE_THROW) {
			throw new RuntimeException('integriq listener exploded');
		}

		if ($this->mode === self::MODE_UNHANDLED) {
			return;
		}

		foreach ($event->getRecipients() as $recipient) {
			$address = (string)($recipient['address'] ?? '');
			$event->setDecision($address, $this->decideOne(event: $event, recipient: $recipient));
		}

		$event->setHandled(true);
	}//end answerDecision()

	/**
	 * Decide one recipient.
	 *
	 * @param OutboundSendDecisionRequestedEvent $event     The event.
	 * @param array<string, mixed>               $recipient The recipient.
	 *
	 * @return array<string, mixed> The decision.
	 */
	private function decideOne(OutboundSendDecisionRequestedEvent $event, array $recipient): array {
		$category = $event->getCategory();
		$channel = $event->getChannel();
		$key = self::normalise($channel, (string)($recipient['address'] ?? ''));
		if ($category === 'reply' && (string)$event->getInReplyTo() !== '') {
			return ['send' => true, 'overridden' => false, 'code' => 'allowed', 'reason' => '', 'unsubscribe' => null];
		}

		$contactRef = (string)($recipient['contactRef'] ?? '');
		$listRef = (string)($recipient['listRef'] ?? '');
		$mine = array_filter(
			$this->rows,
			static fn (array $row): bool => $row['address'] === $key || ($contactRef !== '' && $row['contactRef'] === $contactRef)
		);

		$optOut = null;
		foreach ($mine as $row) {
			if ($row['state'] !== 'opted-out') {
				continue;
			}

			$covers = match ($row['scope']) {
				'instance' => true,
				'channel' => $row['channel'] === $channel,
				'list' => $listRef !== '' && $row['ref'] === $listRef,
				default => false,
			};
			if ($covers === true) {
				$optOut = $row;
				break;
			}
		}

		if (in_array($category, ['besluit', 'statutory', 'account', 'security'], true) === true) {
			return ['send' => true, 'overridden' => ($optOut !== null), 'code' => ($optOut === null ? 'allowed' : 'exempt-override'), 'reason' => '', 'unsubscribe' => null];
		}

		if ($optOut !== null) {
			return ['send' => false, 'overridden' => false, 'code' => 'opted-out', 'reason' => 'This address opted out ('.$optOut['scope'].').', 'unsubscribe' => null];
		}

		if ($event->requiresConsent() === true && $this->hasConsent(rows: $mine, channel: $channel, listRef: $listRef) === false) {
			return ['send' => false, 'overridden' => false, 'code' => 'no-consent', 'reason' => 'No recorded consent permits this message.', 'unsubscribe' => null];
		}

		$link = 'https://integriq.example/index.php/apps/integriq/unsubscribe/tok-'.md5($key.$channel);
		return [
			'send' => true,
			'overridden' => false,
			'code' => 'allowed',
			'reason' => '',
			'unsubscribe' => [
				'url' => $link,
				'oneClickUrl' => $link.'/one-click',
				'smsText' => 'Stop? Antwoord STOP',
				'headers' => ['List-Unsubscribe' => '<'.$link.'/one-click>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'],
			],
		];
	}//end decideOne()

	/**
	 * Integriq's consent rules.
	 *
	 * @param array<string, array<string, mixed>> $rows    The recipient's rows.
	 * @param string                              $channel The channel.
	 * @param string                              $listRef The list, or empty.
	 *
	 * @return bool True when a consent permits the send.
	 */
	private function hasConsent(array $rows, string $channel, string $listRef): bool {
		foreach ($rows as $row) {
			if ($row['state'] !== 'opted-in') {
				continue;
			}

			$covers = ($listRef !== '' && $row['scope'] === 'list' && $row['ref'] === $listRef)
				|| ($listRef === '' && $row['scope'] === 'channel' && $row['channel'] === $channel);
			if ($covers === false || $row['lawfulBasis'] === 'imported') {
				continue;
			}

			if ($row['lawfulBasis'] === 'soft-opt-in' && (($row['evidence']['objectionOffered'] ?? false) !== true)) {
				continue;
			}

			return true;
		}

		return false;
	}//end hasConsent()

	/**
	 * Answer a change event as integriq's listener does.
	 *
	 * @param OptOutChangeRequestedEvent $event The event.
	 *
	 * @return void
	 */
	private function answerChange(OptOutChangeRequestedEvent $event): void {
		if ($this->changeMode === self::MODE_THROW) {
			throw new RuntimeException('integriq listener exploded');
		}

		if ($this->changeMode === self::MODE_UNHANDLED) {
			return;
		}

		$request = $event->toRequest();
		if ($request['state'] === 'erase-contact') {
			$cleared = 0;
			foreach ($this->rows as $dedupe => $row) {
				if ($row['contactRef'] === $request['contactRef']) {
					$this->rows[$dedupe]['contactRef'] = '';
					$this->rows[$dedupe]['evidence'] = [];
					$cleared++;
				}
			}

			$event->setRecordId($cleared);
			$event->setHandled(true);
			return;
		}

		if (in_array($request['state'], ['opted-in', 'opted-out'], true) === false || self::normalise($request['channel'], $request['address']) === '') {
			$event->setHandled(true);
			$event->setRefusal('invalid request', 'invalid-request');
			return;
		}

		try {
			$event->setRecordId($this->store(request: $request));
		} catch (RuntimeException $e) {
			// The real listener logs and leaves the event unhandled.
			return;
		}

		$event->setHandled(true);
	}//end answerChange()

	/**
	 * Upsert a row; a known legacyRef writes nothing.
	 *
	 * @param array<string, mixed> $request The change request.
	 *
	 * @return int The row id.
	 */
	private function store(array $request): int {
		// integriq_opt_outs column widths (Version2Date20261005...): a longer
		// value is a database error, which the real listener leaves unhandled.
		$limits = ['legacyRef' => 64, 'source' => 64, 'lawfulBasis' => 32, 'channel' => 16, 'scope' => 16, 'state' => 16, 'purpose' => 32, 'sourceApp' => 64];
		foreach ($limits as $field => $max) {
			if (strlen((string)($request[$field] ?? '')) > $max) {
				throw new RuntimeException('SQLSTATE[22001]: value too long for '.$field);
			}
		}

		$legacyRef = (string)($request['legacyRef'] ?? '');
		if ($legacyRef !== '') {
			foreach ($this->rows as $row) {
				if ($row['legacyRef'] === $legacyRef) {
					return $row['id'];
				}
			}
		}

		$channel = (string)($request['channel'] ?? '');
		$scope = (string)($request['scope'] ?? 'instance');
		$address = self::normalise($channel, (string)($request['address'] ?? ''));
		$ref = (string)($request['ref'] ?? '');
		$dedupe = $address.'|'.$scope.'|'.$channel.'|'.$ref;
		$id = ($this->rows[$dedupe]['id'] ?? $this->nextId++);
		$this->rows[$dedupe] = [
			'id' => $id,
			'address' => $address,
			'state' => (string)($request['state'] ?? ''),
			'scope' => $scope,
			'channel' => $channel,
			'ref' => $ref,
			'contactRef' => (string)($request['contactRef'] ?? ''),
			'lawfulBasis' => (string)($request['lawfulBasis'] ?? ''),
			'evidence' => (array)($request['evidence'] ?? []),
			'source' => (string)($request['source'] ?? ''),
			'purpose' => (string)($request['purpose'] ?? ''),
			'legacyRef' => $legacyRef,
		];

		return $id;
	}//end store()

	/**
	 * A small stand-in for integriq's RecipientKey: lowercase email, digits for phones.
	 *
	 * @param string $channel The channel.
	 * @param string $address The address.
	 *
	 * @return string The key, or empty when unusable.
	 */
	public static function normalise(string $channel, string $address): string {
		$address = trim($address);
		if ($channel === 'email' || str_contains($address, '@') === true) {
			return strtolower($address);
		}

		$digits = preg_replace('/[^0-9+]/', '', $address);
		if (str_starts_with((string)$digits, '06') === true) {
			$digits = '+31'.substr((string)$digits, 1);
		}

		return (string)$digits;
	}//end normalise()
}//end class
