<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Posts ranked by engagement rate per network.
  -
  - THE PAGE RENDERS BEFORE THE NUMBERS ARRIVE. pipelinq#1781 fixed a
  - performance page that awaited a per-object fan-out before it painted
  - anything, and this one does not repeat it: the heading and the table
  - shell are in the template unconditionally, one request fills the rows, and
  - nothing here walks a publication to fetch its account. The follower count
  - each rate divides by was copied onto the publication's ranking row by the
  - daily pull, server-side, so the page never asks a second question.
  -
  - The rate rather than the raw count is the point. A company page with 900
  - followers and a spokesperson with 4,000 are not comparable on likes, and
  - an account with no followers recorded shows no rate rather than a zero
  - that would read as "nobody engaged".
  -
  - @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-posts-are-ranked-by-engagement-rate-per-network
  -->
<template>
	<div class="social-performance" data-testid="social-performance">
		<h2>{{ t('pipelinq', 'Social performance') }}</h2>

		<NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>

		<table class="social-performance__table">
			<thead>
				<tr>
					<th scope="col">{{ t('pipelinq', 'Network') }}</th>
					<th scope="col">{{ t('pipelinq', 'Published') }}</th>
					<th scope="col">{{ t('pipelinq', 'Views') }}</th>
					<th scope="col">{{ t('pipelinq', 'Likes') }}</th>
					<th scope="col">{{ t('pipelinq', 'Comments') }}</th>
					<th scope="col">{{ t('pipelinq', 'Shares') }}</th>
					<th scope="col">{{ t('pipelinq', 'Followers') }}</th>
					<th scope="col">{{ t('pipelinq', 'Engagement rate') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-if="loading">
					<td colspan="8"><NcLoadingIcon :size="20" /></td>
				</tr>
				<tr v-else-if="rows.length === 0">
					<td colspan="8">
						{{ t('pipelinq', 'Nothing has been published yet.') }}
					</td>
				</tr>
				<tr
					v-for="row in rows"
					v-else
					:key="row.publicationId"
					data-testid="social-performance-row">
					<td>{{ networkLabel(row.network) }}</td>
					<td>{{ row.publishedAt }}</td>
					<td>{{ row.metrics?.views || 0 }}</td>
					<td>{{ row.metrics?.likes || 0 }}</td>
					<td>{{ row.metrics?.comments || 0 }}</td>
					<td>{{ row.metrics?.shares || 0 }}</td>
					<td>{{ row.followerCount || 0 }}</td>
					<td>{{ rate(row) }}</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { fetchPerformance } from '../../services/socialApi.js'
import {
	formatEngagementRate,
	networkLimits,
} from '../../services/socialNetworks.js'

export default {
	name: 'SocialPerformanceView',

	components: {
		NcLoadingIcon,
		NcNoteCard,
	},

	data() {
		return {
			loading: false,
			error: '',
			rows: [],
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * @param {string} network The network.
		 * @return {string} Its label.
		 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-posts-are-ranked-by-engagement-rate-per-network
		 */
		networkLabel(network) {
			return networkLimits(network).label
		},

		/**
		 * @param {object} row A ranking row.
		 * @return {string} The engagement rate, or a hyphen.
		 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-posts-are-ranked-by-engagement-rate-per-network
		 */
		rate(row) {
			return formatEngagementRate(row)
		},

		/**
		 * One request, after the page has already rendered.
		 *
		 * @return {Promise<void>} Resolves once the rows are in.
		 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-posts-are-ranked-by-engagement-rate-per-network
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				this.rows = await fetchPerformance()
			} catch {
				this.error = t('pipelinq', 'The numbers could not be loaded.')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.social-performance {
	padding: 20px;
}

.social-performance__table {
	width: 100%;
	border-collapse: collapse;
}

.social-performance__table th,
.social-performance__table td {
	text-align: start;
	padding: 8px;
	border-bottom: 1px solid var(--color-border);
}
</style>
