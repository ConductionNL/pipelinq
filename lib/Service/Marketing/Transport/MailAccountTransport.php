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
			$this->logger->warning(
				'MailAccountTransport.send: malformed mailAccountRef/mailAccountUserId',
				['deliveryId' => $mail->deliveryId, 'mailAccountRef' => $this->mailAccountRef]
			);
			return SendResult::failed(reason: 'malformed-mail-account-reference');
		}

		$accountService = $this->resolve(key: 'accounts');
		$outboxService = $this->resolve(key: 'outbox');
		if ($accountService === null || $outboxService === null) {
			$this->logger->info(
				'MailAccountTransport.send: Mail app unavailable',
				['deliveryId' => $mail->deliveryId]
			);
			return SendResult::failed(reason: 'mail-app-not-available');
		}

		try {
			$account = $accountService->find($this->mailAccountUserId, $accountId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'MailAccountTransport.send: account lookup failed',
				['deliveryId' => $mail->deliveryId, 'exception' => $e->getMessage()]
			);
			return SendResult::failed(reason: 'mail-account-not-found');
		}

		return $this->sendViaOutbox(
			outboxService: $outboxService,
			account: $account,
			mail: $mail,
		);
	}//end send()

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
		$localMessageClass = (self::MAIL_CLASSES['localMessage'] ?? '');
		if ($localMessageClass === '' || class_exists($localMessageClass) === false) {
			return SendResult::failed(reason: 'mail-app-not-available');
		}

		try {
			$localMessage = new $localMessageClass();
			$localMessage->setSubject($mail->subject);
			$localMessage->setBodyPlain($mail->text);
			$localMessage->setHtml(true);
			$localMessage->setBodyHtml($mail->html);

			$saved = $outboxService->saveMessage(
				$account,
				$localMessage,
				[['email' => $mail->toEmail]],
				[],
				[],
			);
			$outboxService->sendMessage($saved, $account);
		} catch (Throwable $e) {
			$this->logger->warning(
				'MailAccountTransport.send: outbox send failed',
				['deliveryId' => $mail->deliveryId, 'exception' => $e->getMessage()]
			);
			return SendResult::failed(reason: 'mail-account-send-failed');
		}

		return SendResult::accepted();
	}//end sendViaOutbox()

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

		try {
			$service = $this->container->get($class);
		} catch (Throwable $e) {
			$this->logger->debug(
				'MailAccountTransport.resolve: container resolution failed',
				['class' => $class, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if (is_object($service) === false) {
			return null;
		}

		return $service;
	}//end resolve()
}//end class
