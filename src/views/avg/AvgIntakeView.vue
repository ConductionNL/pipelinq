<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<template>
	<div class="avg-intake-view">
		<h2>{{ t('pipelinq', 'New AVG request') }}</h2>
		<AvgIntakeForm @created="onCreated" />
	</div>
</template>

<script>
import AvgIntakeForm from '../../components/avg/AvgIntakeForm.vue'

export default {
	name: 'AvgIntakeView',
	components: {
		AvgIntakeForm,
	},
	methods: {
		/**
		 * Navigate to the newly created request's detail.
		 *
		 * @param {object} verzoek The created request.
		 */
		onCreated(verzoek) {
			const id = verzoek.id || verzoek['@self']?.id
			if (id) {
				this.$router.push({ name: 'AvgRequestDetail', params: { id } })
			}
		},
	},
}
</script>

<style scoped>
.avg-intake-view { padding: 16px; }
</style>
