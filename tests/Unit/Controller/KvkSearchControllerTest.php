<?php

/**
 * Unit tests for KvkSearchController.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\KvkSearchController;
use OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\KvkApiClient;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The KVK search checks the CRM capability before any lookup, and reads an
 * eight-digit query as a KVK number and anything else as a name.
 *
 * @spec openspec/changes/request-dialog-client-lookups/specs/request-management/spec.md#requirement-the-new-request-dialog-finds-the-client-or-looks-them-up
 */
class KvkSearchControllerTest extends TestCase {
	/**
	 * A controller wired to the given KVK client, privilege and query.
	 *
	 * @param KvkApiClient $kvk        The KVK client.
	 * @param bool         $privileged Whether the user may read the CRM.
	 * @param mixed        $query      What getParam('q') answers.
	 *
	 * @return KvkSearchController The controller.
	 */
	private function controller(KvkApiClient $kvk, bool $privileged, mixed $query): KvkSearchController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn($query);
		$user = $this->createConfiguredMock(IUser::class, ['getUID' => 'pieter']);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new KvkSearchController(
			$request,
			$kvk,
			new CrmPrivilegeCheck(
				$this->createConfiguredMock(IUserSession::class, ['getUser' => $user]),
				$this->createConfiguredMock(ObjectOwnerAccessPolicy::class, ['isPrivileged' => $privileged])
			),
			$l10n,
		);
	}//end controller()

	/**
	 * A user without the CRM capability is refused before any lookup.
	 *
	 * @return void
	 */
	public function testAUserWithoutTheCrmCapabilityIsRefused(): void {
		$kvk = $this->createMock(KvkApiClient::class);
		$kvk->expects($this->never())->method('searchByName');
		$kvk->expects($this->never())->method('companyByNumber');

		$response = $this->controller($kvk, false, 'Bakkerij')->search();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testAUserWithoutTheCrmCapabilityIsRefused()

	/**
	 * An empty query is a bad request, with nothing looked up.
	 *
	 * @return void
	 */
	public function testAnEmptyQueryIsRefused(): void {
		$kvk = $this->createMock(KvkApiClient::class);
		$kvk->expects($this->never())->method('searchByName');
		$kvk->expects($this->never())->method('companyByNumber');

		$response = $this->controller($kvk, true, '   ')->search();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testAnEmptyQueryIsRefused()

	/**
	 * A name query searches by name and answers the rows.
	 *
	 * @return void
	 */
	public function testANameSearchesByName(): void {
		$rows = [['kvkNumber' => '12345678', 'naam' => 'Bakkerij De Graaf']];
		$kvk = $this->createMock(KvkApiClient::class);
		$kvk->expects($this->once())->method('searchByName')->with('Bakkerij De Graaf')->willReturn($rows);
		$kvk->expects($this->never())->method('companyByNumber');

		$response = $this->controller($kvk, true, 'Bakkerij De Graaf')->search();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['results' => $rows], $response->getData());
	}//end testANameSearchesByName()

	/**
	 * An eight-digit query is read as a KVK number.
	 *
	 * @return void
	 */
	public function testAnEightDigitQueryLooksUpTheNumber(): void {
		$rows = [['kvkNumber' => '87654321', 'naam' => 'Graaf Holding']];
		$kvk = $this->createMock(KvkApiClient::class);
		$kvk->expects($this->once())->method('companyByNumber')->with('87654321')->willReturn($rows);
		$kvk->expects($this->never())->method('searchByName');

		$response = $this->controller($kvk, true, ' 87654321 ')->search();

		$this->assertSame(['results' => $rows], $response->getData());
	}//end testAnEightDigitQueryLooksUpTheNumber()

	/**
	 * A seven-digit query is a name search, not a number lookup.
	 *
	 * @return void
	 */
	public function testAShortNumericQueryIsANameSearch(): void {
		$kvk = $this->createMock(KvkApiClient::class);
		$kvk->expects($this->once())->method('searchByName')->with('1234567')->willReturn([]);
		$kvk->expects($this->never())->method('companyByNumber');

		$response = $this->controller($kvk, true, '1234567')->search();

		$this->assertSame(['results' => []], $response->getData());
	}//end testAShortNumericQueryIsANameSearch()
}//end class
