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
 * @spec openspec/changes/archive/2026-06-14-customer-portal/specs.md#REQ-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use OCA\Pipelinq\Kernel\AppIdentity;
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
		return $this->send(recipient: $recipient, subject: $subject, body: $this->tokenLinkBody(intro: $intro, link: [$route, $token]));
	}//end sendTokenLink()

	/**
	 * Send a mail that carries a signed download link to a portal document.
	 *
	 * The link is the absolute address of the portal's document download
	 * route; the signed token is all it needs, so it opens from the mail
	 * without a portal session.
	 *
	 * @param string $recipient The recipient email.
	 * @param string $subject   The localised subject.
	 * @param string $intro     The localised intro line.
	 * @param string $token     The signed download token.
	 *
	 * @return bool True when the message was accepted for delivery.
	 *
	 * @spec openspec/changes/specify-capability-rows/specs/customer-portal/spec.md#requirement-a-client-downloads-everything-the-portal-holds-about-them
	 */
	public function sendDownloadLink(string $recipient, string $subject, string $intro, string $token): bool {
		$url = $this->urlGenerator->linkToRouteAbsolute(
			AppIdentity::APP_ID . '.portalDocument.download',
			['token' => $token]
		);

		return $this->send(recipient: $recipient, subject: $subject, body: $intro . "\n\n" . $url);
	}//end sendDownloadLink()

	/**
	 * The body of a tokenised action link mail: the intro, the link and the ignore line.
	 *
	 * @param string                      $intro The localised intro line.
	 * @param array{0: string, 1: string} $link  The frontend route and the plaintext token.
	 *
	 * @return string The plain-text body.
	 */
	private function tokenLinkBody(string $intro, array $link): string {
		$url = $this->urlGenerator->getAbsoluteURL($link[0]) . '?token=' . rawurlencode($link[1]);

		return $intro . "\n\n" . $url . "\n\n" . $this->l10n->t('If you did not request this, you can ignore this email.');
	}//end tokenLinkBody()

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

		return $this->delivered(recipient: $recipient, content: ['subject' => $subject, 'body' => $body]);
	}//end send()

	/**
	 * Hand a plain-text mail to the mailer; a failure is logged and answers false.
	 *
	 * @param string                                $recipient The valid recipient email.
	 * @param array{subject: string, body: string} $content   The localised subject and plain-text body.
	 *
	 * @return bool True when accepted for delivery.
	 */
	private function delivered(string $recipient, array $content): bool {
		try {
			$message = $this->mailer->createMessage();
			$message->setTo([$recipient]);
			$message->setSubject($content['subject']);
			$message->setPlainBody($content['body']);
			$failed = $this->mailer->send($message);
			return empty($failed) === true;
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq portal: mail send failed', ['exception' => $e->getMessage()]);
			return false;
		}
	}//end delivered()
}//end class
