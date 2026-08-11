<?php

/**
 * Unit tests for LoyaltyController gift-card issuance.
 *
 * Regression guard for the money bug where GiftCardService::issueGiftCard was
 * fully implemented but had no route or controller entry point, so no gift
 * card could ever be created while activate/redeem/validate operated on an
 * empty population.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\LoyaltyController;
use OCA\Pipelinq\Service\GiftCardService;
use OCA\Pipelinq\Service\LoyaltyAccountService;
use OCA\Pipelinq\Service\LoyaltyProgrammeService;
use OCA\Pipelinq\Service\PointsLedgerService;
use OCA\Pipelinq\Service\RedemptionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LoyaltyController::issueGiftCard.
 */
class LoyaltyControllerTest extends TestCase
{
    /**
     * Build the controller with a programmed GiftCardService mock and request params.
     *
     * @param GiftCardService      $giftCardService The (pre-programmed) gift card service.
     * @param array<string, mixed> $params          Request params.
     * @param bool                 $authenticated   Whether a user session is present.
     *
     * @return LoyaltyController
     */
    private function build(GiftCardService $giftCardService, array $params, bool $authenticated=true): LoyaltyController
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            static function (string $key, mixed $default=null) use ($params) {
                return ($params[$key] ?? $default);
            }
        );

        $userSession = $this->createMock(IUserSession::class);
        if ($authenticated === true) {
            $userSession->method('getUser')->willReturn($this->createMock(IUser::class));
        } else {
            $userSession->method('getUser')->willReturn(null);
        }

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        return new LoyaltyController(
            $request,
            $this->createMock(LoyaltyAccountService::class),
            $this->createMock(PointsLedgerService::class),
            $this->createMock(RedemptionService::class),
            $giftCardService,
            $this->createMock(LoyaltyProgrammeService::class),
            $userSession,
            $l10n
        );
    }//end build()

    /**
     * POST /api/loyalty/gift-card/issue mints a card and returns it with the one-time PIN.
     *
     * @return void
     */
    public function testIssueGiftCardCreatesCardAndReturnsPin(): void
    {
        $giftCardService = $this->createMock(GiftCardService::class);
        $giftCardService->expects($this->once())
            ->method('issueGiftCard')
            ->with(null, 50.0, 365, 'purchased', 'Bram')
            ->willReturn(
                [
                    'card' => ['@self' => ['id' => 'gc-1'], 'serial' => 'GC-00000042', 'status' => 'issued', 'currentBalance' => 50.0],
                    'pin'  => '123456',
                ]
            );

        $controller = $this->build(
            giftCardService: $giftCardService,
            params: [
                'initialBalance' => '50',
                'issuedTo'       => 'Bram',
            ]
        );

        $response = $controller->issueGiftCard();
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('123456', $data['pin']);
        $this->assertSame('GC-00000042', $data['card']['serial']);
        $this->assertSame('issued', $data['card']['status']);
    }//end testIssueGiftCardCreatesCardAndReturnsPin()

    /**
     * A non-positive initialBalance is rejected with 400 and never touches the service.
     *
     * @return void
     */
    public function testIssueGiftCardRejectsNonPositiveBalance(): void
    {
        $giftCardService = $this->createMock(GiftCardService::class);
        $giftCardService->expects($this->never())->method('issueGiftCard');

        $controller = $this->build(
            giftCardService: $giftCardService,
            params: ['initialBalance' => '0']
        );

        $response = $controller->issueGiftCard();
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testIssueGiftCardRejectsNonPositiveBalance()

    /**
     * An unauthenticated caller is rejected with 401 before any issuance.
     *
     * @return void
     */
    public function testIssueGiftCardRequiresAuthentication(): void
    {
        $giftCardService = $this->createMock(GiftCardService::class);
        $giftCardService->expects($this->never())->method('issueGiftCard');

        $controller = $this->build(
            giftCardService: $giftCardService,
            params: ['initialBalance' => '50'],
            authenticated: false
        );

        $response = $controller->issueGiftCard();
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testIssueGiftCardRequiresAuthentication()
}//end class
