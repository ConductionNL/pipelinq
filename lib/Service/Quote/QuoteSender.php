<?php

/**
 * Pipelinq QuoteSender.
 *
 * Sends a quote from a lead's lines: filinq renders the PDF and files a copy
 * in the sender's Files, pipelinq mails it and logs an outbound contact moment
 * on the lead.
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
 * @spec openspec/changes/archive/2026-11-25-products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Quote;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\CallerObjectReaderInterface;
use OCA\Pipelinq\Kernel\Contracts\FilinqLetterAdapterInterface;
use OCA\Pipelinq\Service\QuoteService;
use OCA\Pipelinq\Service\TicketService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * A quote from a lead's lines, rendered, mailed and logged.
 *
 * @spec openspec/changes/archive/2026-11-25-products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The orchestration touches filinq, the mailer, the ticket store and OpenRegister by design.
 */
class QuoteSender {

	/**
	 * The channel of a quote's contact moment; quote numbers are counted per year on it.
	 *
	 * @var string
	 */
	public const QUOTE_CHANNEL = 'quote';

	/**
	 * At most this many lines go on one quote.
	 *
	 * @var int
	 */
	private const LINE_LIMIT = 200;

	/**
	 * Constructor.
	 *
	 * @param QuoteService                 $quotes        Totals per VAT rate, the number and the recipient.
	 * @param FilinqLetterAdapterInterface $letters       filinq's side: renders the PDF and files a copy.
	 * @param QuoteMailer                  $mailer        Mails the PDF.
	 * @param TicketService                $tickets       Stores the contact moment.
	 * @param CallerObjectReaderInterface  $reader        Reads the client, contact and products as the caller.
	 * @param ObjectServiceInterface       $objectService OpenRegister's object service, for the lead's lines.
	 * @param IAppConfig                   $appConfig     The register and schema ids.
	 * @param IL10N                        $l10n          The app's translations.
	 * @param LoggerInterface              $logger        Logger.
	 */
	public function __construct(
		private readonly QuoteService $quotes,
		private readonly FilinqLetterAdapterInterface $letters,
		private readonly QuoteMailer $mailer,
		private readonly TicketService $tickets,
		private readonly CallerObjectReaderInterface $reader,
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Who a quote for this lead goes to: its contact when that has an email
	 * address, else its client. Null when neither has one.
	 *
	 * @param array<string, mixed> $lead The lead, read as the caller.
	 *
	 * @return array{email: string, name: string, source: string}|null The recipient.
	 *
	 * @spec openspec/changes/archive/2026-11-25-products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
	 */
	public function recipientFor(array $lead): ?array {
		$parties = $this->parties(lead: $lead);
		return $this->quotes->recipient(contact: $parties['contact'], client: $parties['client']);
	}//end recipientFor()

	/**
	 * Render, mail and log a quote from the lead's lines.
	 *
	 * Nothing is rendered, numbered or logged without a recipient. When the
	 * mail fails after the PDF was made the copy stays in Files and no contact
	 * moment is written.
	 *
	 * @param string               $leadId     The lead id.
	 * @param array<string, mixed> $lead       The lead, read as the caller.
	 * @param string               $templateId The filinq template id.
	 * @param string               $validUntil The last day the quote holds, Y-m-d.
	 *
	 * @return array<string, mixed> The number, totals, recipient, the copy in Files and the contact moment id.
	 *
	 * @throws QuoteRefusedException When there is no recipient, filinq cannot render, or the mail fails.
	 *
	 * @spec openspec/changes/archive/2026-11-25-products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
	 * @spec openspec/changes/archive/2026-11-25-products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-sent-quote-does-not-change-afterwards-req-qslice-004
	 */
	public function send(string $leadId, array $lead, string $templateId, string $validUntil): array {
		$parties   = $this->parties(lead: $lead);
		$recipient = $this->quotes->recipient(contact: $parties['contact'], client: $parties['client']);
		if ($recipient === null) {
			throw new QuoteRefusedException(
				message: $this->l10n->t('Add an email address to the contact or the client of this lead, then send the quote.'),
				status: Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$lines  = $this->linesOf(leadId: $leadId);
		$totals = $this->quotes->vatTotals(lines: $lines);
		$number = $this->quotes->nextNumber();
		$quote  = $this->render(
			templateId: $templateId,
			dataRefs: $this->dataRefs(leadId: $leadId, parties: $parties),
			adHocData: self::templateData(number: $number, validUntil: $validUntil, totals: $totals, lines: $lines, recipient: $recipient),
			filename: self::filename(number: $number, title: (string) ($lead['title'] ?? ''))
		);

		if ($this->mailer->send(recipient: $recipient, quote: $quote) === false) {
			throw new QuoteRefusedException(
				message: $this->l10n->t('The quote was made and filed, but the mail could not be sent.'),
				status: Http::STATUS_BAD_GATEWAY,
				details: ['number' => $quote->number, 'fileId' => $quote->fileId, 'path' => $quote->path]
			);
		}

		$payload = $this->contactMomentPayload(
			leadId: $leadId,
			parties: $parties,
			quote: $quote,
			recipient: $recipient,
			occurredAt: new DateTimeImmutable()
		);

		return [
			'number'          => $quote->number,
			'validUntil'      => $quote->validUntil,
			'totals'          => $totals,
			'recipient'       => $recipient,
			'fileId'          => $quote->fileId,
			'path'            => $quote->path,
			'contactMomentId' => $this->log(payload: $payload, number: $quote->number),
		];
	}//end send()

	/**
	 * The outbound contact moment of a sent quote: on the lead, its client and
	 * contact, with the number, total and the copy in Files.
	 *
	 * @param string                                             $leadId     The lead id.
	 * @param array{contactId: string|null, clientId: string|null} $parties    The lead's readable contact and client ids.
	 * @param SentQuote                                          $quote      The sent quote.
	 * @param array{email: string, name: string, source: string} $recipient  Who it went to.
	 * @param DateTimeImmutable                                  $occurredAt When it was sent.
	 *
	 * @return array<string, string> The contact moment.
	 *
	 * @spec openspec/changes/archive/2026-11-25-products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
	 */
	public function contactMomentPayload(string $leadId, array $parties, SentQuote $quote, array $recipient, DateTimeImmutable $occurredAt): array {
		$description = $this->l10n->t(
			'Quote %1$s sent to %2$s, total EUR %3$s including VAT, valid until %4$s.',
			[$quote->number, $recipient['email'], number_format($quote->totalInclVat, 2, '.', ''), $quote->validUntil]
		);
		if ($quote->path !== null && $quote->path !== '') {
			$description .= ' '.$this->l10n->t('A copy is filed at %s.', [$quote->path]);
		}

		$payload = [
			'lead'        => $leadId,
			'title'       => mb_substr($this->l10n->t('Quote %s', [$quote->number]), 0, 255),
			'direction'   => 'outbound',
			'channel'     => self::QUOTE_CHANNEL,
			'outcome'     => 'handled',
			'description' => $description,
			'occurredAt'  => $occurredAt->format('c'),
		];
		if ($parties['clientId'] !== null) {
			$payload['client'] = $parties['clientId'];
		}

		if ($parties['contactId'] !== null) {
			$payload['contact'] = $parties['contactId'];
		}

		return $payload;
	}//end contactMomentPayload()

	/**
	 * What the template reads beside the records: the quote, its totals and the lines it was made from.
	 *
	 * @param string                                             $number     The quote number.
	 * @param string                                             $validUntil The last day it holds.
	 * @param array<string, mixed>                               $totals     The totals per VAT rate.
	 * @param list<array<string, mixed>>                         $lines      The lines.
	 * @param array{email: string, name: string, source: string} $recipient  Who it goes to.
	 *
	 * @return array<string, mixed> The template data.
	 *
	 * @spec openspec/changes/archive/2026-11-25-products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-the-quote-shows-vat-per-rate-req-qslice-003
	 */
	public static function templateData(string $number, string $validUntil, array $totals, array $lines, array $recipient): array {
		return [
			'quote'  => [
				'number'     => $number,
				'date'       => (new DateTimeImmutable())->format('Y-m-d'),
				'validUntil' => $validUntil,
				'recipient'  => $recipient,
			],
			'totals' => $totals,
			'lines'  => $lines,
		];
	}//end templateData()

	/**
	 * The PDF's file name without extension: the number, then the lead's title, without path characters.
	 *
	 * @param string $number The quote number.
	 * @param string $title  The lead's title.
	 *
	 * @return string The file name.
	 *
	 * @spec openspec/changes/archive/2026-11-25-products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
	 */
	public static function filename(string $number, string $title): string {
		$title = trim((string) preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', ' ', $title));
		$title = trim((string) preg_replace('/\s+/u', ' ', $title), ' .');
		if ($title === '') {
			return $number;
		}

		return mb_substr($number.' '.$title, 0, 120);
	}//end filename()

	/**
	 * Have filinq render the quote and file a copy in the sender's Files.
	 *
	 * @param string                                                  $templateId The template.
	 * @param list<array{register: string, schema: string, id: string}> $dataRefs   The records it reads.
	 * @param array<string, mixed>                                    $adHocData  The quote, totals and lines.
	 * @param string                                                  $filename   The file name, without extension.
	 *
	 * @return SentQuote The rendered quote.
	 *
	 * @throws QuoteRefusedException When filinq cannot render it.
	 */
	private function render(string $templateId, array $dataRefs, array $adHocData, string $filename): SentQuote {
		try {
			$letter = $this->letters->render(
				templateId: $templateId,
				dataRefs: $dataRefs,
				filename: $filename,
				outputMode: 'both',
				adHocData: $adHocData
			);
		} catch (RuntimeException $e) {
			throw new QuoteRefusedException(
				message: $this->l10n->t('filinq could not make the quote.'),
				status: Http::STATUS_BAD_GATEWAY,
				details: ['reason' => $e->getMessage()],
				previous: $e
			);
		}

		return new SentQuote(
			number: (string) $adHocData['quote']['number'],
			validUntil: (string) $adHocData['quote']['validUntil'],
			totalInclVat: (float) $adHocData['totals']['totalInclVat'],
			content: $letter['content'],
			filename: $filename.'.pdf',
			fileId: $letter['fileId'],
			path: $letter['path']
		);
	}//end render()

	/**
	 * The lead's contact and client, each read as the caller; one that cannot be read is null.
	 *
	 * @param array<string, mixed> $lead The lead.
	 *
	 * @return array{contactId: string|null, clientId: string|null, contact: array<string, mixed>|null, client: array<string, mixed>|null}
	 */
	private function parties(array $lead): array {
		$contactId = self::idOf(value: ($lead['contact'] ?? null));
		$clientId  = self::idOf(value: ($lead['client'] ?? null));
		$contact   = null;
		$client    = null;
		if ($contactId !== null) {
			$contact = $this->reader->read(id: $contactId, schemaKey: 'contact_schema');
		}

		if ($clientId !== null) {
			$client = $this->reader->read(id: $clientId, schemaKey: 'client_schema');
		}

		if ($contact === null) {
			$contactId = null;
		}

		if ($client === null) {
			$clientId = null;
		}

		return [
			'contactId' => $contactId,
			'clientId'  => $clientId,
			'contact'   => $contact,
			'client'    => $client,
		];
	}//end parties()

	/**
	 * filinq's references to the lead, and its client and contact when readable.
	 *
	 * @param string                                                  $leadId  The lead id.
	 * @param array{contactId: string|null, clientId: string|null}    $parties The lead's readable contact and client.
	 *
	 * @return list<array{register: string, schema: string, id: string}> The references.
	 */
	private function dataRefs(string $leadId, array $parties): array {
		$register = $this->reader->register();
		$refs     = [['register' => $register, 'schema' => 'lead', 'id' => $leadId]];
		if ($parties['clientId'] !== null) {
			$refs[] = ['register' => $register, 'schema' => 'client', 'id' => $parties['clientId']];
		}

		if ($parties['contactId'] !== null) {
			$refs[] = ['register' => $register, 'schema' => 'contact', 'id' => $parties['contactId']];
		}

		return $refs;
	}//end dataRefs()

	/**
	 * The lead's lines as the caller may read them, each with its product when readable.
	 *
	 * @param string $leadId The lead id.
	 *
	 * @return list<array<string, mixed>> The lines.
	 */
	private function linesOf(string $leadId): array {
		$filters = [
			'register' => $this->reader->register(),
			'schema'   => $this->appConfig->getValueString(AppIdentity::APP_ID, 'leadProduct_schema', ''),
			'lead'     => $leadId,
		];

		$rows = (array) $this->objectService->findAll(config: ['filters' => $filters, 'limit' => self::LINE_LIMIT]);

		$lines = [];
		foreach ($rows as $row) {
			$lines[] = $this->withProduct(line: self::rowData(row: $row));
		}

		return $lines;
	}//end linesOf()

	/**
	 * A line with its product's data in place of the product id, so its VAT class is known.
	 *
	 * @param array<string, mixed> $line The line.
	 *
	 * @return array<string, mixed> The line.
	 */
	private function withProduct(array $line): array {
		$productId = self::idOf(value: ($line['product'] ?? null));
		if ($productId === null) {
			return $line;
		}

		$product = $this->reader->read(id: $productId, schemaKey: 'product_schema');
		if ($product !== null) {
			$line['product'] = $product;
		}

		return $line;
	}//end withProduct()

	/**
	 * Store the contact moment; a failure is logged, the quote is already sent.
	 *
	 * @param array<string, string> $payload The contact moment.
	 * @param string                $number  The quote number, for the log.
	 *
	 * @return string|null The contact moment id.
	 */
	private function log(array $payload, string $number): ?string {
		try {
			$saved = $this->tickets->save(ticketType: TicketService::TYPE_CONTACTMOMENT, payload: $payload);
		} catch (Throwable $e) {
			$this->logger->error(
				'Pipelinq: the quote was sent but its contact moment was not saved',
				['app' => AppIdentity::APP_ID, 'quote' => $number, 'exception' => $e->getMessage()]
			);
			return null;
		}

		$data = self::rowData(row: $saved);
		$id   = (string) ($data['id'] ?? '');
		if ($id === '' && method_exists($saved, 'getUuid') === true) {
			$id = (string) $saved->getUuid();
		}

		if ($id === '') {
			return null;
		}

		return $id;
	}//end log()

	/**
	 * An id from a reference that is a uuid string or an object with an id.
	 *
	 * @param mixed $value The reference.
	 *
	 * @return string|null The id, or null when there is none.
	 */
	private static function idOf(mixed $value): ?string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? null);
		}

		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end idOf()

	/**
	 * The data of an OpenRegister object, whether it came back as an array or an entity.
	 *
	 * @param mixed $row The object.
	 *
	 * @return array<string, mixed> Its data.
	 */
	private static function rowData(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'getObject') === true) {
			return (array) $row->getObject();
		}

		return [];
	}//end rowData()
}//end class
