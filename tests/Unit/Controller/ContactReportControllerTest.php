<?php

/**
 * Unit tests for ContactReportController and the report's Excel and PDF export.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/kcc-report-top-occupancy-forecast-export/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\ContactReportController;
use OCA\Pipelinq\Controller\ReportingController;
use OCA\Pipelinq\Kernel\Contracts\ContactReportServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ReportingServiceInterface;
use OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\ContactReport\ContactReportFilter;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Access before every read, the filters, and the export formats.
 */
#[CoversClass(ContactReportController::class)]
#[CoversClass(ReportingController::class)]
#[UsesClass(ContactReportFilter::class)]
#[UsesClass(CrmPrivilegeCheck::class)]
final class ContactReportControllerTest extends TestCase {
	/**
	 * The contact report double.
	 *
	 * @var ContactReportServiceInterface&MockObject
	 */
	private ContactReportServiceInterface&MockObject $report;

	/**
	 * Set up the report double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->report = $this->createMock(ContactReportServiceInterface::class);
	}//end setUp()

	/**
	 * The CRM privilege check for a caller: crm-user is privileged, intern is not.
	 *
	 * @param string|null $caller The signed-in uid, or null for no session.
	 *
	 * @return CrmPrivilegeCheck The check.
	 */
	private function privilege(?string $caller): CrmPrivilegeCheck {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($caller !== null) {
			$user = $this->createConfiguredMock(IUser::class, ['getUID' => $caller]);
		}

		$session->method('getUser')->willReturn($user);
		$policy = $this->createMock(ObjectOwnerAccessPolicy::class);
		$policy->method('isPrivileged')->willReturnCallback(static fn (string $uid): bool => $uid === 'crm-user');
		return new CrmPrivilegeCheck($session, $policy);
	}//end privilege()

