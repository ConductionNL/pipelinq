<?php

/**
 * Unit tests for EmailSyncController.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\BackgroundJob\EmailMatchJob;
use OCA\Pipelinq\Controller\EmailSyncController;
use OCA\Pipelinq\Service\EmailMatchService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the per-user email settings controller (auth + validation).
 */
class EmailSyncControllerTest extends TestCase
{

    /**
     * The request mock.
     *
     * @var IRequest&MockObject
     */
    private IRequest $request;

    /**
     * The matching service mock.
     *
     * @var EmailMatchService&MockObject
     */
    private EmailMatchService $matchService;

    /**
     * The matching job mock.
     *
     * @var EmailMatchJob&MockObject
     */
    private EmailMatchJob $matchJob;

    /**
     * The user session mock.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession $userSession;

    /**
     * Set up the mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request      = $this->createMock(IRequest::class);
        $this->matchService = $this->createMock(EmailMatchService::class);
        $this->matchJob     = $this->createMock(EmailMatchJob::class);
        $this->userSession  = $this->createMock(IUserSession::class);
    }//end setUp()

    /**
     * Build the controller, optionally authenticated as the given user.
     *
     * @param string|null $uid The authenticated uid, or null for anonymous.
     *
     * @return EmailSyncController The controller.
     */
    private function controller(?string $uid='alice'): EmailSyncController
    {
        if ($uid !== null) {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
            $this->userSession->method('getUser')->willReturn($user);
        } else {
            $this->userSession->method('getUser')->willReturn(null);
        }

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        return new EmailSyncController(
            $this->request,
            $this->matchService,
            $this->matchJob,
            $this->userSession,
            $l10n,
            $this->createMock(LoggerInterface::class)
        );
    }//end controller()

    /**
     * getSettings returns 200 with the user's settings when authenticated.
     *
     * @return void
     */
    public function testGetSettingsReturns200(): void
    {
        $this->matchService->method('isSyncEnabled')->willReturn(true);
        $this->matchService->method('getSyncAccount')->willReturn(5);
        $this->matchService->method('getExcludedAddresses')->willReturn([]);

        $response = $this->controller()->getSettings();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($response->getData()['enabled']);
    }//end testGetSettingsReturns200()

    /**
     * getSettings returns 401 when unauthenticated.
     *
     * @return void
     */
    public function testGetSettingsReturns401(): void
    {
        $response = $this->controller(uid: null)->getSettings();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testGetSettingsReturns401()

    /**
     * saveSettings returns 200 on valid input.
     *
     * @return void
     */
    public function testSaveSettingsReturns200(): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static function (string $key, $default=null) {
                return match ($key) {
                    'enabled'           => true,
                    'account'           => 5,
                    'excludedAddresses' => ['noreply@example.com'],
                    default             => $default,
                };
            }
        );
        $this->matchService->method('getExcludedAddresses')->willReturn(['noreply@example.com']);

        $response = $this->controller()->saveSettings();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testSaveSettingsReturns200()

    /**
     * saveSettings returns 401 when unauthenticated.
     *
     * @return void
     */
    public function testSaveSettingsReturns401(): void
    {
        $response = $this->controller(uid: null)->saveSettings();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testSaveSettingsReturns401()

    /**
     * saveSettings returns 400 when the enabled flag is not a boolean.
     *
     * @return void
     */
    public function testSaveSettingsReturns400OnInvalidEnabled(): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static function (string $key, $default=null) {
                return ($key === 'enabled') ? 'yes' : $default;
            }
        );

        $response = $this->controller()->saveSettings();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testSaveSettingsReturns400OnInvalidEnabled()

    /**
     * saveSettings returns 400 when an excluded address is not a string.
     *
     * @return void
     */
    public function testSaveSettingsReturns400OnInvalidExcluded(): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static function (string $key, $default=null) {
                return match ($key) {
                    'enabled'           => true,
                    'account'           => null,
                    'excludedAddresses' => [123],
                    default             => $default,
                };
            }
        );

        $response = $this->controller()->saveSettings();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testSaveSettingsReturns400OnInvalidExcluded()

    /**
     * trigger returns 200 and the resulting status when authenticated.
     *
     * @return void
     */
    public function testTriggerReturns200(): void
    {
        $this->matchJob->expects($this->once())->method('runForUser')->with('alice');
        $this->matchService->method('getStatus')->willReturn(['lastRun' => null, 'linked' => 0, 'error' => null]);

        $response = $this->controller()->trigger();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testTriggerReturns200()

    /**
     * trigger returns 401 when unauthenticated.
     *
     * @return void
     */
    public function testTriggerReturns401(): void
    {
        $response = $this->controller(uid: null)->trigger();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testTriggerReturns401()

    /**
     * status returns 200 when authenticated.
     *
     * @return void
     */
    public function testStatusReturns200(): void
    {
        $this->matchService->method('getStatus')->willReturn(['lastRun' => null, 'linked' => 0, 'error' => null]);

        $response = $this->controller()->status();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testStatusReturns200()

    /**
     * status returns 401 when unauthenticated.
     *
     * @return void
     */
    public function testStatusReturns401(): void
    {
        $response = $this->controller(uid: null)->status();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testStatusReturns401()
}//end class
