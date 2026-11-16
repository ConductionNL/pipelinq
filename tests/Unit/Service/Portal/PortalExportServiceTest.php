<?php

/**
 * Unit tests for PortalExportService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Portal;

use DateTime;
use OCA\Pipelinq\Service\Portal\DocumentSigningService;
use OCA\Pipelinq\Service\Portal\MainRegisterReader;
use OCA\Pipelinq\Service\Portal\PortalAuditService;
use OCA\Pipelinq\Service\Portal\PortalDelegationService;
use OCA\Pipelinq\Service\Portal\PortalExportService;
use OCA\Pipelinq\Service\Portal\PortalInvoiceService;
use OCA\Pipelinq\Service\Portal\PortalMailService;
use OCA\Pipelinq\Service\Portal\PortalObjectRepository;
use OCA\Pipelinq\Service\Portal\PortalProfileService;
use OCA\Pipelinq\Service\Portal\PortalScopeResolver;
use OCA\Pipelinq\Service\Portal\PortalTokenService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The GDPR export bundle and the export request (signed link, mail, audit),
 * with the real portal services wired around mocked storage, mailer and clock.
 *
 * @covers \OCA\Pipelinq\Service\Portal\PortalExportService
 * @uses   \OCA\Pipelinq\Service\Portal\AbstractPortalReadFacade
 * @uses   \OCA\Pipelinq\Service\Portal\AbstractPosTransactionFacade
 * @uses   \OCA\Pipelinq\Service\Portal\DocumentSigningService
 * @uses   \OCA\Pipelinq\Service\Portal\PortalAuditService
 * @uses   \OCA\Pipelinq\Service\Portal\PortalDelegationService
 * @uses   \OCA\Pipelinq\Service\Portal\PortalInvoiceService
 * @uses   \OCA\Pipelinq\Service\Portal\PortalMailService
 * @uses   \OCA\Pipelinq\Service\Portal\PortalProfileService
 * @uses   \OCA\Pipelinq\Service\Portal\PortalScopeResolver
 * @uses   \OCA\Pipelinq\Service\Portal\PortalTokenService
 * @uses   \OCA\Pipelinq\Service\Portal\ScopedOwner
 */
class PortalExportServiceTest extends TestCase {
	/**
	 * Fixed clock, in seconds.
	 *
	 * @var int
	 */
	private const NOW = 1772600767;

	/**
	 * Portal repository mock.
	 *
	 * @var PortalObjectRepository&MockObject
	 */
	private PortalObjectRepository&MockObject $repository;

	/**
	 * Main register reader mock.
	 *
	 * @var MainRegisterReader&MockObject
	 */
	private MainRegisterReader&MockObject $reader;

	/**
	 * Mailer mock.
	 *
	 * @var IMailer&MockObject
	 */
	private IMailer&MockObject $mailer;

	/**
	 * Service under test.
	 *
	 * @var PortalExportService
	 */
	private PortalExportService $service;

