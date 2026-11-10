<?php

/**
 * Pipelinq EmailFallbackSender.
 *
 * Email fallback dispatcher for the Berichtenbox bridge. When a burger's
 * MijnOverheid mailbox is absent / opted-out, or when a delivered
 * message is unread for 5 working days, the bridge renders the same
 * template and ships it via Nextcloud's IMailer (which is wired to
 * the openconnector SMTP / SendGrid source in production).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/berichtenbox/spec.md#REQ-MAILBOX-003
 * @spec openspec/specs/berichtenbox/spec.md#REQ-FALLBACK-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Consent\ConsentAsk;
use OCP\IAppConfig;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Sends fallback emails for the Berichtenbox bridge.
 *
 * @spec openspec/specs/outbound-messaging/spec.md#REQ-OM-004
 */
class EmailFallbackSender {
	/**
	 * Notice prepended to the body when the message is also expected to
	 * appear in MijnOverheid (REQ-FALLBACK-004).
	 *
	 * @var string
	 */
	public const FALLBACK_NOTICE = 'Dit bericht is ook beschikbaar in uw MijnOverheid Berichtenbox.';

	/**
	 * App config key for the fallback "From" address.
	 *
	 * @var string
	 */
	public const CONFIG_FROM_ADDRESS = 'berichtenbox_fallback_from';

	/**
	 * Constructor.
	 *
	 * @param IMailer $mailer Nextcloud mailer.
	 * @param IAppConfig $appConfig App config.
	 * @param LoggerInterface $logger Logger.
	 * @param IntegriqConsentClient $integriq Asks integriq before the fallback is sent.
	 * @param UnsubscribeMail $unsubscribeMail Puts integriq's link in the body and the headers.
	 */
	public function __construct(
		private readonly IMailer $mailer,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly IntegriqConsentClient $integriq,
		private readonly UnsubscribeMail $unsubscribeMail,
	) {
	}//end __construct()

	/**
	 * Why the last fallback was refused by the opt-out check, or null.
	 *
	 * @var array{code:string,reason:string}|null
	 */
	private ?array $lastRefusal = null;

	/**
	 * Why the last fallback was refused by the opt-out check, or null when it was not.
	 *
	 * @return array{code:string,reason:string}|null The refusal.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-asks-integriq-before-every-non-exempt-message-req-cii-002
	 */
	public function lastRefusal(): ?array {
		return $this->lastRefusal;
	}//end lastRefusal()

	/**
	 * The category of the Berichtenbox message this falls back for.
	 *
	 * @param array<string,mixed> $message The message.
	 *
	 * @return string The category, `service` when it names none.
	 */
	private function categoryOf(array $message): string {
		$category = trim((string)($message['category'] ?? ''));
		if ($category === '') {
			return IntegriqConsentClient::CATEGORY_SERVICE;
		}

		return $category;
	}//end categoryOf()

	/**
	 * Send a fallback email for the given BerichtenboxMessage payload.
	 *
	 * @param array $message The OR object array form of the message
	 *                       (uses subject + body).
	 * @param string $toEmail Recipient address (typically burger.email).
	 * @param bool $appendNotice Whether to prepend the MijnOverheid notice
	 *                           (true for 5-day fallback; false for
	 *                           no-mailbox/opted-out where the burger
	 *                           never had access).
	 *
	 * @return bool True iff IMailer accepted the message.
	 *
	 * @throws RuntimeException If the recipient address is invalid.
	 *
	 * @spec openspec/specs/berichtenbox/spec.md#REQ-FALLBACK-004
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-asks-integriq-before-every-non-exempt-message-req-cii-002
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $appendNotice selects between the
	 *  two documented fallback modes (5-day notice vs no-mailbox); it is not a
	 *  behaviour toggle to be split into two methods.
	 */
	public function send(array $message, string $toEmail, bool $appendNotice = true): bool {
		$decision = $this->consentFor(message: $message, toEmail: $toEmail);
		if ($decision['send'] === false) {
			return false;
		}

		$html = $this->bodyWithNotice(message: $message, appendNotice: $appendNotice);
		$html .= $this->unsubscribeParagraph(unsubscribe: $decision['unsubscribe']);
		try {
			$msg = $this->withBody(msg: $this->addressedMessage(toEmail: $toEmail, message: $message), html: $html);
			$this->unsubscribeMail->applyHeaders(message: $msg, unsubscribe: $decision['unsubscribe']);
			return empty($this->mailer->send($msg)) === true;
		} catch (\Throwable $e) {
			throw $this->dispatchFailure(error: $e);
		}
	}//end send()

