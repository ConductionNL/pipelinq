// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// Thin frontend API client for the StUF-ZKN/BG adapter. All endpoints are
// documented in lib/Controller/StufController.php and routed by appinfo/routes.php
// (see the "StUF-ZKN/BG adapter" block).

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = (path) => generateUrl('/apps/pipelinq' + path)

export async function listEndpoints() {
	const { data } = await axios.get(base('/api/stuf/endpoints'))
	return data
}

export async function listMessages(filters = {}) {
	const { data } = await axios.get(base('/api/stuf/messages'), { params: filters })
	return data
}

export async function sendVrijBericht(endpointId, berichtNaam, payload) {
	const { data } = await axios.post(base('/api/stuf/outbound'), {
		endpointId,
		berichtNaam,
		payload,
	})
	return data
}

export default { listEndpoints, listMessages, sendVrijBericht }
