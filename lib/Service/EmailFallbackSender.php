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
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-mailbox-003
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-fallback-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Sends fallback emails for the Berichtenbox bridge.
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
	 */
	public function __construct(
		private readonly IMailer $mailer,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

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
	 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-fallback-004
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $appendNotice selects between the
	 *  two documented fallback modes (5-day notice vs no-mailbox); it is not a
	 *  behaviour toggle to be split into two methods.
	 */
	public function send(array $message, string $toEmail, bool $appendNotice = true): bool {
		if ($toEmail === '' || $this->mailer->validateMailAddress($toEmail) === false) {
			throw new RuntimeException('Invalid recipient address.');
		}

		$subject = (string)($message['subject'] ?? '');
		$body = (string)($message['body'] ?? '');
		if ($appendNotice === true) {
			$body = '<p>' . htmlspecialchars(self::FALLBACK_NOTICE, ENT_XHTML, 'UTF-8') . '</p>' . $body;
		}

		try {
			$msg = $this->mailer->createMessage();
			$msg->setTo([$toEmail]);
			$msg->setSubject($subject);
			$msg->setHtmlBody($body);
			$msg->setPlainBody(strip_tags($body));

			$from = $this->appConfig->getValueString(
				Application::APP_ID,
				self::CONFIG_FROM_ADDRESS,
				''
			);
			if ($from !== '' && $this->mailer->validateMailAddress($from) === true) {
				$msg->setFrom([$from]);
			}

			$failed = $this->mailer->send($msg);
			return empty($failed) === true;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Berichtenbox fallback email dispatch failed.',
				['exception' => $e->getMessage()]
			);
			throw new RuntimeException('Fallback email dispatch failed: ' . $e->getMessage(), 0, $e);
		}//end try
	}//end send()
}//end class
