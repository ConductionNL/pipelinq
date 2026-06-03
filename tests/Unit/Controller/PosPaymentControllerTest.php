<?php

/**
 * Unit tests for PosPaymentController.
 *
 * The controller is thin: these tests assert its authorization gating (401 when
 * unauthenticated, 403 when a non-admin attempts a tender-type write) and its
 * OCS-exception → HTTP status mapping (e.g. a settled-transaction conflict from
 * the service surfaces as 409 on remove, a delete-with-references as 409). The
 * payment service is mocked; its own behaviour is covered by PosPaymentServiceTest.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\PosPaymentController;
use OCA\Pipelinq\Service\PosPaymentService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for PosPaymentController.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Each auth/status path is asserted
 *  independently for clarity.
 */
class PosPaymentControllerTest extends TestCase
{
    private PosPaymentController $controller;

    private PosPaymentService $service;

    private IUserSession $userSession;

    private IGroupManager $groupManager;

    private IRequest $request;

    /**
     * Wire the controller with mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request      = $this->createMock(IRequest::class);
        $this->service      = $this->createMock(PosPaymentService::class);
        $this->userSession  = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

        $this->controller = new PosPaymentController(
            $this->request,
            $this->service,
            $this->userSession,
            $this->groupManager,
            $l10n,
            $this->createMock(LoggerInterface::class),
        );
    }//end setUp()

    /**
     * Sign in a user with the given uid and admin flag.
     *
     * @param string $uid     The user uid.
     * @param bool   $isAdmin Whether the user is an admin.
     *
     * @return void
     */
    private function login(string $uid, bool $isAdmin = false): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->willReturn($isAdmin);
    }//end login()

    /**
     * Listing tender types without a session returns 401.
     *
     * @return void
     */
    public function testTenderTypesUnauthenticatedReturns401(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->assertSame(401, $this->controller->tenderTypes()->getStatus());
    }//end testTenderTypesUnauthenticatedReturns401()

    /**
     * An authenticated user can list active tender types (200).
     *
     * @return void
     */
    public function testTenderTypesAuthenticatedReturns200(): void
    {
        $this->login('cashier');
        $this->service->method('getActiveTenderTypes')->willReturn([['code' => 'CASH']]);

        $response = $this->controller->tenderTypes();
        $this->assertSame(200, $response->getStatus());
        $this->assertSame([['code' => 'CASH']], $response->getData()['tenderTypes']);
    }//end testTenderTypesAuthenticatedReturns200()

    /**
     * A non-admin creating a tender type is rejected with 403.
     *
     * @return void
     */
    public function testCreateTenderTypeNonAdminReturns403(): void
    {
        $this->login('cashier', isAdmin: false);

        $this->assertSame(403, $this->controller->createTenderType()->getStatus());
    }//end testCreateTenderTypeNonAdminReturns403()

    /**
     * An admin creating a valid tender type gets 201.
     *
     * @return void
     */
    public function testCreateTenderTypeAdminReturns201(): void
    {
        $this->login('boss', isAdmin: true);
        $this->request->method('getParam')->willReturnCallback(
            static fn (string $key, $default = null) => $default
        );
        $this->service->method('createTenderType')->willReturn(['id' => 'new', 'code' => 'MOLLIE']);

        $response = $this->controller->createTenderType();
        $this->assertSame(201, $response->getStatus());
    }//end testCreateTenderTypeAdminReturns201()

    /**
     * A validation failure on create (e.g. missing GL account) surfaces as 400.
     *
     * @return void
     */
    public function testCreateTenderTypeValidationErrorReturns400(): void
    {
        $this->login('boss', isAdmin: true);
        $this->request->method('getParam')->willReturnCallback(
            static fn (string $key, $default = null) => $default
        );
        $this->service->method('createTenderType')
            ->willThrowException(new OCSBadRequestException('Grootboekrekening is vereist.'));

        $this->assertSame(400, $this->controller->createTenderType()->getStatus());
    }//end testCreateTenderTypeValidationErrorReturns400()

    /**
     * Deleting a tender type that still has references surfaces as 409 Conflict.
     *
     * @return void
     */
    public function testDestroyTenderTypeWithReferencesReturns409(): void
    {
        $this->login('boss', isAdmin: true);
        $this->service->method('deleteTenderType')
            ->willThrowException(new OCSBadRequestException('Kan betalingstype niet verwijderen: 3 betaling(en).'));

        $this->assertSame(409, $this->controller->destroyTenderType('type-1')->getStatus());
    }//end testDestroyTenderTypeWithReferencesReturns409()

    /**
     * Adding a tender as an authenticated user returns 201 with the new tender.
     *
     * @return void
     */
    public function testAddTenderReturns201(): void
    {
        $this->login('cashier');
        $this->request->method('getParam')->willReturnCallback(
            static fn (string $key, $default = null) => $default
        );
        $this->service->method('addTender')->willReturn(['id' => 'tender-1', 'amount' => 50.0]);
        $this->service->method('validateTenderSum')->willReturn(['reconciled' => false]);

        $response = $this->controller->addTender('txn-1');
        $this->assertSame(201, $response->getStatus());
        $this->assertSame('tender-1', $response->getData()['tender']['id']);
    }//end testAddTenderReturns201()

    /**
     * Adding a tender to a settled transaction surfaces the service's bad-request
     * as 400.
     *
     * @return void
     */
    public function testAddTenderToSettledReturns400(): void
    {
        $this->login('cashier');
        $this->request->method('getParam')->willReturnCallback(
            static fn (string $key, $default = null) => $default
        );
        $this->service->method('addTender')
            ->willThrowException(new OCSBadRequestException('afgeronde transactie'));

        $this->assertSame(400, $this->controller->addTender('txn-1')->getStatus());
    }//end testAddTenderToSettledReturns400()

    /**
     * Removing a tender from a settled transaction surfaces as 409 Conflict.
     *
     * @return void
     */
    public function testRemoveTenderFromSettledReturns409(): void
    {
        $this->login('cashier');
        $this->service->method('removeTender')
            ->willThrowException(new OCSBadRequestException('afgeronde transactie'));

        $this->assertSame(409, $this->controller->removeTender('txn-1', 'tender-1')->getStatus());
    }//end testRemoveTenderFromSettledReturns409()

    /**
     * Removing a tender the caller may not access surfaces as 403.
     *
     * @return void
     */
    public function testRemoveTenderForbiddenReturns403(): void
    {
        $this->login('intruder');
        $this->service->method('removeTender')
            ->willThrowException(new OCSForbiddenException('Geen toegang'));

        $this->assertSame(403, $this->controller->removeTender('txn-1', 'tender-1')->getStatus());
    }//end testRemoveTenderForbiddenReturns403()

    /**
     * A successful tender removal returns 204.
     *
     * @return void
     */
    public function testRemoveTenderSuccessReturns204(): void
    {
        $this->login('cashier');
        $this->service->expects($this->once())->method('removeTender');

        $this->assertSame(204, $this->controller->removeTender('txn-1', 'tender-1')->getStatus());
    }//end testRemoveTenderSuccessReturns204()
}//end class
