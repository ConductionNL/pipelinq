<?php

/**
 * Pipelinq ShillinqInvoiceReader.
 *
 * Reads paid AR invoices out of shillinq's register so a campaign report
 * can close on money that was actually collected, instead of on a lead
 * somebody marked won.
 *
 * 🔴 READ ONLY, AND THAT IS THE POINT. Shillinq owns the ledger (ADR-107).
 * This class has no write path at all, and adding one would be the wrong
 * fix for any problem: an amount Pipelinq wanted booked goes through the
 * existing one-way handoffs (`ShillinqApService`, `ShillinqWipService`),
 * never through here.
 *
 * The read is duck-typed through OpenRegister with `_rbac` and
 * `_multitenancy` off, the same shape `CampaignPerformanceService` uses
 * to read portaliq's rollups. The probe is a string constant and the
 * whole path fails soft: shillinq absent means the report closes on won
 * leads and says so, never that it errors. `withCallerRights()` gives a
 * reader that reads with the signed-in user's rights instead, for a screen
 * that shows a user what shillinq holds.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-attribution-closes-on-a-paid-invoice-or-on-a-won-lead-and-the-report-says-which
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Service\Marketing\ShillinqInvoiceProjection;
use OCA\Pipelinq\Util\PayloadToArray;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * ShillinqInvoiceReader: paid AR invoices for one customer in one window.
 *
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-attribution-closes-on-a-paid-invoice-or-on-a-won-lead-and-the-report-says-which
 */
class ShillinqInvoiceReader {
	use PayloadToArray;


	/**
	 * Shillinq's register slug.
	 *
	 * @var string
	 */
	public const SHILLINQ_REGISTER = 'shillinq';

	/**
	 * The AR invoice schema slug shillinq declares.
	 *
	 * @var string
	 */
	public const AR_INVOICE_SCHEMA = 'ARInvoice';

	/**
	 * A class that exists exactly when shillinq is installed.
	 *
	 * @var string
	 */
	public const SHILLINQ_PROBE_CLASS = 'OCA\\Shillinq\\AppInfo\\Application';

	/**
	 * The lifecycle state that means the money arrived.
	 *
	 * @var string
	 */
	public const PAID_STATE = 'paid';

	/**
	 * Rows read per page, and the hard cap on a single lookup.
	 *
	 * @var int
	 */
	private const PAGE = 100;

	/**
	 * Never read more than this many invoices for one customer.
	 *
	 * @var int
	 */
	private const MAX_ROWS = 500;

	/**
	 * Whether reads apply the signed-in user's rights.
	 *
	 * @var bool
	 */
	private bool $callerRights = false;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container for the lazy, duck-typed object service.
	 * @param LoggerInterface $logger Logger.
	 * @param ShillinqInvoiceProjection $projection Reduces one shillinq row to what pipelinq reads.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-attribution-closes-on-a-paid-invoice-or-on-a-won-lead-and-the-report-says-which
	 */
	public function __construct(
		private ContainerInterface $container,
		private LoggerInterface $logger,
		private ShillinqInvoiceProjection $projection = new ShillinqInvoiceProjection(),
	) {
	}//end __construct()

	/**
	 * This reader with the signed-in user's rights: every read applies
	 * OpenRegister's RBAC and multitenancy instead of reading as the system.
	 *
	 * @return static The reader.
	 *
	 * @spec openspec/specs/client-detail/spec.md#requirement-the-client-page-says-what-the-client-does-business-on
	 */
	public function withCallerRights(): static {
		$reader = clone $this;
		$reader->callerRights = true;
		return $reader;
	}//end withCallerRights()

