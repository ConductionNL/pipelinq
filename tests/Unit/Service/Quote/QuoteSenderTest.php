<?php

/**
 * Unit tests for QuoteSender.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Quote
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/archive/2026-11-25-products-quote-from-lead/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Quote;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Service\CallerObjectReader;
use OCA\Pipelinq\Service\Letter\FilinqLetterAdapter;
use OCA\Pipelinq\Service\LineTotals\LineTotalMath;
use OCA\Pipelinq\Service\ProductCatalogService;
use OCA\Pipelinq\Service\Quote\QuoteMailer;
use OCA\Pipelinq\Service\Quote\QuoteRefusedException;
use OCA\Pipelinq\Service\Quote\QuoteSender;
use OCA\Pipelinq\Service\Quote\SentQuote;
use OCA\Pipelinq\Service\QuoteNumberSequence;
use OCA\Pipelinq\Service\QuoteService;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Render, mail and log; refuse without a recipient; keep the file when the mail fails.
 *
 * @spec openspec/changes/archive/2026-11-25-products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
 * @spec openspec/changes/archive/2026-11-25-products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-the-quote-shows-vat-per-rate-req-qslice-003
 */
class QuoteSenderTest extends TestCase {

	private const LEAD = '0e1f2a3b-4c5d-4e6f-8a9b-0c1d2e3f4a5b';

	private const CLIENT = '6f1c1d2e-3b4a-4c5d-8e9f-0a1b2c3d4e5f';

	private const CONTACT = '1b2c3d4e-5f6a-4b7c-8d9e-0f1a2b3c4d5e';

	private const ADVICE = 'a1a1a1a1-0000-4000-8000-000000000001';

	private const BOOK = 'b2b2b2b2-0000-4000-8000-000000000002';

	/**
	 * The objects the caller may read, by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $readable = [];

	/**
	 * The lead's lines.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $lines = [];

	/**
	 * The filters the lines were asked for with.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $lineQueries = [];

	/**
	 * The adapter double.
	 *
	 * @var FilinqLetterAdapter&MockObject
	 */
	private FilinqLetterAdapter $letters;

	/**
	 * The mailer double.
	 *
	 * @var QuoteMailer&MockObject
	 */
	private QuoteMailer $mailer;

	/**
	 * The ticket store double.
	 *
	 * @var TicketService&MockObject
	 */
	private TicketService $tickets;

	/**
	 * The quote number sequence double.
	 *
	 * @var QuoteNumberSequence&MockObject
	 */
	private QuoteNumberSequence $numbers;

