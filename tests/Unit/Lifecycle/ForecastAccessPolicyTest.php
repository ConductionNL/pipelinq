<?php

/**
 * Unit tests for ForecastAccessPolicy.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Lifecycle
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

namespace OCA\Pipelinq\Tests\Unit\Lifecycle;

use OCA\Pipelinq\Lifecycle\ForecastAccessPolicy;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for forecast scope enforcement (read / override / quota).
 */
class ForecastAccessPolicyTest extends TestCase
{
    /**
     * Build a policy where the given uids are managers (members of "managers").
     *
     * @param array<string, bool> $admins   Map uid => isAdmin.
     * @param array<int, string>  $managers Manager-group member uids.
     *
     * @return ForecastAccessPolicy The configured policy.
     */
    private function policy(array $admins, array $managers): ForecastAccessPolicy
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn('managers');

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturnCallback(static fn(string $u): bool => ($admins[$u] ?? false));
        $groupManager->method('isInGroup')->willReturnCallback(
            static fn(string $u, string $g): bool => $g === 'managers' && in_array($u, $managers, true)
        );

        return new ForecastAccessPolicy(appConfig: $appConfig, groupManager: $groupManager);
    }//end policy()

    /**
     * A rep can read only their own rep-level forecast.
     *
     * @return void
     */
    public function testRepCanReadOwnOnly(): void
    {
        $policy = $this->policy([], []);
        $this->assertTrue($policy->canRead('john.doe', 'rep', 'john.doe'));
        $this->assertFalse($policy->canRead('john.doe', 'rep', 'jane.smith'));
        $this->assertFalse($policy->canRead('john.doe', 'team', 'sales-east'));
        $this->assertFalse($policy->canRead('john.doe', 'company', null));
    }//end testRepCanReadOwnOnly()

    /**
     * A manager can read at any level.
     *
     * @return void
     */
    public function testManagerCanReadAnyLevel(): void
    {
        $policy = $this->policy([], ['alice']);
        $this->assertTrue($policy->canRead('alice', 'company', null));
        $this->assertTrue($policy->canRead('alice', 'rep', 'john.doe'));
    }//end testManagerCanReadAnyLevel()

    /**
     * Only managers can override, and never at company level.
     *
     * @return void
     */
    public function testOverridePermission(): void
    {
        $policy = $this->policy([], ['alice']);
        $this->assertTrue($policy->canOverride('alice', 'rep'));
        $this->assertTrue($policy->canOverride('alice', 'division'));
        $this->assertFalse($policy->canOverride('alice', 'company'));
        $this->assertFalse($policy->canOverride('john.doe', 'rep'));
    }//end testOverridePermission()

    /**
     * Admins are always managers.
     *
     * @return void
     */
    public function testAdminIsManager(): void
    {
        $policy = $this->policy(['root' => true], []);
        $this->assertTrue($policy->isManager('root'));
        $this->assertTrue($policy->canSetQuota('root', 'team'));
    }//end testAdminIsManager()

    /**
     * An empty user id fails closed.
     *
     * @return void
     */
    public function testEmptyUserFailsClosed(): void
    {
        $policy = $this->policy([], ['alice']);
        $this->assertFalse($policy->isManager(''));
        $this->assertFalse($policy->canRead('', 'rep', ''));
    }//end testEmptyUserFailsClosed()
}//end class
