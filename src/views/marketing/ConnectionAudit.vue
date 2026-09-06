<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.

  Connection audit (marketing-search-intelligence, phase 5): per client and
  network, whether we follow them and whether they follow us.

  THE THIRD ANSWER IS THE POINT. Only Mastodon and Bluesky publish a follower
  list an audit can read. Everywhere else the honest answer is "the network
  will not say", and the page renders that reason rather than a no. A no is
  something a marketer acts on, and it would be wrong about half the time.

  The page reads one collection. Asking each network per client while
  rendering is the fan-out pipelinq#1781 removed from the blast performance
  page, and it would be slower by a factor of the client list.

  @spec openspec/changes/marketing-search-intelligence/specs/marketing-analytics-connectors/spec.md#requirement-the-connection-audit-page-reads-one-collection-and-renders-the-reasons
-->
<template>
	<div class="connection-audit">
		<header class="connection-audit__header">
			<h2>{{ t('pipelinq', 'Connection audit') }}</h2>
			<NcButton :disabled="refreshing" @click="refresh">
				{{ t('pipelinq', 'Check again') }}
			</NcButton>
		</header>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-else-if="!rows.length"
			class="connection-audit__empty"
			data-testid="connection-audit-empty"
			:name="t('pipelinq', 'Nothing to compare yet')"
			:description="
				t(
					'pipelinq',
					'Connect a social account and give a client a handle on the same network. Only Mastodon and Bluesky publish a follower list an audit can read; for the other networks this page will say so rather than guess.',
				)
			">
			<template #icon>
				<AccountArrowRightOutline :size="20" />
			</template>
		</NcEmptyContent>

		<div v-else class="connection-audit__scroll">
			<table
				class="connection-audit__table"
				data-testid="connection-audit-table">
				<thead>
					<tr>
						<th scope="col">{{ t('pipelinq', 'Handle') }}</th>
						<th scope="col">{{ t('pipelinq', 'Network') }}</th>
						<th scope="col">{{ t('pipelinq', 'We follow them') }}</th>
						<th scope="col">{{ t('pipelinq', 'They follow us') }}</th>
						<th scope="col">{{ t('pipelinq', 'Reason') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in rows" :key="keyOf(row)">
						<td>
							<a
								v-if="row.counterpartUrl"
								:href="row.counterpartUrl"
								target="_blank"
								rel="noopener noreferrer">
								{{ row.counterpartHandle }}
							</a>
							<span v-else>{{ row.counterpartHandle }}</span>
						</td>
						<td>{{ row.network }}</td>
						<td :class="verdictClass(row.weFollowThem)">
							{{ verdictLabel(row.weFollowThem) }}
						</td>
						<td :class="verdictClass(row.theyFollowUs)">
							{{ verdictLabel(row.theyFollowUs) }}
						</td>
						<td class="connection-audit__reason">{{ row.reason }}</td>
					</tr>
				</tbody>
			</table>
		</div>

		<p v-if="error" class="connection-audit__error" role="alert">{{ error }}</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import AccountArrowRightOutline from 'vue-material-design-icons/AccountArrowRightOutline.vue'

export default {
	name: 'ConnectionAudit',
	components: {
		AccountArrowRightOutline,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
	},

	data() {
		return {
			loading: true,
			refreshing: false,
			error: '',
			rows: [],
		}
	},

	mounted() {
		this.fetchRows()
	},

	methods: {
		/**
		 * GET /api/marketing/connection-audit. One read serves the page.
		 *
		 * @param {boolean} refresh Whether to re-run the audit first.
		 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-analytics-connectors/spec.md#requirement-the-connection-audit-page-reads-one-collection-and-renders-the-reasons
		 */
		async fetchRows(refresh = false) {
			this.error = ''
			try {
				const url = generateUrl(
					'/apps/pipelinq/api/marketing/connection-audit',
				)
				const { data } = await axios.get(url, {
					params: { refresh: refresh ? 'true' : 'false' },
				})
				this.rows = data?.rows || []
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('pipelinq', 'Could not load the connection audit.')
			} finally {
				this.loading = false
				this.refreshing = false
			}
		},

		/**
		 * Re-run the audit against the networks that will answer.
		 *
		 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-analytics-connectors/spec.md#requirement-the-connection-audit-answers-only-what-an-api-will-say
		 */
		async refresh() {
			this.refreshing = true
			await this.fetchRows(true)
		},

		/**
		 * @param {object} row An audit row.
		 * @return {string} A stable key for the row.
		 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-analytics-connectors/spec.md#requirement-the-connection-audit-page-reads-one-collection-and-renders-the-reasons
		 */
		keyOf(row) {
			return `${row?.network || ''}:${row?.counterpartHandle || ''}`
		},

		/**
		 * Yes, no, or the fact that the network will not say. Unknown is
		 * never rendered as a no.
		 *
		 * @param {string} verdict The stored verdict.
		 * @return {string}
		 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-analytics-connectors/spec.md#requirement-the-connection-audit-answers-only-what-an-api-will-say
		 */
		verdictLabel(verdict) {
			const labels = {
				yes: this.t('pipelinq', 'Yes'),
				no: this.t('pipelinq', 'No'),
				unknown: this.t('pipelinq', 'Cannot be checked'),
			}
			return labels[verdict] || labels.unknown
		},

		/**
		 * @param {string} verdict The stored verdict.
		 * @return {string} The class for the cell.
		 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-analytics-connectors/spec.md#requirement-the-connection-audit-answers-only-what-an-api-will-say
		 */
		verdictClass(verdict) {
			if (verdict === 'unknown' || !verdict) {
				return 'connection-audit__verdict connection-audit__verdict--unknown'
			}
			return 'connection-audit__verdict'
		},
	},
}
</script>

<style scoped lang="scss">
.connection-audit {
	padding: 20px;
}

.connection-audit__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
	flex-wrap: wrap;
	margin-bottom: 16px;
}

.connection-audit__scroll {
	overflow-x: auto;
}

.connection-audit__table {
	width: 100%;
	border-collapse: collapse;

	th,
	td {
		padding: 8px 12px;
		border-bottom: 1px solid var(--color-border);
		text-align: start;
	}

	th {
		font-weight: bold;
	}
}

.connection-audit__verdict--unknown,
.connection-audit__reason {
	color: var(--color-text-maxcontrast);
}

.connection-audit__error {
	color: var(--color-error);
	margin-top: 12px;
}
</style>