	/**
	 * A lead with a contact and a client, two lines at 21 and 9 percent.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->readable = [
			self::CLIENT  => ['name' => 'Bakkerij De Graaf', 'email' => 'info@degraaf.example'],
			self::CONTACT => ['firstName' => 'Kees', 'lastName' => 'Bakker', 'email' => 'kees@degraaf.example'],
			self::ADVICE  => ['name' => 'Advies', 'vatClass' => 'high'],
			self::BOOK    => ['name' => 'Handboek', 'vatClass' => 'low'],
		];
		$this->lines    = [
			['name' => 'Advies', 'product' => self::ADVICE, 'quantity' => 10, 'unitPrice' => 100, 'total' => 1000],
			['name' => 'Handboek', 'product' => self::BOOK, 'quantity' => 20, 'unitPrice' => 25, 'total' => 500],
		];
		$this->letters  = $this->createMock(originalClassName: FilinqLetterAdapter::class);
		$this->mailer   = $this->createMock(originalClassName: QuoteMailer::class);
		$this->tickets  = $this->createMock(originalClassName: TicketService::class);
		$this->numbers  = $this->createMock(originalClassName: QuoteNumberSequence::class);
	}//end setUp()

	/**
	 * The sender over a real QuoteService, ProductCatalogService and CallerObjectReader.
	 *
	 * @return QuoteSender
	 */
	private function sender(): QuoteSender {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'register' => '7',
				'client_schema' => '11',
				'contact_schema' => '12',
				'product_schema' => '15',
				'leadProduct_schema' => '16',
				default => $default,
			}
		);

		$readable      = $this->readable;
		$objectService = $this->createMock(originalClassName: \OCA\OpenRegister\Service\ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			static function (int|string $id) use ($readable): ?ObjectEntity {
				if (isset($readable[$id]) === false) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setUuid((string) $id);
				$entity->setObject($readable[$id]);
				return $entity;
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$this->lineQueries[] = $config;
				return array_map(
					static function (array $line): ObjectEntity {
						$entity = new ObjectEntity();
						$entity->setObject($line);
						return $entity;
					},
					$this->lines
				);
			}
		);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);

		$catalog = new ProductCatalogService(
			appConfig: $config,
			logger: $logger,
			objectService: $this->createMock(originalClassName: ObjectServiceInterface::class)
		);
		$quotes  = new QuoteService(catalog: $catalog, numbers: $this->numbers, math: new LineTotalMath());

		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		return new QuoteSender(
			quotes: $quotes,
			letters: $this->letters,
			mailer: $this->mailer,
			tickets: $this->tickets,
			reader: new CallerObjectReader(container: $container, appConfig: $config, logger: $logger),
			objectService: $objectService,
			appConfig: $config,
			l10n: $l10n,
			logger: $logger
		);
	}//end sender()

	/**
	 * The lead as the controller hands it over.
	 *
	 * @return array<string, mixed>
	 */
	private static function lead(): array {
		return ['id' => self::LEAD, 'title' => 'Nieuw kantoor', 'client' => self::CLIENT, 'contact' => self::CONTACT, 'assignee' => 'sanne'];
	}//end lead()

	/**
	 * filinq renders and files the PDF.
	 *
	 * @return void
	 */
	private function filinqRenders(): void {
		$this->letters->method('render')->willReturn(
			['content' => '%PDF-1.7 quote', 'fileId' => 4711, 'path' => '/Pipelinq/OFF-2026-0042 Nieuw kantoor.pdf', 'warnings' => []]
		);
	}//end filinqRenders()

	/**
	 * The contact receives the PDF; filinq gets the records, the totals per
	 * rate and the lines; the contact moment carries the number and the file.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-25-products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
	 */
	public function testSendsToTheContactAndLogsTheQuote(): void {
		$this->numbers->expects($this->once())->method('next')->willReturn('OFF-2026-0042');

		$rendered = null;
		$this->letters->expects($this->once())->method('render')->willReturnCallback(
			static function (
				string $templateId,
				array $dataRefs,
				string $filename,
				string $outputMode,
				array $listRefs,
				array $adHocData
			) use (&$rendered): array {
				$rendered = compact('templateId', 'dataRefs', 'filename', 'outputMode', 'adHocData');
				return ['content' => '%PDF-1.7 quote', 'fileId' => 4711, 'path' => '/Pipelinq/OFF-2026-0042 Nieuw kantoor.pdf', 'warnings' => []];
			}
		);

		$mailed = null;
		$this->mailer->expects($this->once())->method('send')->willReturnCallback(
			static function (array $recipient, SentQuote $quote) use (&$mailed): bool {
				$mailed = [$recipient, $quote];
				return true;
			}
		);

		$saved = null;
		$this->tickets->expects($this->once())->method('save')->willReturnCallback(
			static function (string $ticketType, array $payload) use (&$saved): ObjectEntity {
				$saved  = [$ticketType, $payload];
				$entity = new ObjectEntity();
				$entity->setObject(['id' => 'cm-1'] + $payload);
				return $entity;
			}
		);

		$result = $this->sender()->send(leadId: self::LEAD, lead: self::lead(), templateId: 'tpl-q', validUntil: '2026-12-17');

		$this->assertSame(expected: 'tpl-q', actual: $rendered['templateId']);
		$this->assertSame(expected: 'both', actual: $rendered['outputMode']);
		$this->assertSame(expected: 'OFF-2026-0042 Nieuw kantoor', actual: $rendered['filename']);
		$this->assertSame(
			expected: [
				['register' => '7', 'schema' => 'lead', 'id' => self::LEAD],
				['register' => '7', 'schema' => 'client', 'id' => self::CLIENT],
				['register' => '7', 'schema' => 'contact', 'id' => self::CONTACT],
			],
			actual: $rendered['dataRefs']
		);
		$this->assertSame(
			expected: [
				'rates'        => [
					['rate' => 21, 'net' => 1000.0, 'vat' => 210.0, 'gross' => 1210.0],
					['rate' => 9, 'net' => 500.0, 'vat' => 45.0, 'gross' => 545.0],
				],
				'totalExclVat' => 1500.0,
				'totalVat'     => 255.0,
				'totalInclVat' => 1755.0,
			],
			actual: $rendered['adHocData']['totals']
		);
		$this->assertSame(expected: 'OFF-2026-0042', actual: $rendered['adHocData']['quote']['number']);
		$this->assertSame(expected: '2026-12-17', actual: $rendered['adHocData']['quote']['validUntil']);
		$this->assertSame(expected: 'Advies', actual: $rendered['adHocData']['lines'][0]['product']['name']);
		$this->assertSame(
			expected: ['filters' => ['register' => '7', 'schema' => '16', 'lead' => self::LEAD], 'limit' => 200],
			actual: $this->lineQueries[0]
		);

		$this->assertSame(expected: ['email' => 'kees@degraaf.example', 'name' => 'Kees Bakker', 'source' => 'contact'], actual: $mailed[0]);
		$this->assertSame(expected: 'OFF-2026-0042 Nieuw kantoor.pdf', actual: $mailed[1]->filename);
		$this->assertSame(expected: '%PDF-1.7 quote', actual: $mailed[1]->content);
		$this->assertSame(expected: 1755.0, actual: $mailed[1]->totalInclVat);

		$this->assertSame(expected: TicketService::TYPE_CONTACTMOMENT, actual: $saved[0]);
		$payload = $saved[1];
		unset($payload['occurredAt']);
		$this->assertSame(
			expected: [
				'lead'        => self::LEAD,
				'title'       => 'Quote OFF-2026-0042',
				'direction'   => 'outbound',
				'channel'     => 'quote',
				'outcome'     => 'handled',
				'description' => 'Quote OFF-2026-0042 sent to kees@degraaf.example, total EUR 1755.00 including VAT, valid until 2026-12-17.'
					.' A copy is filed at /Pipelinq/OFF-2026-0042 Nieuw kantoor.pdf.',
				'client'      => self::CLIENT,
				'contact'     => self::CONTACT,
			],
			actual: $payload
		);

		$this->assertSame(expected: 'OFF-2026-0042', actual: $result['number']);
		$this->assertSame(expected: 4711, actual: $result['fileId']);
		$this->assertSame(expected: 'cm-1', actual: $result['contactMomentId']);
		$this->assertSame(expected: 'contact', actual: $result['recipient']['source']);
	}//end testSendsToTheContactAndLogsTheQuote()

	/**
	 * A contact without an email address leaves the client as the recipient.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-25-products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
	 */
	public function testWithoutAContactEmailTheClientReceivesIt(): void {
		$this->readable[self::CONTACT]['email'] = '';

		$this->assertSame(
			expected: ['email' => 'info@degraaf.example', 'name' => 'Bakkerij De Graaf', 'source' => 'client'],
			actual: $this->sender()->recipientFor(lead: self::lead())
		);
	}//end testWithoutAContactEmailTheClientReceivesIt()

	/**
	 * A contact the caller may not read is not named on the quote or its contact moment.
	 *
	 * @return void
	 */
	public function testAnUnreadableContactIsLeftOut(): void {
		unset($this->readable[self::CONTACT]);
		$this->numbers->method('next')->willReturn('OFF-2026-0042');
		$this->filinqRenders();
		$this->mailer->method('send')->willReturn(true);

		$saved = null;
		$this->tickets->method('save')->willReturnCallback(
			static function (string $ticketType, array $payload) use (&$saved): ObjectEntity {
				$saved = $payload;
				return new ObjectEntity();
			}
		);

		$result = $this->sender()->send(leadId: self::LEAD, lead: self::lead(), templateId: 'tpl-q', validUntil: '2026-12-17');

		$this->assertArrayNotHasKey(key: 'contact', array: $saved);
		$this->assertSame(expected: self::CLIENT, actual: $saved['client']);
		$this->assertSame(expected: 'client', actual: $result['recipient']['source']);
	}//end testAnUnreadableContactIsLeftOut()

	/**
	 * Without any email address nothing is numbered, rendered, sent or logged.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-25-products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
	 */
	public function testWithoutARecipientNothingIsSentOrLogged(): void {
		$this->readable[self::CONTACT]['email'] = '';
		$this->readable[self::CLIENT]['email']  = 'not an address';
		$this->numbers->expects($this->never())->method('next');
		$this->letters->expects($this->never())->method('render');
		$this->mailer->expects($this->never())->method('send');
		$this->tickets->expects($this->never())->method('save');

		try {
			$this->sender()->send(leadId: self::LEAD, lead: self::lead(), templateId: 'tpl-q', validUntil: '2026-12-17');
			$this->fail(message: 'A quote without a recipient must be refused.');
		} catch (QuoteRefusedException $e) {
			$this->assertSame(expected: 422, actual: $e->getStatus());
			$this->assertSame(expected: 'Add an email address to the contact or the client of this lead, then send the quote.', actual: $e->getMessage());
		}
	}//end testWithoutARecipientNothingIsSentOrLogged()

	/**
	 * A failed mail keeps the filed PDF, says where it is, and logs no contact moment.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-25-products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
	 */
	public function testAFailedMailLogsNothingAndNamesTheFile(): void {
		$this->numbers->method('next')->willReturn('OFF-2026-0042');
		$this->filinqRenders();
		$this->mailer->method('send')->willReturn(false);
		$this->tickets->expects($this->never())->method('save');

		try {
			$this->sender()->send(leadId: self::LEAD, lead: self::lead(), templateId: 'tpl-q', validUntil: '2026-12-17');
			$this->fail(message: 'A quote whose mail failed must be refused.');
		} catch (QuoteRefusedException $e) {
			$this->assertSame(expected: 502, actual: $e->getStatus());
			$this->assertSame(
				expected: ['number' => 'OFF-2026-0042', 'fileId' => 4711, 'path' => '/Pipelinq/OFF-2026-0042 Nieuw kantoor.pdf'],
				actual: $e->getDetails()
			);
		}
	}//end testAFailedMailLogsNothingAndNamesTheFile()

	/**
	 * A render filinq refuses is a 502 and nothing is mailed.
	 *
	 * @return void
	 */
	public function testARefusedRenderMailsNothing(): void {
		$this->numbers->method('next')->willReturn('OFF-2026-0042');
		$this->letters->method('render')->willThrowException(new RuntimeException('filinq_render_failed: template broken'));
		$this->mailer->expects($this->never())->method('send');

		try {
			$this->sender()->send(leadId: self::LEAD, lead: self::lead(), templateId: 'tpl-q', validUntil: '2026-12-17');
			$this->fail(message: 'A refused render must be refused.');
		} catch (QuoteRefusedException $e) {
			$this->assertSame(expected: 502, actual: $e->getStatus());
			$this->assertSame(expected: ['reason' => 'filinq_render_failed: template broken'], actual: $e->getDetails());
		}
	}//end testARefusedRenderMailsNothing()

	/**
	 * Sending again takes the next number; the first quote is not touched.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-25-products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-sent-quote-does-not-change-afterwards-req-qslice-004
	 */
	public function testSendingAgainTakesTheNextNumber(): void {
		$this->numbers->method('next')->willReturnOnConsecutiveCalls('OFF-2026-0042', 'OFF-2026-0043');
		$this->filinqRenders();
		$this->mailer->method('send')->willReturn(true);
		$this->tickets->method('save')->willReturn(new ObjectEntity());

		$sender = $this->sender();
		$first  = $sender->send(leadId: self::LEAD, lead: self::lead(), templateId: 'tpl-q', validUntil: '2026-12-17');
		$this->lines[0]['quantity'] = 12;
		$this->lines[0]['total']    = 1200;
		$second = $sender->send(leadId: self::LEAD, lead: self::lead(), templateId: 'tpl-q', validUntil: '2026-12-17');

		$this->assertSame(expected: 'OFF-2026-0042', actual: $first['number']);
		$this->assertSame(expected: 1755.0, actual: $first['totals']['totalInclVat']);
		$this->assertSame(expected: 'OFF-2026-0043', actual: $second['number']);
		$this->assertSame(expected: 1997.0, actual: $second['totals']['totalInclVat']);
	}//end testSendingAgainTakesTheNextNumber()

	/**
	 * The file name drops path characters and keeps the number first.
	 *
	 * @return void
	 */
	public function testTheFileNameIsSafe(): void {
		$this->assertSame(
			expected: 'OFF-2026-0042 Kantoor Utrecht en Zeist',
			actual: QuoteSender::filename(number: 'OFF-2026-0042', title: 'Kantoor / Utrecht  en Zeist')
		);
		$this->assertSame(expected: 'OFF-2026-0042', actual: QuoteSender::filename(number: 'OFF-2026-0042', title: ' ../ '));
	}//end testTheFileNameIsSafe()

	/**
	 * The contact moment is stamped with the moment it was sent.
	 *
	 * @return void
	 */
	public function testTheContactMomentCarriesTheMoment(): void {
		$quote   = new SentQuote(
			number: 'OFF-2026-0042',
			validUntil: '2026-12-17',
			totalInclVat: 1755.0,
			content: '%PDF',
			filename: 'OFF-2026-0042.pdf',
			fileId: null,
			path: null
		);
		$payload = $this->sender()->contactMomentPayload(
			leadId: self::LEAD,
			parties: ['contactId' => null, 'clientId' => null],
			quote: $quote,
			recipient: ['email' => 'a@b.example', 'name' => '', 'source' => 'client'],
			occurredAt: new DateTimeImmutable('2026-11-17T10:00:00+01:00')
		);

		$this->assertSame(expected: '2026-11-17T10:00:00+01:00', actual: $payload['occurredAt']);
		$this->assertSame(
			expected: 'Quote OFF-2026-0042 sent to a@b.example, total EUR 1755.00 including VAT, valid until 2026-12-17.',
			actual: $payload['description']
		);
		$this->assertArrayNotHasKey(key: 'client', array: $payload);
	}//end testTheContactMomentCarriesTheMoment()
}//end class
