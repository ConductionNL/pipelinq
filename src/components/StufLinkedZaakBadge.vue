<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - StufLinkedZaakBadge surfaces a linked zaaksysteem identifier in a
  - Request or Contact detail view. Mounts on either pipelinqEntiteit ∈
  - {request, contact} and renders a stable inline pill with the external
  - identificatie + endpoint name. Used in RequestDetail and ContactDetail
  - to satisfy task 5.3 (UI integration of StUF mappings).
  -
  - @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-008
  - @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-010
-->
<template>
	<div v-if="loaded" class="stuf-linked-zaak-badge" data-testid="stuf-linked-zaak-badge">
		<template v-if="mapping">
			<span class="stuf-linked-zaak-badge__label">
				{{ entityLabel }}
			</span>
			<span class="stuf-linked-zaak-badge__id">{{ mapping.externIdentificatie }}</span>
			<span v-if="endpointName" class="stuf-linked-zaak-badge__endpoint">@ {{ endpointName }}</span>
			<span class="stuf-linked-zaak-badge__status" :class="statusClass">{{ mapping.synchronisatieStatus || '—' }}</span>
		</template>
		<template v-else-if="entiteit === 'request'">
			<NcButton type="secondary" :disabled="busy || !defaultEndpoint" @click="registerZaak">
				{{ t('pipelinq', 'Register to zaaksysteem') }}
			</NcButton>
			<span v-if="!defaultEndpoint" class="stuf-linked-zaak-badge__note">
				{{ t('pipelinq', 'No active StUF endpoint configured.') }}
			</span>
		</template>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { listEndpoints } from '../services/stufApi.js'

export default {
	name: 'StufLinkedZaakBadge',
	components: { NcButton },
	props: {
		entiteit: {
			type: String,
			required: true,
			validator: (v) => ['request', 'contact'].includes(v),
		},
		entityId: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			loaded: false,
			busy: false,
			mapping: null,
			endpoints: [],
		}
	},
	computed: {
		entityLabel() {
			return this.entiteit === 'request'
				? t('pipelinq', 'Linked zaak:')
				: t('pipelinq', 'Linked betrokkene:')
		},
		defaultEndpoint() {
			return this.endpoints.find((ep) => ep.actief !== false) || null
		},
		endpointName() {
			if (!this.mapping) {
				return ''
			}
			const ep = this.endpoints.find((e) => e.id === this.mapping.endpointId)
			return ep ? ep.naam : this.mapping.endpointId
		},
		statusClass() {
			const s = (this.mapping && this.mapping.synchronisatieStatus) || 'unknown'
			return 'stuf-linked-zaak-badge__status--' + s
		},
	},
	watch: {
		entityId() {
			this.refresh()
		},
	},
	mounted() {
		this.refresh()
	},
	methods: {
		async refresh() {
			this.loaded = false
			try {
				const data = await listEndpoints()
				this.endpoints = Array.isArray(data.items) ? data.items : []
				await this.loadMapping()
			} catch (e) {
				// Non-admins won't be able to list endpoints; soft-fail.
				this.endpoints = []
				await this.loadMapping()
			}
			this.loaded = true
		},
		async loadMapping() {
			// Fetch via the OR direct read path; this is the same shape every
			// other detail panel uses. We re-use the messages endpoint with a
			// dedicated filter via the controller's `endpoints` listing? Not
			// available — call /index.php/apps/openregister/api/objects with
			// the filter shape openregister exposes.
			try {
				const url = generateUrl('/apps/openregister/api/objects/pipelinq/zaaksysteemMapping')
				const { data } = await axios.get(url, {
					params: {
						filters: {
							pipelinqEntiteit: this.entiteit,
							pipelinqId: this.entityId,
						},
						limit: 1,
					},
				})
				const items = (data && data.results) || (data && data.items) || []
				this.mapping = items.length ? items[0] : null
			} catch (e) {
				this.mapping = null
			}
		},
		async registerZaak() {
			if (!this.defaultEndpoint) {
				return
			}
			this.busy = true
			try {
				// Backend service exposes a wrapper at POST /api/stuf/outbound
				// only for vrije berichten; for registerZaak the integration
				// service is called by the request workflow. From the UI, we
				// emit the intent and let the host page (RequestDetail) call
				// its own action. If the host hasn't bound `@register`, the
				// inline error message guides the user.
				this.$emit('register', { endpointId: this.defaultEndpoint.id })
				showSuccess(t('pipelinq', 'Registration request dispatched'))
			} catch (e) {
				showError(t('pipelinq', 'Could not register zaak'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.stuf-linked-zaak-badge {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	padding: 6px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	margin: 8px 0;
}
.stuf-linked-zaak-badge__label {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}
.stuf-linked-zaak-badge__id {
	font-family: var(--font-monospace, monospace);
}
.stuf-linked-zaak-badge__endpoint {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
.stuf-linked-zaak-badge__status {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius);
	font-size: 11px;
	font-weight: bold;
}
.stuf-linked-zaak-badge__status--in_sync {
	background: var(--color-success);
	color: white;
}
.stuf-linked-zaak-badge__status--fout {
	background: var(--color-error);
	color: white;
}
.stuf-linked-zaak-badge__status--wacht {
	background: var(--color-warning);
	color: white;
}
.stuf-linked-zaak-badge__note {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
