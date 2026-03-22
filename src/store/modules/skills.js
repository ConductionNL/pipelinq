/**
 * Skills store for Pipelinq — manages skill CRUD via OpenRegister API.
 */
import { defineStore } from 'pinia'
import { useObjectStore } from './object.js'

export const useSkillsStore = defineStore('skills', {
	state: () => ({
		skills: [],
		loading: false,
		error: null,
	}),
	getters: {
		activeSkills: (state) => state.skills.filter(s => s.isActive !== false),
		getSkillById: (state) => (id) => state.skills.find(s => s.id === id),
	},
	actions: {
		async fetchSkills() {
			this.loading = true
			this.error = null
			try {
				const objectStore = useObjectStore()
				const result = await objectStore.fetchObjects('skill', { _limit: 100 })
				this.skills = result || []
			} catch (error) {
				this.error = error.message
				console.error('Error fetching skills:', error)
			} finally {
				this.loading = false
			}
		},

		async saveSkill(data) {
			this.loading = true
			this.error = null
			try {
				const objectStore = useObjectStore()
				const result = await objectStore.saveObject('skill', data)
				if (result) {
					await this.fetchSkills()
				}
				return result
			} catch (error) {
				this.error = error.message
				console.error('Error saving skill:', error)
				return null
			} finally {
				this.loading = false
			}
		},

		async deleteSkill(id) {
			this.loading = true
			this.error = null
			try {
				const objectStore = useObjectStore()
				const success = await objectStore.deleteObject('skill', id)
				if (success) {
					this.skills = this.skills.filter(s => s.id !== id)
				}
				return success
			} catch (error) {
				this.error = error.message
				console.error('Error deleting skill:', error)
				return false
			} finally {
				this.loading = false
			}
		},
	},
})
