/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * The Pipeline and Stage fields the lead and request forms share: the chosen
 * pipeline, its stages in order, and the first open stage a pipeline puts a
 * new item on.
 *
 * The host has `pipelines` (every stored pipeline) and a `form` with
 * `pipeline` and `stage`.
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-leads-ui/tasks.md#task-49
 */
export default {
	computed: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-leads-ui/tasks.md#task-53
		 */
		selectedPipeline() {
			if (!this.form.pipeline) return null
			return this.pipelines.find((p) => p.id === this.form.pipeline) || null
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-leads-ui/tasks.md#task-55
		 */
		stageOptions() {
			if (!this.selectedPipeline?.stages) return []
			return [...this.selectedPipeline.stages]
				.sort((a, b) => a.order - b.order)
				.map((s) => s.name)
		},
	},

	methods: {
		/**
		 * Reset the stage when the pipeline changes, and auto-select the
		 * pipeline's first open stage.
		 *
		 * @spec openspec/changes/reverse-2026-05-26-fe-leads-ui/tasks.md#task-49
		 */
		onPipelineChange() {
			this.form.stage = null
			if (this.selectedPipeline) {
				this.placeOnFirstOpenStage(this.selectedPipeline)
			}
		},

		/**
		 * Put the form on the pipeline's first open stage, when it has one.
		 *
		 * @param {object} pipeline The pipeline.
		 * @return {void}
		 */
		placeOnFirstOpenStage(pipeline) {
			const stages = [...(pipeline.stages || [])].sort(
				(a, b) => a.order - b.order,
			)
			const firstOpen = stages.find((s) => !s.isClosed)
			if (firstOpen) {
				this.form.stage = firstOpen.name
			}
		},
	},
}
