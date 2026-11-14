<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Test/analysis stub for OpenRegister's AppHost StoreActionAuthorizer. It
 * admits nobody, so a test that forgets to say who may publish fails closed.
 *
 * @category Test
 * @package  OCA\OpenRegister\AppHost\Store
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Store;

use OCA\OpenRegister\AppHost\Service\StoreDescriptor;
use OCP\IUser;

/**
 * Stub of OpenRegister's StoreActionAuthorizer.
 */
class StoreActionAuthorizer {
	/**
	 * Whether the user may publish to the descriptor's store.
	 *
	 * @param StoreDescriptor $descriptor The calling app's store parameters.
	 * @param IUser           $user       The signed-in user.
	 *
	 * @return bool
	 */
	public function canPublish(StoreDescriptor $descriptor, IUser $user): bool {
		return false;
	}//end canPublish()
}//end class
