/**
 * useClickToDial — originate an outbound call from any phone-number field.
 *
 * Delegates to the server-authoritative, session-authenticated endpoint
 * `POST /apps/pipelinq/api/cti/click-to-dial` (CtiController::clickToDial).
 * The server loads the configured adapter, enforces that the agent is not
 * already on a call (presence guard), normalises the target number, and
 * creates the pending contactmoment — the browser never talks to the
 * telephony platform directly.
 *
 * Returns a `{ dialing, dial }` pair. `dial(extension, targetNumber)` resolves
 * to one of `'initiated' | 'busy' | 'unconfigured' | 'failed'`.
 *
 * @spec openspec/changes/cti-screenpop-adapter/specs/cti-screenpop-adapter/spec.md
 */

import { ref } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'

/**
 * Provide a click-to-dial action.
 *
 * @return {{ dialing: import('vue').Ref<boolean>, dial: Function }} The composable API.
 */
export function useClickToDial() {
	const dialing = ref(false)

	/**
	 * Originate an outbound call.
	 *
	 * @param {string} extension The agent's extension.
	 * @param {string} targetNumber The number to dial.
	 * @return {Promise<string>} The outcome status.
	 */
	async function dial(extension, targetNumber) {
		if (!extension || !targetNumber) {
			showError(t('pipelinq', 'Extension and number are required to dial'))
			return 'failed'
		}

		dialing.value = true
		try {
			const response = await axios.post(
				generateUrl('/apps/pipelinq/api/cti/click-to-dial'),
				{ extension, targetNumber },
			)
			showSuccess(response.data?.message || t('pipelinq', 'Call initiated'))
			return 'initiated'
		} catch (error) {
			const status = error?.response?.status
			if (status === 409) {
				showError(t('pipelinq', 'Cannot initiate call while on another call'))
				return 'busy'
			}
			if (status === 400) {
				showError(t('pipelinq', 'Click-to-dial is not configured'))
				return 'unconfigured'
			}
			showError(t('pipelinq', 'Call could not be initiated'))
			return 'failed'
		} finally {
			dialing.value = false
		}
	}

	return { dialing, dial }
}