	/**
	 * A request answering the given query.
	 *
	 * @param array<string, mixed> $query The query.
	 *
	 * @return IRequest The request.
	 */
	private function request(array $query): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => (array_key_exists($key, $query) === true) ? $query[$key] : $default
		);
		return $request;
	}//end request()

	/**
	 * The controller for a caller and query.
	 *
	 * @param string|null          $caller The signed-in uid.
	 * @param array<string, mixed> $query  The query.
	 *
	 * @return ContactReportController The controller.
	 */
	private function controller(?string $caller, array $query): ContactReportController {
		return new ContactReportController(request: $this->request(query: $query), report: $this->report, privilege: $this->privilege(caller: $caller));
	}//end controller()

	/**
	 * Every route of the report.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function routes(): array {
		return ['top subjects' => ['topSubjects'], 'occupancy' => ['occupancy'], 'forecast' => ['forecast']];
	}//end routes()

	/**
	 * A reader without the privilege is refused before any contact moment is read.
	 *
	 * @param string $action The route's action.
	 *
	 * @return void
	 */
	#[DataProvider('routes')]
	public function testAReaderWithoutThePrivilegeIsRefusedBeforeAnyRead(string $action): void {
		$this->report->expects($this->never())->method($this->anything());
		$query = ['from' => '2026-11-09', 'to' => '2026-11-15'];

		$forbidden = $this->controller(caller: 'intern', query: $query)->$action();
		$anonymous = $this->controller(caller: null, query: $query)->$action();

		$this->assertSame(403, $forbidden->getStatus());
		$this->assertSame(['message' => 'Forbidden'], $forbidden->getData());
		$this->assertSame(401, $anonymous->getStatus());
	}//end testAReaderWithoutThePrivilegeIsRefusedBeforeAnyRead()

	/**
	 * The top subjects are read for the period with the team, channel and employee filters.
	 *
	 * @return void
	 */
	public function testTopSubjectsPassesThePeriodAndFilters(): void {
		$this->report->expects($this->once())->method('topSubjects')
			->with('2026-11-09', '2026-11-15', new ContactReportFilter(team: 'burgerzaken', channel: 'telefoon', employee: 'alice'))
			->willReturn(['subjects' => [['subject' => 'Paspoort', 'count' => 4]]]);

		$response = $this->controller(
			caller: 'crm-user',
			query: ['from' => '2026-11-09', 'to' => '2026-11-15', 'team' => 'burgerzaken', 'channel' => 'telefoon', 'employee' => 'alice']
		)->topSubjects();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['subjects' => [['subject' => 'Paspoort', 'count' => 4]]], $response->getData());
	}//end testTopSubjectsPassesThePeriodAndFilters()

	/**
	 * Occupancy without a period, or with an unreadable one, is a 400 and reads nothing.
	 *
	 * @return void
	 */
	public function testOccupancyNeedsAValidPeriod(): void {
		$this->report->expects($this->never())->method('occupancy');

		$missing = $this->controller(caller: 'crm-user', query: ['from' => '2026-11-09'])->occupancy();
		$invalid = $this->controller(caller: 'crm-user', query: ['from' => 'yesterday-ish', 'to' => '2026-11-15'])->occupancy();

		$this->assertSame(400, $missing->getStatus());
		$this->assertSame(400, $invalid->getStatus());
	}//end testOccupancyNeedsAValidPeriod()

	/**
	 * The forecast is read with the filters and needs no period; a failure is a generic 500.
	 *
	 * @return void
	 */
	public function testForecastPassesTheFiltersAndHidesFailures(): void {
		$this->report->expects($this->exactly(2))->method('forecast')
			->with(new ContactReportFilter(channel: 'email'))
			->willReturnOnConsecutiveCalls(['enoughHistory' => false, 'days' => []], $this->throwException(new \RuntimeException('store down')));

		$first = $this->controller(caller: 'crm-user', query: ['channel' => 'email'])->forecast();
		$second = $this->controller(caller: 'crm-user', query: ['channel' => 'email'])->forecast();

		$this->assertSame(['enoughHistory' => false, 'days' => []], $first->getData());
		$this->assertSame(500, $second->getStatus());
		$this->assertSame(['message' => 'Operation failed'], $second->getData());
	}//end testForecastPassesTheFiltersAndHidesFailures()

	/**
	 * The export controller for a caller and query.
	 *
	 * @param string|null              $caller    The signed-in uid.
	 * @param array<string, mixed>     $query     The query.
	 * @param ReportingServiceInterface $reporting The reporting service.
	 *
	 * @return ReportingController The controller.
	 */
	private function exporter(?string $caller, array $query, ReportingServiceInterface $reporting): ReportingController {
		return new ReportingController(
			request: $this->request(query: $query),
			reportingService: $reporting,
			privilege: $this->privilege(caller: $caller),
			contactReport: $this->report,
		);
	}//end exporter()

	/**
	 * An unknown format is a 400 and nothing is generated.
	 *
	 * @return void
	 */
	public function testAnUnknownExportFormatIsRefused(): void {
		$reporting = $this->createMock(ReportingServiceInterface::class);
		$reporting->expects($this->never())->method($this->anything());
		$this->report->expects($this->never())->method($this->anything());

		$response = $this->exporter(caller: 'crm-user', query: ['from' => '2026-11-09', 'to' => '2026-11-15', 'format' => 'doc'], reporting: $reporting)->exportCsv();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(400, $response->getStatus());
	}//end testAnUnknownExportFormatIsRefused()

	/**
	 * Excel and PDF are generated for the period and filters, as a download of the right type.
	 *
	 * @return void
	 */
	public function testExcelAndPdfExportsCarryTheFilters(): void {
		$filter = new ContactReportFilter(team: 'burgerzaken');
		$this->report->expects($this->once())->method('workbook')->with('2026-11-09', '2026-11-15', $filter)->willReturn('XLSX');
		$this->report->expects($this->once())->method('document')->with('2026-11-09', '2026-11-15', $filter)->willReturn('PDF');
		$reporting = $this->createMock(ReportingServiceInterface::class);
		$query = ['from' => '2026-11-09', 'to' => '2026-11-15', 'team' => 'burgerzaken'];

		$xlsx = $this->exporter(caller: 'crm-user', query: $query + ['format' => 'xlsx'], reporting: $reporting)->exportCsv();
		$pdf = $this->exporter(caller: 'crm-user', query: $query + ['format' => 'pdf'], reporting: $reporting)->exportCsv();

		$this->assertInstanceOf(DataDownloadResponse::class, $xlsx);
		$this->assertSame('XLSX', $xlsx->render());
		$this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', self::headers(response: $xlsx)['Content-Type']);
		$this->assertStringContainsString('rapportage-2026-11-09-2026-11-15.xlsx', self::headers(response: $xlsx)['Content-Disposition']);
		$this->assertInstanceOf(DataDownloadResponse::class, $pdf);
		$this->assertSame('PDF', $pdf->render());
		$this->assertSame('application/pdf', self::headers(response: $pdf)['Content-Type']);
	}//end testExcelAndPdfExportsCarryTheFilters()

	/**
	 * A response's own headers; getHeaders() would also reach for the server container.
	 *
	 * @param DataDownloadResponse $response The response.
	 *
	 * @return array<string, string> The headers.
	 */
	private static function headers(DataDownloadResponse $response): array {
		return (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($response);
	}//end headers()

	/**
	 * The export formats keep the same access check: a reader without the privilege gets nothing.
	 *
	 * @return void
	 */
	public function testExportFormatsAreRefusedWithoutThePrivilege(): void {
		$this->report->expects($this->never())->method($this->anything());
		$reporting = $this->createMock(ReportingServiceInterface::class);

		$response = $this->exporter(caller: 'intern', query: ['from' => '2026-11-09', 'to' => '2026-11-15', 'format' => 'xlsx'], reporting: $reporting)->exportCsv();

		$this->assertSame(403, $response->getStatus());
	}//end testExportFormatsAreRefusedWithoutThePrivilege()
}//end class
