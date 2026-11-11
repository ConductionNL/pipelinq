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

use OCA\Pipelinq\Service\UnsubscribeMail;
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
	 * RFC 2369 header name.
	 */
	public const HEADER_UNSUBSCRIBE = 'List-Unsubscribe';

	/**
	 * RFC 8058 header name.
	 */
	public const HEADER_UNSUBSCRIBE_POST = 'List-Unsubscribe-Post';

	/**
	 * RFC 8058 one-click value.
	 */
	public const ONE_CLICK = 'List-Unsubscribe=One-Click';

	/**
	 * Constructor.
	 *
	 * @param IMailer $mailer Nextcloud's own mailer service.
	 * @param LoggerInterface $logger The logger.
	 * @param UnsubscribeMail|null $unsubscribeMail Sets List-Unsubscribe through OpenRegister's helper.
	 */
	public function __construct(
		private readonly IMailer $mailer,
		private readonly LoggerInterface $logger,
		private readonly ?UnsubscribeMail $unsubscribeMail = null,
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
		$message = $this->newMessage();
		if ($message === null) {
			return new SendResult(accepted: false, error: 'instance-mailer-unavailable');
		}

		$this->applyEnvelope(message: $message, mail: $mail);
		if ($mail->headers !== []) {
			$this->applyHeaders(message: $message, headers: $mail->headers);
		}

		return $this->deliver(message: $message, mail: $mail);
	}//end send()

	/**
	 * A new message from the mailer; null (logged) when it cannot make one.
	 *
	 * @return IMessage|null
	 */
	private function newMessage(): ?IMessage {
		try {
			return $this->mailer->createMessage();
		} catch (Throwable $e) {
			$this->logger->warning(
				'InstanceMailerTransport.send: createMessage failed',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end newMessage()

	/**
	 * Hand the built message to the mailer and judge its answer.
	 *
	 * @param IMessage     $message The built message.
	 * @param RenderedMail $mail    The rendered mail (for its delivery id).
	 *
	 * @return SendResult The outcome.
	 */
	private function deliver(IMessage $message, RenderedMail $mail): SendResult {
		try {
			$failedRecipients = $this->mailer->send($message);
		} catch (Throwable $e) {
			$this->logger->warning(
				'InstanceMailerTransport.send: send failed',
				['deliveryId' => $mail->deliveryId, 'exception' => $e->getMessage()]
			);
			return new SendResult(accepted: false, error: 'instance-mailer-send-failed');
		}

		return $this->verdictOn(failedRecipients: $failedRecipients, deliveryId: $mail->deliveryId);
	}//end deliver()

	/**
	 * Accepted when the mailer rejected no recipient; otherwise logged and refused.
	 *
	 * @param array<mixed> $failedRecipients The recipients the mailer rejected.
	 * @param string       $deliveryId       The delivery id, for the log.
	 *
	 * @return SendResult The outcome.
	 */
	private function verdictOn(array $failedRecipients, string $deliveryId): SendResult {
		if ($failedRecipients === []) {
			return new SendResult(accepted: true);
		}

		$this->logger->warning(
			'InstanceMailerTransport.send: provider rejected recipient(s)',
			['deliveryId' => $deliveryId, 'failedRecipients' => $failedRecipients]
		);
		return new SendResult(accepted: false, error: 'recipient-rejected');
	}//end verdictOn()

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
		$this->applyBodies(message: $message, mail: $mail);
	}//end applyEnvelope()

	/**
	 * Set the HTML and the plain body, each only when the mail has one.
	 *
	 * @param IMessage     $message The message being built.
	 * @param RenderedMail $mail    The rendered mail.
	 *
	 * @return void
	 */
	private function applyBodies(IMessage $message, RenderedMail $mail): void {
		if ($mail->html !== '') {
			$message->setHtmlBody(body: $mail->html);
		}

		if ($mail->text !== '') {
			$message->setPlainBody(body: $mail->text);
		}
	}//end applyBodies()

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
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-every-non-exempt-pipelinq-mail-carries-an-unsubscribe-link-req-cii-004
	 */
	private function applyHeaders(IMessage $message, array $headers): void {
		// The unsubscribe pair goes through OpenRegister's shared helper
		// (opt-out-before-send decision 6); pipelinq keeps no copy of it.
		$unsubscribe = trim((string)($headers[self::HEADER_UNSUBSCRIBE] ?? ''), " <>\t");
		unset($headers[self::HEADER_UNSUBSCRIBE], $headers[self::HEADER_UNSUBSCRIBE_POST]);
		if ($unsubscribe !== '') {
			$this->applyUnsubscribe(message: $message, url: $unsubscribe);
		}

		if ($headers === []) {
			return;
		}

		$this->injectHeaders(message: $message, headers: $headers);
	}//end applyHeaders()

	/**
	 * Set the List-Unsubscribe pair through the shared helper; logged when
	 * it is not set (no helper, or the helper could not), the body link remains.
	 *
	 * @param IMessage $message The message being built.
	 * @param string   $url     The bare one-click URL.
	 *
	 * @return void
	 */
	private function applyUnsubscribe(IMessage $message, string $url): void {
		$set = false;
		if ($this->unsubscribeMail !== null) {
			$set = $this->unsubscribeMail->applyHeaders(message: $message, unsubscribe: ['oneClickUrl' => $url]);
		}

		if ($set === false) {
			$this->logger->info('InstanceMailerTransport.applyHeaders: List-Unsubscribe not set; the body link remains');
		}
	}//end applyUnsubscribe()

	/**
	 * Add the other headers when the message exposes `getSymfonyEmail()`;
	 * otherwise logged and sent without them.
	 *
	 * @param IMessage              $message The message being built.
	 * @param array<string, string> $headers Header name => value pairs.
	 *
	 * @return void
	 */
	private function injectHeaders(IMessage $message, array $headers): void {
		if (method_exists($message, 'getSymfonyEmail') === false) {
			$this->logger->info(
				'InstanceMailerTransport.applyHeaders: getSymfonyEmail() unavailable — sending without extra headers',
				['headerNames' => array_keys($headers)]
			);
			return;
		}

		$this->addTextHeaders(symfonyEmail: static fn () => $message->getSymfonyEmail(), headers: $headers);
	}//end injectHeaders()

	/**
	 * Add each header as a text header; a failure is logged and the mail
	 * still goes.
	 *
	 * @param callable              $symfonyEmail Reads the message's Symfony email.
	 * @param array<string, string> $headers      Header name => value pairs.
	 *
	 * @return void
	 */
	private function addTextHeaders(callable $symfonyEmail, array $headers): void {
		try {
			$email = $symfonyEmail();
			foreach ($headers as $name => $value) {
				$email->getHeaders()->addTextHeader($name, $value);
			}
		} catch (Throwable $e) {
			$this->logger->info(
				'InstanceMailerTransport.applyHeaders: header injection failed — sending without extra headers',
				['exception' => $e->getMessage()]
			);
		}
	}//end addTextHeaders()
}//end class
