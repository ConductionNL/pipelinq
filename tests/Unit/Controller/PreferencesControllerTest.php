<?php

/**
 * Unit tests for PreferencesController.
 *
 * Verifies the wire contract of the generic per-user preference endpoints:
 *   - Both actions demand an authenticated user (401 otherwise).
 *   - The stored key is ALWAYS namespaced to the calling user's UID and to the
 *     `pref_` prefix, so a caller cannot address another user's preference nor
 *     an app-config key by choosing the `{key}` path segment.
 *   - A key that sanitises to nothing is rejected with 400.
 *   - The response body is the documented `{value: string|null}` envelope.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\PreferencesController;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PreferencesController.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class PreferencesControllerTest extends TestCase
{

    private PreferencesController $controller;

    /**
     * @var IConfig&MockObject
     */
    private IConfig $config;

    /**
     * @var IUserSession&MockObject
     */
    private IUserSession $session;

    protected function setUp(): void
    {
        $this->config  = $this->createMock(IConfig::class);
        $this->session = $this->createMock(IUserSession::class);

        $this->controller = new PreferencesController(
            $this->createMock(IRequest::class),
            $this->config,
            $this->session,
        );
    }//end setUp()

    /**
     * Make the session resolve to a user.
     *
     * @param string $uid The user id.
     *
     * @return void
     */
    private function loginAs(string $uid='alice'): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->session->method('getUser')->willReturn($user);
    }//end loginAs()

    // ---- getPreference -----------------------------------------------------

    /**
     * @return void
     */
    public function testGetPreferenceRequiresAuthentication(): void
    {
        $this->session->method('getUser')->willReturn(null);
        $this->config->expects($this->never())->method('getUserValue');

        $response = $this->controller->getPreference(key: 'support-seen');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame('Not logged in', $response->getData()['message']);
    }//end testGetPreferenceRequiresAuthentication()

    /**
     * @return void
     */
    public function testGetPreferenceReturnsStoredValue(): void
    {
        $this->loginAs();
        $this->config->method('getUserValue')->willReturn('2026-08-11');

        $response = $this->controller->getPreference(key: 'support-seen');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['value' => '2026-08-11'], $response->getData());
    }//end testGetPreferenceReturnsStoredValue()

    /**
     * An unset preference reads back as an explicit null, not an empty string.
     *
     * @return void
     */
    public function testGetPreferenceReturnsNullWhenUnset(): void
    {
        $this->loginAs();
        $this->config->method('getUserValue')->willReturn('');

        $response = $this->controller->getPreference(key: 'support-seen');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertArrayHasKey('value', $response->getData());
        $this->assertNull($response->getData()['value']);
    }//end testGetPreferenceReturnsNullWhenUnset()

    /**
     * The read is scoped to the SESSION user and to the `pref_` namespace —
     * the client-supplied key can never select another user's value nor an
     * unprefixed Nextcloud user value.
     *
     * @return void
     */
    public function testGetPreferenceIsScopedToCallingUserAndPrefNamespace(): void
    {
        $this->loginAs(uid: 'alice');

        $this->config->expects($this->once())
            ->method('getUserValue')
            ->with('alice', 'pipelinq', 'pref_supportseen', '')
            ->willReturn('yes');

        $response = $this->controller->getPreference(key: 'support_seen');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('yes', $response->getData()['value']);
    }//end testGetPreferenceIsScopedToCallingUserAndPrefNamespace()

    /**
     * A traversal-shaped key cannot escape the `pref_` namespace: the unsafe
     * characters are stripped and the remainder is still prefixed.
     *
     * @return void
     */
    public function testGetPreferenceCannotEscapeThePrefNamespace(): void
    {
        $this->loginAs(uid: 'alice');

        $observed = '';
        $this->config->method('getUserValue')
            ->willReturnCallback(
                function (string $userId, string $appName, string $key, string $default) use (&$observed): string {
                    $observed = $key;
                    return $default;
                }
            );

        $this->controller->getPreference(key: '../../core/lastupdatedat');

        $this->assertStringStartsWith('pref_', $observed);
        $this->assertSame('pref_corelastupdatedat', $observed);
    }//end testGetPreferenceCannotEscapeThePrefNamespace()

    /**
     * @return void
     */
    public function testGetPreferenceRejectsKeyWithNoSafeCharacters(): void
    {
        $this->loginAs();
        $this->config->expects($this->never())->method('getUserValue');

        $response = $this->controller->getPreference(key: '///');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('Invalid key', $response->getData()['message']);
    }//end testGetPreferenceRejectsKeyWithNoSafeCharacters()

    // ---- setPreference -----------------------------------------------------

    /**
     * @return void
     */
    public function testSetPreferenceRequiresAuthentication(): void
    {
        $this->session->method('getUser')->willReturn(null);
        $this->config->expects($this->never())->method('setUserValue');

        $response = $this->controller->setPreference(key: 'support-seen', value: 'x');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame('Not logged in', $response->getData()['message']);
    }//end testSetPreferenceRequiresAuthentication()

    /**
     * @return void
     */
    public function testSetPreferenceRejectsKeyWithNoSafeCharacters(): void
    {
        $this->loginAs();
        $this->config->expects($this->never())->method('setUserValue');

        $response = $this->controller->setPreference(key: '@@@', value: 'x');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('Invalid key', $response->getData()['message']);
    }//end testSetPreferenceRejectsKeyWithNoSafeCharacters()

    /**
     * The write lands on the CALLING user's value only.
     *
     * @return void
     */
    public function testSetPreferenceWritesOnlyToTheCallingUsersValue(): void
    {
        $this->loginAs(uid: 'alice');

        $this->config->expects($this->once())
            ->method('setUserValue')
            ->with('alice', 'pipelinq', 'pref_support-seen', 'yes');

        $response = $this->controller->setPreference(key: 'support-seen', value: 'yes');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['value' => 'yes'], $response->getData());
    }//end testSetPreferenceWritesOnlyToTheCallingUsersValue()

    /**
     * A key that collides with an app-config key still writes a per-USER
     * value under the `pref_` prefix — never the instance-wide app config.
     *
     * @return void
     */
    public function testSetPreferenceCannotReachAnAppConfigKey(): void
    {
        $this->loginAs(uid: 'mallory');

        $this->config->expects($this->never())->method('setAppValue');
        $this->config->expects($this->once())
            ->method('setUserValue')
            ->with('mallory', 'pipelinq', 'pref_register', 'hijacked');

        $response = $this->controller->setPreference(key: 'register', value: 'hijacked');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('hijacked', $response->getData()['value']);
    }//end testSetPreferenceCannotReachAnAppConfigKey()

    /**
     * @return void
     */
    public function testSetPreferenceWithEmptyValueDeletesAndReturnsNull(): void
    {
        $this->loginAs(uid: 'alice');

        $this->config->expects($this->once())
            ->method('deleteUserValue')
            ->with('alice', 'pipelinq', 'pref_support-seen');
        $this->config->expects($this->never())->method('setUserValue');

        $response = $this->controller->setPreference(key: 'support-seen', value: '');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertArrayHasKey('value', $response->getData());
        $this->assertNull($response->getData()['value']);
    }//end testSetPreferenceWithEmptyValueDeletesAndReturnsNull()

    /**
     * Documents the observable consequence of stripping (rather than
     * rejecting) unsafe characters: two DIFFERENT client keys are stored under
     * ONE storage key, so the second write silently overwrites the first.
     *
     * @return void
     */
    public function testDistinctClientKeysCollapseOntoOneStorageKey(): void
    {
        $this->loginAs(uid: 'alice');

        $keys = [];
        $this->config->method('setUserValue')
            ->willReturnCallback(
                function (string $userId, string $appName, string $key, string $value) use (&$keys): void {
                    $keys[] = $key;
                }
            );

        $this->controller->setPreference(key: 'a.b', value: 'first');
        $this->controller->setPreference(key: 'a/b', value: 'second');

        $this->assertSame(['pref_ab', 'pref_ab'], $keys);
    }//end testDistinctClientKeysCollapseOntoOneStorageKey()
}//end class
