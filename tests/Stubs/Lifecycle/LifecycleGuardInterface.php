<?php

/**
 * Test stub for OpenRegister's LifecycleGuardInterface.
 *
 * Mirrors the real OCA\OpenRegister\Lifecycle\LifecycleGuardInterface so
 * pipelinq's lifecycle guards can be type-checked and unit-tested without the
 * openregister app installed. Guarded with interface_exists() in
 * tests/bootstrap.php so the real interface wins when OpenRegister is present.
 *
 * @category Test
 * @package  OCA\OpenRegister\Lifecycle
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

namespace OCA\OpenRegister\Lifecycle;

/**
 * Apps implement this interface to authorise a lifecycle transition.
 */
interface LifecycleGuardInterface
{
    /**
     * Authorise (or deny) a transition.
     *
     * @param array<string, mixed> $object The loaded object payload at its current state.
     * @param string               $action The transition action being applied.
     * @param string               $userId The uid of the caller.
     *
     * @return GuardResult Allow or deny + optional message.
     */
    public function check(array $object, string $action, string $userId): GuardResult;
}//end interface
