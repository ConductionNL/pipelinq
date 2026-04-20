<template>
	<div class="call-timer">
		<div class="call-timer__display">
			{{ formattedTime }}
		</div>
		<div class="call-timer__controls">
			<NcButton
				v-if="!running"
				type="primary"
				@click="start">
				{{ t('pipelinq', 'Start') }}
			</NcButton>
			<NcButton
				v-if="running"
				type="error"
				@click="stop">
				{{ t('pipelinq', 'Stop') }}
			</NcButton>
			<NcButton
				type="tertiary"
				@click="reset">
				{{ t('pipelinq', 'Reset') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'

export default {
	name: 'CallTimer',
	components: { NcButton },
	data() {
		return {
			seconds: 0,
			running: false,
			interval: null,
		}
	},
	computed: {
		formattedTime() {
			const m = Math.floor(this.seconds / 60)
			const s = this.seconds % 60
			return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`
		},
		isoDuration() {
			const m = Math.floor(this.seconds / 60)
			const s = this.seconds % 60
			return `PT${m}M${s}S`
		},
	},
	beforeDestroy() {
		clearInterval(this.interval)
	},
	methods: {
		start() {
			if (this.running) return
			this.running = true
			this.interval = setInterval(() => {
				this.seconds++
				this.$emit('tick', this.isoDuration)
			}, 1000)
		},
		stop() {
			this.running = false
			clearInterval(this.interval)
			this.$emit('stopped', this.isoDuration)
		},
		reset() {
			this.stop()
			this.seconds = 0
			this.$emit('reset')
		},
	},
}
</script>

<style scoped>
.call-timer { display: flex; align-items: center; gap: 12px; padding: 8px 12px; border: 1px solid var(--color-border); border-radius: var(--border-radius-large); background: var(--color-background-dark); }

.call-timer__display { font-family: monospace; font-size: 1.5em; font-weight: 700; min-width: 80px; text-align: center; }

.call-timer__controls { display: flex; gap: 4px; }
</style>