	/**
	 * Whether the signed-in user may read shillinq's AR invoices. A right
	 * that cannot be checked answers no.
	 *
	 * @return bool True only when OpenRegister grants read on the schema.
	 *
	 * @spec openspec/specs/client-detail/spec.md#requirement-the-client-page-says-what-the-client-does-business-on
	 */
	public function callerMayRead(): bool {
		if ($this->probe() === false) {
			return false;
		}

		try {
			$register = $this->container->get('OCA\OpenRegister\Db\RegisterMapper')->find(
				id: self::SHILLINQ_REGISTER,
				_rbac: false,
				_multitenancy: false
			);
			$schema   = $this->container->get('OCA\OpenRegister\Db\SchemaMapper')->findBySlugInIds(
				slug: self::AR_INVOICE_SCHEMA,
				schemaIds: $register->getSchemas()
			);
			if ($schema === null) {
				return false;
			}

			return $this->container->get('OCA\OpenRegister\Service\ObjectService')
				->getPermissionHandler()
				->hasPermission(schema: $schema, action: 'read');
		} catch (Throwable $e) {
			$this->logger->warning(
				'ShillinqInvoiceReader: the invoice read right could not be checked',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end callerMayRead()

	/**
	 * Whether shillinq is installed. Protected so a test can answer for it.
	 *
	 * @return bool True when shillinq is present.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-attribution-closes-on-a-paid-invoice-or-on-a-won-lead-and-the-report-says-which
	 */
	public function isAvailable(): bool {
		return $this->probe();
	}//end isAvailable()

	/**
	 * The paid AR invoices of one shillinq customer inside a window.
	 *
	 * @param string $customerRef The shillinq customer reference, as
	 *                            `client.shillinqOrganisationRef` records it.
	 * @param string $from Window start `YYYY-MM-DD`, inclusive.
	 * @param string $to Window end `YYYY-MM-DD`, inclusive.
	 *
	 * @return array<int, array{id: string, amount: float, currency: string, invoiceDate: string, invoiceNumber: string}>
	 *         The invoices, keyed by nothing: the caller dedupes by id.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-attribution-closes-on-a-paid-invoice-or-on-a-won-lead-and-the-report-says-which
	 */
	public function paidInvoicesFor(string $customerRef, string $from, string $to): array {
		$rows = $this->customerRows(customerRef: $customerRef, filters: ['lifecycleState' => self::PAID_STATE]);

		$invoices = [];
		foreach ($rows as $row) {
			$invoice = $this->normalise(row: $row);
			if ($invoice === null || $this->inWindow(date: $invoice['invoiceDate'], from: $from, to: $to) === false) {
				continue;
			}

			$invoices[] = $invoice;
		}

		return $invoices;
	}//end paidInvoicesFor()

	/**
	 * Every invoice of one shillinq customer that is past the draft stage.
	 *
	 * `paidInvoicesFor()` answers "what money arrived", which is the only
	 * question a campaign report may ask. A marketing signal asks two more:
	 * when the customer last received an invoice at all, and whether one of
	 * them is in dunning. Both need the invoices that were NOT paid, so this
	 * is a second read rather than a widened first one: nothing that closes
	 * attribution may accidentally start counting an unpaid invoice.
	 *
	 * Drafts are excluded. A draft invoice is a document nobody has sent, so
	 * treating it as contact with the customer would date a lapsed customer
	 * from a note somebody typed.
	 *
	 * @param string $customerRef The shillinq customer reference, as
	 *                            `client.shillinqOrganisationRef` records it.
	 *
	 * @return array<int, array<string, mixed>> One entry per invoice: `id`,
	 *         `amount`, `currency`, `invoiceDate`, `dueDate`, `lifecycleState`
	 *         and `lines`. Newest first is NOT guaranteed: the caller sorts.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-bookkeeping-supplies-six-segment-fields-and-pipelinq-stores-none-of-them
	 */
	public function invoicesFor(string $customerRef): array {
		$rows = $this->customerRows(customerRef: $customerRef, filters: []);

		$invoices = [];
		foreach ($rows as $row) {
			$invoice = $this->projection->whole(row: $row);
			if ($invoice !== null) {
				$invoices[] = $invoice;
			}
		}

		return $invoices;
	}//end invoicesFor()

	/**
	 * Whether shillinq's application class is loadable. Protected so a
	 * test can substitute the answer without shillinq present.
	 *
	 * @return bool True when shillinq is installed.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-attribution-closes-on-a-paid-invoice-or-on-a-won-lead-and-the-report-says-which
	 */
	protected function probe(): bool {
		return class_exists(self::SHILLINQ_PROBE_CLASS);
	}//end probe()

	/**
	 * The OpenRegister object service, or null when it cannot be built.
	 *
	 * @return object|null The service.
	 */
	private function objectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'ShillinqInvoiceReader: OpenRegister unavailable',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end objectService()

	/**
	 * The AR invoice rows of one customer, with any further filters; none
	 * without a customer, without shillinq or without OpenRegister.
	 *
	 * @param string               $customerRef The shillinq customer reference.
	 * @param array<string, mixed> $filters     Filters on top of register, schema and customer.
	 *
	 * @return array<int, array<string, mixed>> Plain rows.
	 */
	private function customerRows(string $customerRef, array $filters): array {
		$customer = trim($customerRef);
		if ($customer === '' || $this->probe() === false) {
			return [];
		}

		$objectService = $this->objectService();
		if ($objectService === null) {
			return [];
		}

		return $this->pages(
			objectService: $objectService,
			filters: array_merge(
				['register' => self::SHILLINQ_REGISTER, 'schema' => self::AR_INVOICE_SCHEMA, 'customerId' => $customer],
				$filters
			)
		);
	}//end customerRows()

	/**
	 * Read matching rows page by page until a short page or the cap, with
	 * this reader's access flags; a failed read is logged and ends the read.
	 *
	 * @param object               $objectService The OpenRegister object service.
	 * @param array<string, mixed> $filters       The filters, register and schema included.
	 *
	 * @return array<int, array<string, mixed>> Plain rows.
	 */
	private function pages(object $objectService, array $filters): array {
		$reader = new ObjectPageReader(objectService: $objectService, withRights: $this->callerRights);
		$reader->pageSize = self::PAGE;
		$reader->maxRows = self::MAX_ROWS;
		$rows = $reader->readAll(filters: $filters);
		if ($reader->failure !== null) {
			$this->logger->warning(
				'ShillinqInvoiceReader: reading AR invoices failed',
				['customer' => (string)($filters['customerId'] ?? ''), 'exception' => $reader->failure->getMessage()]
			);
		}

		return array_map(fn (mixed $row): array => $this->toArray(value: $row), $rows);
	}//end pages()

	/**
	 * Reduce a shillinq row to the four things a campaign report needs.
	 *
	 * A row whose `lifecycleState` is not `paid` is dropped here as well
	 * as in the filter, because OpenRegister's filter DSL ignores a key it
	 * does not recognise and an ignored filter returns rows nobody asked
	 * for while looking exactly like a correct result.
	 *
	 * @param array<string, mixed> $row The shillinq AR invoice.
	 *
	 * @return array{id: string, amount: float, currency: string, invoiceDate: string, invoiceNumber: string}|null
	 *         Null when the row is not a paid invoice we can read.
	 */
	private function normalise(array $row): ?array {
		if ((string)($row['lifecycleState'] ?? '') !== self::PAID_STATE) {
			return null;
		}

		$id = $this->projection->identify(row: $row);
		if ($id === '') {
			return null;
		}

		return [
			'id' => $id,
			'amount' => (float)($row['grossAmount'] ?? 0),
			'currency' => (string)($row['currency'] ?? 'EUR'),
			'invoiceDate' => substr((string)($row['invoiceDate'] ?? ''), 0, 10),
			'invoiceNumber' => (string)($row['invoiceNumber'] ?? ''),
		];
	}//end normalise()

	/**
	 * Whether a date falls inside an inclusive window.
	 *
	 * An invoice with no date counts: shillinq is the authority on its own
	 * rows, and dropping a paid invoice because a field is blank would
	 * under-report money that was actually collected.
	 *
	 * @param string $date The invoice date `YYYY-MM-DD`, may be empty.
	 * @param string $from Window start.
	 * @param string $to Window end.
	 *
	 * @return bool True when it counts.
	 */
	private function inWindow(string $date, string $from, string $to): bool {
		if ($date === '') {
			return true;
		}

		if ($from !== '' && $date < $from) {
			return false;
		}

		return ($to === '' || $date <= $to);
	}//end inWindow()
}//end class