	/**
	 * Refuse an empty or invalid recipient address.
	 *
	 * @param string $toEmail Recipient address.
	 *
	 * @return void
	 *
	 * @throws RuntimeException If the recipient address is invalid.
	 */
	private function assertValidRecipient(string $toEmail): void {
		if ($toEmail === '' || $this->mailer->validateMailAddress($toEmail) === false) {
			throw new RuntimeException('Invalid recipient address.');
		}
	}//end assertValidRecipient()

	/**
	 * Check the recipient, then ask integriq about the fallback; a refusal is remembered and logged.
	 *
	 * The Berichtenbox status is left as it is by the caller.
	 *
	 * @param array<string,mixed> $message The message.
	 * @param string              $toEmail Recipient address.
	 *
	 * @return array{send:bool,code:string,reason:string,unsubscribe:array<string,mixed>|null} The decision.
	 *
	 * @throws RuntimeException If the recipient address is invalid.
	 */
	private function consentFor(array $message, string $toEmail): array {
		$this->assertValidRecipient(toEmail: $toEmail);
		$this->lastRefusal = null;
		$ask = new ConsentAsk(channel: 'email', category: $this->categoryOf(message: $message), requiresConsent: false);
		$decision = $this->integriq->decideOne(ask: $ask, recipient: ['address' => $toEmail]);
		if ($decision['send'] === false) {
			$this->lastRefusal = ['code' => $decision['code'], 'reason' => $decision['reason']];
			$this->logger->warning(
				'Berichtenbox fallback email not sent: integriq refused it.',
				['messageId' => (string)($message['uuid'] ?? ''), 'code' => $decision['code'], 'category' => $ask->category]
			);
		}

		return $decision;
	}//end consentFor()

	/**
	 * The message body, after the MijnOverheid notice when asked for.
	 *
	 * @param array<string,mixed> $message      The message.
	 * @param bool                $appendNotice Whether to prepend the notice.
	 *
	 * @return string The HTML body.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Carries send()'s documented fallback mode.
	 */
	private function bodyWithNotice(array $message, bool $appendNotice): string {
		$body = (string)($message['body'] ?? '');
		if ($appendNotice === true) {
			return '<p>' . htmlspecialchars(self::FALLBACK_NOTICE, ENT_XHTML, 'UTF-8') . '</p>' . $body;
		}

		return $body;
	}//end bodyWithNotice()

	/**
	 * Integriq's unsubscribe line as a paragraph, or '' when there is none.
	 *
	 * @param array<string,mixed>|null $unsubscribe Integriq's material.
	 *
	 * @return string The paragraph.
	 */
	private function unsubscribeParagraph(?array $unsubscribe): string {
		$line = $this->unsubscribeMail->bodyLine(unsubscribe: $unsubscribe);
		if ($line === '') {
			return '';
		}

		return '<p>' . htmlspecialchars($line, ENT_XHTML, 'UTF-8') . '</p>';
	}//end unsubscribeParagraph()

	/**
	 * A new message to the recipient with the Berichtenbox message's subject.
	 *
	 * @param string              $toEmail Recipient address.
	 * @param array<string,mixed> $message The message.
	 *
	 * @return IMessage The mail message.
	 */
	private function addressedMessage(string $toEmail, array $message): IMessage {
		$msg = $this->mailer->createMessage();
		$msg->setTo([$toEmail]);
		$msg->setSubject((string)($message['subject'] ?? ''));
		return $msg;
	}//end addressedMessage()

	/**
	 * Set the HTML and plain bodies, and the configured From when it is valid.
	 *
	 * @param IMessage $msg  The message.
	 * @param string   $html The HTML body.
	 *
	 * @return IMessage The same message.
	 */
	private function withBody(IMessage $msg, string $html): IMessage {
		$msg->setHtmlBody($html);
		$msg->setPlainBody(strip_tags($html));
		$from = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_FROM_ADDRESS, '');
		if ($from !== '' && $this->mailer->validateMailAddress($from) === true) {
			$msg->setFrom([$from]);
		}

		return $msg;
	}//end withBody()

	/**
	 * Log a dispatch failure and wrap it.
	 *
	 * @param \Throwable $error What the mailer threw.
	 *
	 * @return RuntimeException The wrapped failure.
	 */
	private function dispatchFailure(\Throwable $error): RuntimeException {
		$this->logger->warning(
			'Berichtenbox fallback email dispatch failed.',
			['exception' => $error->getMessage()]
		);
		return new RuntimeException('Fallback email dispatch failed: ' . $error->getMessage(), 0, $error);
	}//end dispatchFailure()
}//end class