	/**
	 * Wire the export service from real portal services.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->repository = $this->createMock(PortalObjectRepository::class);
		$this->repository->method('idOf')->willReturnCallback(
			static fn (array $object): ?string => ($object['@self']['id'] ?? null)
		);
		$this->reader = $this->createMock(MainRegisterReader::class);
		$this->mailer = $this->createMock(IMailer::class);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$time->method('getDateTime')->willReturnCallback(
			static fn (): DateTime => (new DateTime('@' . self::NOW))
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'T:' . $text);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('signing-key');

		$random = $this->createMock(ISecureRandom::class);
		$logger = $this->createMock(LoggerInterface::class);

		$audit       = new PortalAuditService($this->repository, $time, $logger);
		$mail        = new PortalMailService($this->mailer, $this->createMock(IURLGenerator::class), $l10n, $logger);
		$tokens      = new PortalTokenService($random, $time);
		$profile     = new PortalProfileService($this->repository, $tokens, $mail, $audit, $l10n);
		$delegations = new PortalDelegationService($this->repository, $audit, $time);
		$scope       = new PortalScopeResolver($this->repository, $delegations);
		$invoices    = new PortalInvoiceService($this->reader, $scope);
		$signing     = new DocumentSigningService($appConfig, $random, $time);

		$this->service = new PortalExportService($profile, $audit, $invoices, $signing, $mail, $time, $l10n);
	}//end setUp()

	/**
	 * The export bundles the profile, trimmed audit events and own invoices.
	 *
	 * @return void
	 */
	public function testBuildExport(): void {
		$account = [
			'@self'                => ['id' => 'acc-1'],
			'email'                => 'jan@example.test',
			'displayName'          => 'Jan',
			'linkedOrganisationId' => 'org-1',
			'passwordHash'         => 'secret',
		];

		$this->repository->method('findAll')->willReturnCallback(
			static function (string $schema, array $filters): array {
				if ($schema === 'portalAuditEvent') {
					TestCase::assertSame(['accountId' => 'acc-1'], $filters);
					return [
						['eventType' => 'login', 'outcome' => 'success', 'occurredAt' => '2026-01-01', 'ipHash' => 'x'],
						['eventType' => 'logout', 'occurredAt' => '2026-02-01'],
					];
				}

				return [];
			}
		);
		$this->reader->method('findAll')->with('posTransaction')->willReturn(
			[
				['id' => 'inv-1', 'client' => 'org-1', 'invoiceNumber' => 'F-1', 'confirmedAt' => '2026-01-05', 'total' => 10],
				['id' => 'inv-2', 'client' => 'org-2', 'invoiceNumber' => 'F-2', 'confirmedAt' => '2026-01-06', 'total' => 20],
				['id' => 'inv-3', 'client' => ['id' => 'org-1'], 'reference' => 'R-3', 'settledAt' => '2026-01-07'],
			]
		);

		$this->assertSame(
			[
				'exportedAt'  => '2026-03-04T05:06:07+00:00',
				'account'     => [
					'id'           => 'acc-1',
					'email'        => 'jan@example.test',
					'pendingEmail' => null,
					'displayName'  => 'Jan',
					'phone'        => null,
					'address'      => null,
					'locale'       => 'nl',
					'jobTitle'     => null,
					'accountType'  => 'b2c',
				],
				'auditEvents' => [
					['eventType' => 'logout', 'outcome' => null, 'occurredAt' => '2026-02-01'],
					['eventType' => 'login', 'outcome' => 'success', 'occurredAt' => '2026-01-01'],
				],
				'documents'   => [
					['type' => 'invoice', 'number' => 'R-3', 'date' => '2026-01-07', 'amount' => null],
					['type' => 'invoice', 'number' => 'F-1', 'date' => '2026-01-05', 'amount' => 10],
				],
			],
			$this->service->buildExport($account)
		);
	}//end testBuildExport()

	/**
	 * Requesting an export signs a 30-day link, mails the account and audits it.
	 *
	 * @return void
	 */
	public function testRequestExport(): void {
		$message = $this->createMock(IMessage::class);
		$message->expects($this->once())->method('setTo')->with(['jan@example.test']);
		$message->expects($this->once())->method('setSubject')->with('T:Your data export is ready');
		$message->expects($this->once())->method('setPlainBody')
			->with('T:Your data export is ready to download. The link is valid for 30 days.');
		$this->mailer->method('validateMailAddress')->willReturn(true);
		$this->mailer->method('createMessage')->willReturn($message);
		$this->mailer->expects($this->once())->method('send')->with($message)->willReturn([]);

		$this->repository->expects($this->once())->method('save')->with(
			'portalAuditEvent',
			$this->callback(
				static fn (array $record): bool => $record['accountId'] === 'acc-1'
					&& $record['tenantId'] === 'acme'
					&& $record['eventType'] === 'data-export-requested'
					&& $record['outcome'] === 'success'
			)
		);

		$result = $this->service->requestExport(['id' => 'acc-1', 'email' => 'jan@example.test'], 'acme');

		$this->assertSame(self::NOW + (30 * 24 * 60 * 60), $result['expiresAt']);
		$this->assertMatchesRegularExpression('#^/portal/api/documents/[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/download$#', $result['downloadUrl']);

		$payload = explode('.', explode('/', $result['downloadUrl'])[4])[0];
		$decoded = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
		$this->assertSame(
			['objectId' => 'acc-1', 'objectType' => 'data-export', 'accountId' => 'acc-1', 'issuedAt' => self::NOW, 'expiresAt' => self::NOW + 2592000],
			$decoded
		);
	}//end testRequestExport()

	/**
	 * The account id falls back from @self.id to id to uuid.
	 *
	 * @return void
	 */
	public function testRequestExportUsesUuidFallback(): void {
		$this->mailer->method('validateMailAddress')->willReturn(false);
		$this->mailer->expects($this->never())->method('send');
		$this->repository->expects($this->once())->method('save')->with(
			'portalAuditEvent',
			$this->callback(static fn (array $record): bool => $record['accountId'] === 'u-7')
		);

		$this->service->requestExport(['uuid' => 'u-7'], 'acme');
	}//end testRequestExportUsesUuidFallback()
}//end class
