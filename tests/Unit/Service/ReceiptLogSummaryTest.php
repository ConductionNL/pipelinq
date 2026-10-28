<?php

/**
 * Unit tests for ReceiptDeliveryService::receiptLog(): what the receipt card
 * on a transaction page shows (board PqKassabon "Bon").
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pos-receipt-card/specs/pos-receipt-engine/spec.md#requirement-a-transaction-page-shows-its-receipt-history
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCA\Pipelinq\Service\InvoiceSequenceService;
use OCA\Pipelinq\Service\ReceiptDeliveryService;
use OCA\Pipelinq\Service\ReceiptService;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Mail\IMailer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Receipt card summary.
 */
class ReceiptLogSummaryTest extends TestCase {

	/**
	 * The object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objectService;

	/**
	 * The access policy.
	 *
	 * @var PosAccessPolicy&MockObject
	 */
	private PosAccessPolicy $policy;

	/**
	 * The service under test.
	 *
	 * @var ReceiptDeliveryService
	 */
	private ReceiptDeliveryService $service;

	/**
	 * Wire the service with a transaction and three log entries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->policy = $this->createMock(originalClassName: PosAccessPolicy::class);
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key): string => $key);

		$transaction = new ObjectEntity();
		$transaction->setUuid('tx-1');
		$transaction->setObject(['id' => 'tx-1', 'total' => 96.41, 'status' => 'settled']);
		$this->objectService->method('find')->willReturn($transaction);
		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config): array {
				if (($config['filters']['schema'] ?? '') !== 'receiptPrintLog_schema') {
					return [];
				}

				return [
					$this->log(action: 'print', extra: ['printerDevice' => 'Bonprinter balie 2', 'printedAt' => '2026-10-07T11:43:00+02:00']),
					$this->log(action: 'print', extra: ['printerDevice' => 'Bonprinter balie 1', 'printedAt' => '2026-10-07T11:40:00+02:00']),
					$this->log(action: 'email', extra: ['emailRecipient' => 's@example.nl', 'printedAt' => '2026-10-07T11:50:00+02:00', 'status' => 'failed']),
				];
			}
		);

		$this->service = new ReceiptDeliveryService(
			receiptService: $this->createMock(originalClassName: ReceiptService::class),
			invoiceSequence: $this->createMock(originalClassName: InvoiceSequenceService::class),
			mailer: $this->createMock(originalClassName: IMailer::class),
			appConfig: $appConfig,
			policy: $this->policy,
			l10n: $this->createMock(originalClassName: IL10N::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			objectService: $this->objectService,
		);
	}//end setUp()

	/**
	 * A receipt log entry of transaction tx-1.
	 *
	 * @param string $action The action, print or email.
	 * @param array<string, mixed> $extra The other fields.
	 *
	 * @return array<string, mixed> The entry.
	 */
	private function log(string $action, array $extra): array {
		return array_merge(['transaction' => 'tx-1', 'action' => $action, 'status' => 'success'], $extra);
	}//end log()

	/**
	 * The newest print, the number of prints, the newest sent mail and the layout.
	 *
	 * @return void
	 */
	public function testReceiptLogSummarisesPrintsAndMails(): void {
		$this->policy->method('canAccessTransaction')->willReturn(true);

		$log = $this->service->receiptLog(transactionId: 'tx-1', userId: 'pieter');

		$this->assertSame(expected: '2026-10-07T11:43:00+02:00', actual: $log['printedAt']);
		$this->assertSame(expected: 'Bonprinter balie 2', actual: $log['printerDevice']);
		$this->assertSame(expected: 2, actual: $log['printCount']);
		$this->assertSame(expected: '', actual: $log['emailedAt']);
		$this->assertSame(expected: 'receipt', actual: $log['layout']);
		$this->assertSame(expected: ReceiptService::INVOICE_THRESHOLD_EUR, actual: $log['invoiceThreshold']);
	}//end testReceiptLogSummarisesPrintsAndMails()

	/**
	 * Someone who may not see the transaction gets no log.
	 *
	 * @return void
	 */
	public function testReceiptLogChecksAccess(): void {
		$this->policy->method('canAccessTransaction')->willReturn(false);

		$this->expectException(exception: OCSForbiddenException::class);
		$this->service->receiptLog(transactionId: 'tx-1', userId: 'stranger');
	}//end testReceiptLogChecksAccess()
}//end class
