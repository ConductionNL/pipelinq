<?php

/**
 * Pipelinq InstanceMailerTransport.
 *
 * Sends a rendered mail through the Nextcloud instance's own mail server via
 * `OCP\Mail\IMailer`. This is the transport every tenant gets for free,
 * seeded as the `default` `mailTransport` on a fresh install (member 1 of
 * `marketing-mail-transports`).
 *
 * PRIVATE-API DEPENDENCY: `IMessage` (the public contract returned by
 * `IMailer::createMessage()`) has no header setter — there is no OCP way to
 * set `List-Unsubscribe` or any other custom header. The runtime
 * implementation Nextcloud ships, `\OC\Mail\Message`, additionally exposes
 * `getSymfonyEmail(): Symfony\Component\Mime\Email` — undocumented, private,
 * and not part of the `IMessage` contract. This adapter reaches for it
 * ONLY behind a `method_exists()` guard, and degrades soft (logs, sends
 * without the extra headers) the moment a future Nextcloud release stops
 * returning that class. Verified against `lib/private/Mail/Mailer.php` and
 * `lib/private/Mail/Message.php` in the Nextcloud server tree — see
 * design.md.
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
 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-header-injection-on-the-instance-mailer-degrades-soft
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing\Transport;

use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * InstanceMailerTransport: sends through the Nextcloud instance mail server.
 *
 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-a-tenant-sends-with-zero-configuration
 */
final class InstanceMailerTransport implements TransportInterface {
	/**
	 * Constructor.
	 *
	 * @param IMailer $mailer Nextcloud's own mailer service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly IMailer $mailer,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Send through `IMailer`.
	 *
	 * @param RenderedMail $mail The rendered mail to send.
	 *
	 * @return SendResult The outcome.
	 *
	 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-header-injection-on-the-instance-mailer-degrades-soft
	 */
	public function send(RenderedMail $mail): SendResult {
		try {
			$message = $this->mailer->createMessage();
		} catch (Throwable $e) {
			$this->logger->warning(
				'InstanceMailerTransport.send: createMessage failed',
				['exception' => $e->getMessage()]
			);
			return SendResult::failed(reason: 'instance-mailer-unavailable');
		}

		$this->applyEnvelope(message: $message, mail: $mail);
		if ($mail->headers !== []) {
			$this->applyHeaders(message: $message, headers: $mail->headers);
		}

		try {
			$failedRecipients = $this->mailer->send($message);
		} catch (Throwable $e) {
			$this->logger->warning(
				'InstanceMailerTransport.send: send failed',
				['deliveryId' => $mail->deliveryId, 'exception' => $e->getMessage()]
			);
			return SendResult::failed(reason: 'instance-mailer-send-failed');
		}

		if (is_array($failedRecipients) === true && $failedRecipients !== []) {
			$this->logger->warning(
				'InstanceMailerTransport.send: provider rejected recipient(s)',
				['deliveryId' => $mail->deliveryId, 'failedRecipients' => $failedRecipients]
			);
			return SendResult::failed(reason: 'recipient-rejected');
		}

		return SendResult::accepted();
	}//end send()

	/**
	 * Set from/replyTo/to/subject/body via the public `IMessage` contract.
	 *
	 * @param IMessage $message The message being built.
	 * @param RenderedMail $mail The rendered mail.
	 *
	 * @return void
	 */
	private function applyEnvelope(IMessage $message, RenderedMail $mail): void {
		$from = [$mail->fromEmail];
		if ($mail->fromName !== '') {
			$from = [$mail->fromEmail => $mail->fromName];
		}

		$message->setFrom(addresses: $from);
		$message->setTo(recipients: [$mail->toEmail]);
		if ($mail->replyTo !== '') {
			$message->setReplyTo(addresses: [$mail->replyTo]);
		}

		$message->setSubject(subject: $mail->subject);
		if ($mail->html !== '') {
			$message->setHtmlBody(body: $mail->html);
		}

		if ($mail->text !== '') {
			$message->setPlainBody(body: $mail->text);
		}
	}//end applyEnvelope()

	/**
	 * Set extra headers via the guarded private `getSymfonyEmail()` path.
	 *
	 * Degrades soft: when the runtime `IMessage` implementation does not
	 * expose `getSymfonyEmail()`, the message is still sent — without the
	 * extra headers — and the omission is logged, never thrown.
	 *
	 * @param IMessage $message The message being built.
	 * @param array<string, string> $headers Header name => value pairs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-header-injection-on-the-instance-mailer-degrades-soft
	 */
	private function applyHeaders(IMessage $message, array $headers): void {
		if (method_exists($message, 'getSymfonyEmail') === false) {
			$this->logger->info(
				'InstanceMailerTransport.applyHeaders: getSymfonyEmail() unavailable — sending without extra headers',
				['headerNames' => array_keys($headers)]
			);
			return;
		}

		try {
			$symfonyEmail = $message->getSymfonyEmail();
			foreach ($headers as $name => $value) {
				$symfonyEmail->getHeaders()->addTextHeader($name, $value);
			}
		} catch (Throwable $e) {
			$this->logger->info(
				'InstanceMailerTransport.applyHeaders: header injection failed — sending without extra headers',
				['exception' => $e->getMessage()]
			);
		}
	}//end applyHeaders()
}//end class
