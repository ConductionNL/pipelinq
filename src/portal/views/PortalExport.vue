<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<div class="portal-export">
		<h1>{{ t('pipelinq', 'Privacy and data') }}</h1>

		<section>
			<h2>{{ t('pipelinq', 'Request my data') }}</h2>
			<p>
				{{
					t(
						'pipelinq',
						'Download a machine-readable copy of the data we hold about you (AVG Art. 15).',
					)
				}}
			</p>
			<p
				v-if="exportLink"
				role="status"
				aria-live="polite"
				class="portal-success">
				<a :href="exportLink">{{
					t('pipelinq', 'Your export is ready to download.')
				}}</a>
			</p>
			<button class="portal-button-primary" @click="requestExport">
				{{ t('pipelinq', 'Request my data') }}
			</button>
		</section>

		<section>
			<h2>{{ t('pipelinq', 'Close my account') }}</h2>
			<p>
				{{
					t(
						'pipelinq',
						'We will email a confirmation link. Closing your account cannot be undone.',
					)
				}}
			</p>
			<p
				v-if="closeMessage"
				role="status"
				aria-live="polite"
				class="portal-success">
				{{ closeMessage }}
			</p>
			<button
				v-if="!confirming"
				class="portal-button-danger"
				@click="confirming = true">
				{{ t('pipelinq', 'Close my account') }}
			</button>
			<template v-else>
				<p>{{ t('pipelinq', 'Are you sure?') }}</p>
				<button class="portal-button-danger" @click="requestClose">
					{{ t('pipelinq', 'Yes, close my account') }}
				</button>
				<button class="portal-button-link" @click="confirming = false">
					{{ t('pipelinq', 'Cancel') }}
				</button>
			</template>
		</section>

		<p v-if="error" role="alert" class="portal-error">
			{{ error }}
		</p>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { portalApi } from '../portalApi.js'

export default {
	name: 'PortalExport',
	data() {
		return { exportLink: '', closeMessage: '', confirming: false, error: '' }
	},

	methods: {
		async requestExport() {
			this.error = ''
			try {
				const result = await portalApi.requestExport()
				this.exportLink = generateUrl('/apps/pipelinq' + result.downloadUrl)
			} catch (e) {
				this.error =
					e.message || t('pipelinq', 'Could not request the export.')
			}
		},

		async requestClose() {
			this.error = ''
			try {
				await portalApi.requestClose()
				this.confirming = false
				this.closeMessage = t(
					'pipelinq',
					'Check your email to confirm closing your account.',
				)
			} catch (e) {
				this.error =
					e.message || t('pipelinq', 'Could not request account closure.')
			}
		},
	},
}
</script>
