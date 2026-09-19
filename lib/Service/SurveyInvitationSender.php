<?php

/**
 * Pipelinq SurveyInvitationSender.
 *
 * Hands one survey invitation to its channel. Email goes through Nextcloud's
 * own mailer, so an instance that has configured SMTP once has configured this
 * too. A channel this instance cannot reach returns false rather than
 * throwing, and the caller marks the invitation `failed`.
 *
 * The link carries the per-invitation token, not the survey's: a shared link
 * cannot be told apart from a forwarded one, and a survey whose answers cannot
 * be attributed cannot close a loop with anybody.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-automated-invitation-dispatch-on-interaction-completion
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\IURLGenerator;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Deliver an invitation over its channel.
 *
 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-tokenized-invitation-response-collection
 */
class SurveyInvitationSender {
	/**
	 * Constructor.
	 *
	 * @param IMailer $mailer Nextcloud's mailer.
	 * @param IURLGenerator $urlGenerator Builds the absolute response link.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly IMailer $mailer,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The absolute link an invitation's token opens.
	 *
	 * @param string $token The per-invitation token.
	 *
	 * @return string The url.
	 */
	public function linkFor(string $token): string {
		return $this->urlGenerator->getAbsoluteURL(
			'/index.php/apps/pipelinq/survey/i/' . rawurlencode($token)
		);
	}//end linkFor()

	/**
	 * Send one invitation.
	 *
	 * @param array<string, mixed> $invitation The invitation.
	 * @param string $address The address to deliver to. Defaults to the one
	 *   the invitation recorded when the dispatch decided to send.
	 *
	 * @return bool True when the hand-off succeeded.
	 *
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-automated-invitation-dispatch-on-interaction-completion
	 */
	public function send(array $invitation, string $address = ''): bool {
		$channel = trim((string)($invitation['channel'] ?? 'email'));
		if ($channel !== 'email') {
			// SMS and WhatsApp go through the outbound channel adapters, which
			// are configured per instance and absent on most. Reporting that
			// plainly is better than pretending a message went out.
			$this->logger->info(
				'SurveyInvitationSender: no transport configured for this channel',
				['channel' => $channel]
			);

			return false;
		}

		if ($address === '') {
			$address = (string)($invitation['deliveryAddress'] ?? '');
		}

		$address = trim($address);
		if ($address === '' || $this->mailer->validateMailAddress($address) === false) {
			return false;
		}

		$link = $this->linkFor(token: (string)($invitation['token'] ?? ''));

		try {
			$message = $this->mailer->createMessage();
			$message->setTo([$address]);
			$message->setSubject('How did we do?');
			$message->setHtmlBody(
				'<p>We would like to know how the last contact went.</p>'
				. '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">'
				. 'Answer a few questions</a></p>'
			);
			$message->setPlainBody(
				"We would like to know how the last contact went.\n\n" . $link
			);

			$this->mailer->send($message);

			return true;
		} catch (Throwable $e) {
			$this->logger->error(
				'SurveyInvitationSender: the invitation could not be sent',
				['exception' => $e->getMessage()]
			);

			return false;
		}
	}//end send()
}//end class
