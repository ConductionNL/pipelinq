<?php

/**
 * Pipelinq MailAccountTransport.
 *
 * Sends a rendered mail through the sender's own Nextcloud Mail account
 * outbox, for low-volume sends a tenant would rather route through their own
 * mailbox than a bulk provider. The Mail app (`custom_apps/mail`) ships no
 * OCP contract, so every class here is resolved lazily and duck-typed —
 * mirroring hermiq's `MailReadService::mail()` two-layer guard
 * (`class_exists()` before the container call, then try/catch around
 * `container->get()`) rather than `BlastService`'s simpler try/catch-only
 * pattern, because the Mail app — unlike OpenRegister — is genuinely
 * optional on an instance.
 *
 * TYPE BOUNDARY: `AccountService::find(string $userId, int $id): Account`
 * takes an `int` account id and scopes the lookup to `$userId` (the Mail
 * app's own IDOR guard). `mailTransport.mailAccountRef` is a schema string
 * (OpenRegister has no distinct integer-id concept), so it is cast to `int`
 * behind a `ctype_digit()` check; a malformed ref degrades soft rather than
 * letting a `TypeError` reach the caller.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Marketing\Transport
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-a-mail-account-transport-sends-through-the-senders-own-account
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing\Transport;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * MailAccountTransport: sends through the Nextcloud Mail app's outbox.
 *
 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-a-mail-account-transport-sends-through-the-senders-own-account
 */
final class MailAccountTransport implements TransportInterface {
	/**
	 * FQCN map for the Mail app classes this adapter needs, resolved lazily.
	 * `class_exists()` is checked BEFORE the container call so an absent
	 * Mail app never produces container-resolution log noise (hermiq
	 * precedent).
	 *
	 * @var array<string, string>
	 */
	private const MAIL_CLASSES = [
		'accounts' => '\OCA\Mail\Service\AccountService',
		'outbox' => '\OCA\Mail\Service\OutboxService',
		'localMessage' => '\OCA\Mail\Db\LocalMessage',
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container (for lazy Mail app resolution).
	 * @param LoggerInterface $logger The logger.
	 * @param string $mailAccountRef The Mail app's numeric account id, as a string.
	 * @param string $mailAccountUserId The Nextcloud user id that owns `$mailAccountRef`.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly string $mailAccountRef,
		private readonly string $mailAccountUserId,
	) {
	}//end __construct()

	/**
	 * Send through the Mail app's outbox.
	 *
	 * @param RenderedMail $mail The rendered mail to send.
	 *
	 * @return SendResult The outcome.
	 *
	 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-a-mail-account-transport-sends-through-the-senders-own-account
	 */
	public function send(RenderedMail $mail): SendResult {
		$accountId = $this->parseAccountId();
		if ($accountId === null || $this->mailAccountUserId === '') {
			return $this->malformed(mail: $mail);
		}

		$services = $this->mailServices();
		if ($services === null) {
			return $this->unavailable(mail: $mail);
		}

		$account = $this->ownersAccount(accountService: $services['accounts'], accountId: $accountId);
		if ($account instanceof Throwable) {
			return $this->lookupFailed(mail: $mail, failure: $account);
		}

		return $this->sendViaOutbox(outboxService: $services['outbox'], account: $account, mail: $mail);
	}//end send()

	/**
	 * The refusal of a malformed account reference or a missing owner, logged.
	 *
	 * @param RenderedMail $mail The rendered mail.
	 *
	 * @return SendResult
	 */
	private function malformed(RenderedMail $mail): SendResult {
		$this->logger->warning(
			'MailAccountTransport.send: malformed mailAccountRef/mailAccountUserId',
			['deliveryId' => $mail->deliveryId, 'mailAccountRef' => $this->mailAccountRef]
		);
		return new SendResult(accepted: false, error: 'malformed-mail-account-reference');
	}//end malformed()

	/**
	 * The refusal when the Mail app is not available, logged.
	 *
	 * @param RenderedMail $mail The rendered mail.
	 *
	 * @return SendResult
	 */
	private function unavailable(RenderedMail $mail): SendResult {
		$this->logger->info('MailAccountTransport.send: Mail app unavailable', ['deliveryId' => $mail->deliveryId]);
		return new SendResult(accepted: false, error: 'mail-app-not-available');
	}//end unavailable()

	/**
	 * The Mail app's account and outbox services; null when either is absent.
	 *
	 * @return array{accounts: object, outbox: object}|null
	 */
	private function mailServices(): ?array {
		$accountService = $this->resolve(key: 'accounts');
		$outboxService = $this->resolve(key: 'outbox');
		if ($accountService === null || $outboxService === null) {
			return null;
		}

		return ['accounts' => $accountService, 'outbox' => $outboxService];
	}//end mailServices()

