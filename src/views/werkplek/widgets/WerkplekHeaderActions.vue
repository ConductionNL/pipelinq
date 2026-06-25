<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!-- @spec openspec/changes/pipelinq-werkplek-declarative/tasks.md#task-3 -->
<template>
	<div class="werkplek-header-actions">
		<WerkplekAgentStatus
			:is-available="isAvailable"
			@update:isAvailable="isAvailable = $event" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import WerkplekAgentStatus from '../../../components/werkplek/WerkplekAgentStatus.vue'

/**
 * WerkplekHeaderActions — the workspace dashboard's `actionsComponent`,
 * rendering the agent availability toggle in the standard page header
 * (`#header-actions`). Loads the initial availability from the aggregated
 * `GET /api/kcc-werkplek/state` endpoint; the toggle itself PUTs changes.
 *
 * @spec openspec/changes/pipelinq-werkplek-declarative/tasks.md#task-3
 */
export default {
	name: 'WerkplekHeaderActions',

	components: { WerkplekAgentStatus },

	data() {
		return {
			isAvailable: false,
		}
	},

	created() {
		this.fetchAvailability()
	},

	methods: {
		/**
		 * Load the agent's current availability so the toggle reflects state on
		 * first paint. Failures leave it at the default (unavailable).
		 *
		 * @return {Promise<void>}
		 */
		async fetchAvailability() {
			try {
				const res = await axios.get(generateUrl('/apps/pipelinq/api/kcc-werkplek/state'))
				const profile = (res.data && res.data.agentProfile) || {}
				this.isAvailable = Boolean(profile.isAvailable)
			} catch (e) {
				// eslint-disable-next-line no-console
				console.warn('[WerkplekHeaderActions] availability fetch failed', e)
			}
		},
	},
}
</script>

<style scoped>
.werkplek-header-actions {
	display: flex;
	align-items: center;
	gap: 8px;
}
</style>
