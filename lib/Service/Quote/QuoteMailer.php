<?php

/**
 * Pipelinq QuoteMailer.
 *
 * Mails a quote's PDF to its recipient through Nextcloud's mailer.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Quote
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Quote;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCP\IL10N;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The mail that carries a quote.
 *
 * @spec openspec/changes/products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
 */
class QuoteMailer {

	/**
	 * Constructor.
	 *
	 * @param IMailer         $mailer Nextcloud's mailer.
	 * @param IL10N           $l10n   The app's translations.
	 * @param LoggerInterface $logger Logger for a mail that did not go out.
	 */
	public function __construct(
		private readonly IMailer $mailer,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Mail the quote with its PDF attached.
	 *
	 * @param array{email: string, name: string} $recipient Who the quote goes to.
	 * @param SentQuote                          $quote     The quote's number, validity, total and PDF.
	 *
	 * @return bool True when the mailer accepted the message for every recipient.
	 *
	 * @spec openspec/changes/products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
	 */
	public function send(array $recipient, SentQuote $quote): bool {
		try {
			$message = $this->mailer->createMessage();
			$message->setTo(self::address(recipient: $recipient));
			$message->setSubject($this->l10n->t('Quote %s', [$quote->number]));
			$message->setPlainBody($this->body(quote: $quote));
			$message->attach($this->mailer->createAttachment($quote->content, $quote->filename, 'application/pdf'));

			return $this->mailer->send($message) === [];
		} catch (Throwable $e) {
			$this->logger->error(
				'Pipelinq: a quote was made but its mail did not go out',
				['app' => AppIdentity::APP_ID, 'quote' => $quote->number, 'exception' => $e->getMessage()]
			);
			return false;
		}
	}//end send()

	/**
	 * The plain-text body: the number, the total and how long the quote holds.
	 *
	 * @param SentQuote $quote The quote.
	 *
	 * @return string The body.
	 *
	 * @spec openspec/changes/products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
	 */
	public function body(SentQuote $quote): string {
		return $this->l10n->t(
			'Please find attached quote %1$s, for a total of EUR %2$s including VAT. It is valid until %3$s.',
			[$quote->number, number_format($quote->totalInclVat, 2, '.', ''), $quote->validUntil]
		);
	}//end body()

	/**
	 * The mailer's address form: the email, with the name when there is one.
	 *
	 * @param array{email: string, name: string} $recipient The recipient.
	 *
	 * @return array<int|string, string> The address.
	 */
	private static function address(array $recipient): array {
		if ($recipient['name'] === '') {
			return [$recipient['email']];
		}

		return [$recipient['email'] => $recipient['name']];
	}//end address()
}//end class