	/**
	 * The owner's account, looked up for the configured owner only; the
	 * lookup's failure when it throws.
	 *
	 * @param object $accountService Resolved `AccountService`.
	 * @param int    $accountId      The account id.
	 *
	 * @return object The account, or the Throwable the lookup threw.
	 */
	private function ownersAccount(object $accountService, int $accountId): object {
		try {
			return $accountService->find($this->mailAccountUserId, $accountId);
		} catch (Throwable $e) {
			return $e;
		}
	}//end ownersAccount()

	/**
	 * The refusal of a failed account lookup, logged.
	 *
	 * @param RenderedMail $mail    The rendered mail.
	 * @param Throwable    $failure What the lookup threw.
	 *
	 * @return SendResult
	 */
	private function lookupFailed(RenderedMail $mail, Throwable $failure): SendResult {
		$this->logger->warning(
			'MailAccountTransport.send: account lookup failed',
			['deliveryId' => $mail->deliveryId, 'exception' => $failure->getMessage()]
		);
		return new SendResult(accepted: false, error: 'mail-account-not-found');
	}//end lookupFailed()

	/**
	 * Build a `LocalMessage`, save it to the outbox, and send it.
	 *
	 * @param object $outboxService Resolved `OutboxService`.
	 * @param object $account Resolved `Account`.
	 * @param RenderedMail $mail The rendered mail to send.
	 *
	 * @return SendResult The outcome.
	 */
	private function sendViaOutbox(object $outboxService, object $account, RenderedMail $mail): SendResult {
		if (class_exists(self::MAIL_CLASSES['localMessage']) === false) {
			return new SendResult(accepted: false, error: 'mail-app-not-available');
		}

		try {
			$saved = $outboxService->saveMessage($account, $this->localMessageFor(mail: $mail), [['email' => $mail->toEmail]], [], []);
			$outboxService->sendMessage($saved, $account);
		} catch (Throwable $e) {
			$this->logger->warning('MailAccountTransport.send: outbox send failed', ['deliveryId' => $mail->deliveryId, 'exception' => $e->getMessage()]);
			return new SendResult(accepted: false, error: 'mail-account-send-failed');
		}

		return new SendResult(accepted: true);
	}//end sendViaOutbox()

	/**
	 * The Mail app's `LocalMessage` for the mail: subject, plain and HTML body.
	 *
	 * @param RenderedMail $mail The rendered mail.
	 *
	 * @return object The local message.
	 */
	private function localMessageFor(RenderedMail $mail): object {
		$localMessageClass = self::MAIL_CLASSES['localMessage'];
		$localMessage = new $localMessageClass();
		$localMessage->setSubject($mail->subject);
		$localMessage->setBodyPlain($mail->text);
		$localMessage->setHtml(true);
		$localMessage->setBodyHtml($mail->html);

		return $localMessage;
	}//end localMessageFor()

	/**
	 * Parse `mailAccountRef` into the `int` the Mail app's API expects.
	 *
	 * @return int|null The parsed id, or null when `mailAccountRef` is not a
	 *                   plain non-negative integer string.
	 */
	private function parseAccountId(): ?int {
		if ($this->mailAccountRef === '' || ctype_digit($this->mailAccountRef) === false) {
			return null;
		}

		return (int)$this->mailAccountRef;
	}//end parseAccountId()

	/**
	 * Resolve a Mail app class lazily, `class_exists()`-guarded before the
	 * container call.
	 *
	 * @param string $key Key into {@see MAIL_CLASSES}.
	 *
	 * @return object|null The resolved service, or null when the Mail app is
	 *                      absent or resolution fails.
	 */
	private function resolve(string $key): ?object {
		$class = (self::MAIL_CLASSES[$key] ?? '');
		if ($class === '' || class_exists($class) === false) {
			return null;
		}

		$service = $this->fromContainer(class: $class);
		if (is_object($service) === false) {
			return null;
		}

		return $service;
	}//end resolve()

	/**
	 * The container's entry for a class; null (logged at debug) when it cannot build it.
	 *
	 * @param string $class The class name.
	 *
	 * @return mixed The entry.
	 */
	private function fromContainer(string $class): mixed {
		try {
			return $this->container->get($class);
		} catch (Throwable $e) {
			$this->logger->debug(
				'MailAccountTransport.resolve: container resolution failed',
				['class' => $class, 'exception' => $e->getMessage()]
			);
			return null;
		}
	}//end fromContainer()
}//end class
