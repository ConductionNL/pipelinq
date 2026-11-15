<?php

/**
 * Pipelinq WritableAddressBook.
 *
 * Whether an address book accepts card updates.
 *
 * @category Util
 * @package  OCA\Pipelinq\Util
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
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Util;

use OCP\Constants;
use OCP\IAddressBook;

/**
 * A system address book never does; any other does when it grants the update
 * permission.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait WritableAddressBook {

	/**
	 * Whether pipelinq may write cards in this addressbook.
	 *
	 * @param IAddressBook $addressBook The addressbook.
	 *
	 * @return bool True for a writable, non-system addressbook.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function isWritable(IAddressBook $addressBook): bool {
		if ($addressBook->isSystemAddressBook() === true) {
			return false;
		}

		return ($addressBook->getPermissions() & Constants::PERMISSION_UPDATE) !== 0;
	}//end isWritable()
}//end trait
