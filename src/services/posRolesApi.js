// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// Frontend read of the till roles the POS staff admin offers.
// The endpoint is documented in lib/Controller/PosRoleController.php.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The till roles, or none when the answer has none or the request fails.
 *
 * @return {Promise<Array<object>>} The roles.
 * @spec exclude POS roles have no owning requirement, see PinLoginModal::loadStaff
 */
export async function fetchPosRoles() {
	try {
		const response = await axios.get(generateUrl('/apps/pipelinq/api/pos/roles'))
		return response?.data?.roles || []
	} catch {
		return []
	}
}
