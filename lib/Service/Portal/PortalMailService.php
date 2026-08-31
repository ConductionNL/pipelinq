<?php

/**
 * Pipelinq PortalMailService.
 *
 * Centralises the small set of transactional emails the customer portal sends
 * (password reset, email-change verification, account-closure confirmation,
 * data-export ready). Keeping delivery in one place means a token link is built
 * and sent the same way everywhere, and a mail failure is logged rather than
 * surfaced to the portal client (ADR-005: no internal detail leakage).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Portal
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/customer-portal/specs.md#REQ-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/**
 * Sends portal transactional emails.
 *
 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
 *   sessions, tokens, delegation, documents, invoices, orders, exports and
 *   audit are all unspecified
 */
class PortalMailService {
	/**
	 * Constructor.
	 *
	 * @param IMailer $mailer The Nextcloud mailer.
	 * @param IURLGenerator $urlGenerator The URL generator.
	 * @param IL10N $l10n The localisation service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private IMailer $mailer,
		private IURLGenerator $urlGenerator,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Send a tokenised action link (reset / verify / close).
	 *
	 * @param string $recipient The recipient email.
	 * @param string $route The frontend route (e.g. /portal/password-reset).
	 * @param string $token The plaintext token.
	 * @param string $subject The localised subject.
	 * @param string $intro The localised intro line.
	 *
	 * @return bool True when the message was accepted for delivery.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function sendTokenLink(
		string $recipient,
		string $route,
		string $token,
		string $subject,
		string $intro,
	): bool {
		$link = $this->urlGenerator->getAbsoluteURL($route) . '?token=' . rawurlencode($token);
		$body = $intro . "\n\n" . $link . "\n\n" . $this->l10n->t('If you did not request this, you can ignore this email.');

		return $this->send(recipient: $recipient, subject: $subject, body: $body);
	}//end sendTokenLink()

	/**
	 * Send a plain informational message.
	 *
	 * @param string $recipient The recipient email.
	 * @param string $subject The localised subject.
	 * @param string $body The localised plain-text body.
	 *
	 * @return bool True when accepted for delivery.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function send(string $recipient, string $subject, string $body): bool {
		if ($this->mailer->validateMailAddress($recipient) === false) {
			return false;
		}

		try {
			$message = $this->mailer->createMessage();
			$message->setTo([$recipient]);
			$message->setSubject($subject);
			$message->setPlainBody($body);
			$failed = $this->mailer->send($message);
			return empty($failed) === true;
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq portal: mail send failed', ['exception' => $e->getMessage()]);
			return false;
		}
	}//end send()
}//end class
