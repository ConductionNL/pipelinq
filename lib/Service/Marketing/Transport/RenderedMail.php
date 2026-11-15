<?php

/**
 * Pipelinq RenderedMail.
 *
 * The one rendered-mail shape every transport adapter receives. Built once
 * by `MailTransportService` from `BlastService`'s existing per-recipient
 * template rendering, then handed unchanged to whichever adapter the
 * resolved `mailTransport` selects — no adapter re-derives subject/body
 * content, and no adapter sees the recipient's full delivery row.
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

/**
 * RenderedMail: an immutable, transport-agnostic rendered message.
 *
 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-header-injection-on-the-instance-mailer-degrades-soft
 */
final class RenderedMail {
	/**
	 * Sender email address.
	 *
	 * @var string
	 */
	public readonly string $fromEmail;

	/**
	 * Sender display name (may be empty).
	 *
	 * @var string
	 */
	public readonly string $fromName;

	/**
	 * Reply-To address (may be empty, then replies go to `$fromEmail`).
	 *
	 * @var string
	 */
	public readonly string $replyTo;

	/**
	 * Recipient email address (one delivery, one recipient).
	 *
	 * @var string
	 */
	public readonly string $toEmail;

	/**
	 * Rendered subject.
	 *
	 * @var string
	 */
	public readonly string $subject;

	/**
	 * Rendered HTML body.
	 *
	 * @var string
	 */
	public readonly string $html;

	/**
	 * Rendered plain-text body.
	 *
	 * @var string
	 */
	public readonly string $text;

	/**
	 * Constructor.
	 *
	 * @param MailAddressing        $addressing The sender, reply address and recipient.
	 * @param MailContent           $content    The subject and both bodies.
	 * @param array<string, string> $headers    Extra headers to set when the
	 *                                          transport supports it (e.g. RFC 8058
	 *                                          `List-Unsubscribe`). Never required:
	 *                                          every transport degrades soft when it
	 *                                          cannot set them.
	 * @param string                $deliveryId The BlastDelivery UUID or slug this mail was rendered for.
	 */
	public function __construct(
		MailAddressing $addressing,
		MailContent $content,
		public readonly array $headers,
		public readonly string $deliveryId,
	) {
		$this->fromEmail = $addressing->fromEmail;
		$this->fromName  = $addressing->fromName;
		$this->replyTo   = $addressing->replyTo;
		$this->toEmail   = $addressing->toEmail;
		$this->subject   = $content->subject;
		$this->html      = $content->html;
		$this->text      = $content->text;
	}//end __construct()
}//end class
