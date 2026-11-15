<?php

/**
 * Pipelinq ConfirmationMail.
 *
 * The mail that asks a new subscriber to confirm their membership.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-self-service-subscribe-creates-a-pending-subscription
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\IL10N;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use Throwable;

/**
 * Composes and sends the confirmation mail of a pending membership. Built by
 * SubscriptionService, which logs a failure.
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-self-service-subscribe-creates-a-pending-subscription
 */
final class ConfirmationMail {
	/**
	 * Constructor.
	 *
	 * @param IMailer $mailer The mailer.
	 * @param IL10N   $l10n   The translator.
	 */
	public function __construct(
		private IMailer $mailer,
		private IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Send the confirmation mail with the list's sender and reply-to, when valid.
	 *
	 * @param ListSignupRun $run        The pending membership (its list and address).
	 * @param string        $confirmUrl The confirmation link.
	 *
	 * @return string|null Null when sent, the failure message otherwise.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-self-service-subscribe-creates-a-pending-subscription
	 */
	public function send(ListSignupRun $run, string $confirmUrl): ?string {
		$listName = (string)($run->list['name'] ?? '');
		$subject = $this->l10n->t('Confirm your subscription to %s', [$listName]);
		$template = "You asked to receive %1\$s.\n\n"
			. "Open this link to confirm. If you did not ask for this, "
			. "ignore this message and nothing happens.\n\n%2\$s\n\n%3\$s";
		$body = $this->l10n->t($template, [$listName, $confirmUrl, (string)($run->list['footerAddress'] ?? '')]);

		try {
			$message = $this->mailer->createMessage();
			$message->setTo([$run->email]);
			$message->setSubject($subject);
			$message->setPlainBody($body);
			$this->setSender(message: $message, list: $run->list);
			$this->mailer->send($message);
			return null;
		} catch (Throwable $e) {
			return $e->getMessage();
		}
	}//end send()

	/**
	 * Set the list's sender and reply-to addresses, each only when it is a valid address.
	 *
	 * @param IMessage             $message The message.
	 * @param array<string, mixed> $list    The list.
	 *
	 * @return void
	 */
	private function setSender(IMessage $message, array $list): void {
		$sender = trim((string)($list['senderEmail'] ?? ''));
		if ($sender !== '' && $this->mailer->validateMailAddress($sender) === true) {
			$message->setFrom([$sender => (string)($list['senderName'] ?? $sender)]);
		}

		$replyTo = trim((string)($list['replyTo'] ?? ''));
		if ($replyTo !== '' && $this->mailer->validateMailAddress($replyTo) === true) {
			$message->setReplyTo([$replyTo]);
		}
	}//end setSender()
}//end class
