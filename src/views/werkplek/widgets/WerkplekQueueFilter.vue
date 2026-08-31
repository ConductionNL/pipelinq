<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!-- @spec openspec/changes/pipelinq-werkplek-declarative/tasks.md#task-3 -->
<template>
	<div class="werkplek-queue-filter">
		<button
			type="button"
			class="werkplek-queue-filter__item"
			:class="{ 'werkplek-queue-filter__item--active': !selectedQueue }"
			@click="select('')">
			<span class="werkplek-queue-filter__label">{{
				t('pipelinq', 'All queues')
			}}</span>
			<span class="werkplek-queue-filter__count">{{ totalCount }}</span>
		</button>
		<button
			v-for="q in queues"
			:key="q.slug || q.id"
			type="button"
			class="werkplek-queue-filter__item"
			:class="{
				'werkplek-queue-filter__item--active':
					selectedQueue === (q.slug || q.id),
			}"
			@click="select(q.slug || q.id)">
			<span class="werkplek-queue-filter__label">{{ q.title }}</span>
			<span class="werkplek-queue-filter__count">{{
				queueCounts[q.slug] || 0
			}}</span>
		</button>
		<p
			v-if="!loading && queues.length === 0"
			class="werkplek-queue-filter__empty">
			{{ t('pipelinq', 'No queues configured') }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * WerkplekQueueFilter — a workspace widget that lists the agent's queues with
 * open-request counts and, on click, writes the chosen queue into the page-level
 * workspace context key `selectedQueue`. The Requests and Tasks `object-list`
 * widgets filter on `queue: "@workspace.selectedQueue?"` (optional token), so
 * picking a queue narrows both lists and "All queues" clears the filter.
 *
 * Queue data comes from the existing aggregated `GET /api/kcc-werkplek/state`
 * endpoint (queues + queueCounts), so no extra backend is needed.
 *
 * @spec openspec/changes/pipelinq-werkplek-declarative/tasks.md#task-3
 */
export default {
	name: 'WerkplekQueueFilter',

	inject: {
		/** Page workspace context (reactive ref) provided by CnDashboardPage. */
		cnWorkspaceContext: { default: null },
	},

	data() {
		return {
			queues: [],
			queueCounts: {},
			loading: false,
		}
	},

	computed: {
		/** The unwrapped workspace bag. */
		workspaceCtx() {
			const c = this.cnWorkspaceContext
			if (!c) return null
			return typeof c === 'object' && 'value' in c ? c.value : c
		},

		/** Currently-selected queue slug/id (empty = all). */
		selectedQueue() {
			return (this.workspaceCtx && this.workspaceCtx.selectedQueue) || ''
		},

		/** Sum of open-request counts across queues. */
		totalCount() {
			return Object.values(this.queueCounts).reduce(
				(a, b) => a + (Number(b) || 0),
				0,
			)
		},
	},

	created() {
		this.fetchQueues()
	},

	methods: {
		/**
		 * Fetch the queue list + counts from the workspace-state endpoint.
		 * Degrades silently to an empty list on failure.
		 *
		 * @return {Promise<void>}
		 */
		async fetchQueues() {
			this.loading = true
			try {
				const res = await axios.get(
					generateUrl('/apps/pipelinq/api/kcc-werkplek/state'),
				)
				const data = res.data || {}
				this.queues = Array.isArray(data.queues) ? data.queues : []
				this.queueCounts = data.queueCounts || {}
			} catch (e) {
				console.warn('[WerkplekQueueFilter] queue fetch failed', e)
				this.queues = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Write the chosen queue into the workspace context so the list widgets
		 * react. Empty string clears the filter.
		 *
		 * The Options-API `inject` auto-unwraps the page's provided `ref({})`,
		 * so `cnWorkspaceContext` is the plain reactive object here; write the key
		 * in place. The `.value` branch supports a raw-ref holder.
		 *
		 * @param {string} value Queue slug/id, or '' for all.
		 * @spec openspec/changes/pipelinq-werkplek-declarative/tasks.md#task-3
		 */
		select(value) {
			const holder = this.cnWorkspaceContext
			if (!holder || typeof holder !== 'object') return
			if ('value' in holder) {
				holder.value = {
					...(holder.value || {}),
					selectedQueue: value || '',
				}
				return
			}
			holder.selectedQueue = value || ''
		},
	},
}
</script>

<style scoped>
.werkplek-queue-filter {
	display: flex;
	flex-direction: column;
	gap: 4px;
	width: 100%;
}

.werkplek-queue-filter__item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
	font: inherit;
	text-align: left;
}

.werkplek-queue-filter__item:hover {
	background: var(--color-background-hover);
}

.werkplek-queue-filter__item--active {
	border-color: var(--color-primary-element);
	box-shadow: inset 3px 0 0 0 var(--color-primary-element);
}

.werkplek-queue-filter__count {
	background: var(--color-background-darker);
	padding: 1px 8px;
	border-radius: var(--border-radius-pill, 999px);
	font-size: 0.85em;
}

.werkplek-queue-filter__empty {
	color: var(--color-text-maxcontrast);
	font-style: italic;
	margin: 4px 0;
}
</style>
