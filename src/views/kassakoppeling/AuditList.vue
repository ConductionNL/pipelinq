<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
-->
<template>
	<div>
		<CnIndexPage
			:title="t('pipelinq', 'Kassakoppeling audit')"
			:description="t('pipelinq', 'Append-only, cryptographically signed POS audit log for Belastingdienst compliance')"
			:schema="schema"
			:objects="objects"
			:pagination="pagination"
			:loading="loading"
			:sort-key="sortKey"
			:sort-order="sortOrder"
			:selectable="false"
			:include-columns="visibleColumns"
			:empty-title="t('pipelinq', 'No audit entries found')"
			@refresh="refresh"
			@sort="onSort"
			@row-click="openEntry"
			@page-changed="onPageChange">
			<template #actions>
				<div v-if="isManager" class="kassakoppeling-export">
					<NcSelect v-model="exportFormat"
						:options="formatOptions"
						:input-label="t('pipelinq', 'Export format')"
						:clearable="false"
						label="label"
						track-by="id" />
					<NcButton type="secondary"
						:disabled="exporting"
						@click="exportBelastingdienst">
						{{ t('pipelinq', 'Export for Belastingdienst') }}
					</NcButton>
				</div>
			</template>
		</CnIndexPage>
	</div>
</template>

<script>
import { inject } from 'vue'
import { NcButton, NcSelect } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'AuditList',
	components: {
		CnIndexPage,
		NcButton,
		NcSelect,
	},
	setup() {
		const sidebarState = inject('sidebarState', null)
		const objectStore = useObjectStore()
		return useListView('kassakoppelingAuditLog', { sidebarState, objectStore })
	},
	data() {
		return {
			exporting: false,
			exportFormat: { id: 'xml', label: 'XML' },
			formatOptions: [
				{ id: 'xml', label: 'XML' },
				{ id: 'json', label: 'JSON' },
			],
		}
	},
	computed: {
		/**
		 * Columns shown on the list, in order.
		 *
		 * @return {Array<string>} The column keys.
		 */
		visibleColumns() {
			return ['timestamp', 'operatorId', 'registerNumber', 'action', 'amount', 'verified']
		},
		/**
		 * Whether the current user may export (NC admins; server is authoritative).
		 *
		 * @return {boolean} Whether to show the export controls.
		 */
		isManager() {
			return typeof window.OC?.isUserAdmin === 'function' ? window.OC.isUserAdmin() : false
		},
	},
	methods: {
		/**
		 * Navigate to an entry's detail.
		 *
		 * @param {object} row The clicked row.
		 */
		openEntry(row) {
			this.$router.push({ name: 'KassakoppelingAuditDetail', params: { id: row.id } })
		},
		/**
		 * Download the Belastingdienst export in the selected format.
		 */
		async exportBelastingdienst() {
			this.exporting = true
			try {
				const format = this.exportFormat?.id || 'xml'
				const response = await fetch(
					generateUrl(`/apps/pipelinq/api/kassakoppeling/audit/export?format=${format}`),
					{
						method: 'GET',
						headers: {
							requesttoken: OC.requestToken,
							'OCS-APIREQUEST': 'true',
						},
					},
				)
				if (!response.ok) {
					const data = await response.json().catch(() => ({}))
					showError(data.error || t('pipelinq', 'Export failed.'))
					return
				}
				const blob = await response.blob()
				const url = window.URL.createObjectURL(blob)
				const link = document.createElement('a')
				link.href = url
				link.download = `kassakoppeling-export.${format}`
				document.body.appendChild(link)
				link.click()
				document.body.removeChild(link)
				window.URL.revokeObjectURL(url)
				showSuccess(t('pipelinq', 'Export downloaded.'))
			} catch (e) {
				showError(t('pipelinq', 'Export failed.'))
			} finally {
				this.exporting = false
			}
		},
	},
}
</script>

<style scoped>
.kassakoppeling-export {
	display: flex;
	align-items: flex-end;
	gap: 8px;
}
</style>
