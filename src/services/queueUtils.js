/**
 * Queue utility functions for Pipelinq.
 *
 * Priority sort comparator, capacity check, and routing suggestion logic.
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * Priority ordering map (lower number = higher priority).
 */
const PRIORITY_ORDER = { urgent: 0, high: 1, normal: 2, low: 3 }

/**
 * Compare two items by priority (descending) then by age (oldest first).
 *
 * @param {object} a First item
 * @param {object} b Second item
 * @return {number} Sort comparison result
 */
export function prioritySortComparator(a, b) {
	const pa = PRIORITY_ORDER[a.priority] ?? 2
	const pb = PRIORITY_ORDER[b.priority] ?? 2
	if (pa !== pb) return pa - pb

	// Oldest first (ascending date)
	const dateA = a.requestedAt || a.dateCreated || ''
	const dateB = b.requestedAt || b.dateCreated || ''
	if (dateA && dateB) return new Date(dateA).getTime() - new Date(dateB).getTime()
	if (dateA) return -1
	if (dateB) return 1
	return 0
}

/**
 * Check if a queue is at capacity.
 *
 * @param {object} queue Queue object
 * @param {number} currentCount Current number of items in the queue
 * @return {boolean} True if queue is at or over capacity
 */
export function isAtCapacity(queue, currentCount) {
	if (!queue.maxCapacity) return false
	return currentCount >= queue.maxCapacity
}

/**
 * Calculate the waiting time label for an item.
 *
 * @param {string} dateStr ISO date string (requestedAt or dateCreated)
 * @return {string} Human-readable waiting time (e.g., "waiting 3 days")
 */
export function getWaitingTime(dateStr) {
	if (!dateStr) return ''
	const created = new Date(dateStr)
	const now = new Date()
	const diffMs = now.getTime() - created.getTime()
	const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24))

	if (diffDays === 0) {
		const diffHours = Math.floor(diffMs / (1000 * 60 * 60))
		if (diffHours === 0) return t('pipelinq', 'just now')
		return t('pipelinq', 'waiting {hours}h', { hours: diffHours })
	}
	if (diffDays === 1) return t('pipelinq', 'waiting 1 day')
	return t('pipelinq', 'waiting {days} days', { days: diffDays })
}

/**
 * Get the oldest item's waiting time from a list of items.
 *
 * @param {Array} items Queue items
 * @return {string} Waiting time of the oldest item
 */
export function getOldestWaitingTime(items) {
	if (!items || items.length === 0) return '-'
	let oldest = null
	for (const item of items) {
		const date = item.requestedAt || item.dateCreated
		if (!date) continue
		if (!oldest || new Date(date) < new Date(oldest)) {
			oldest = date
		}
	}
	return oldest ? getWaitingTime(oldest) : '-'
}

/**
 * Find agents whose skills match a given category.
 *
 * @param {string} category The request category to match
 * @param {Array} skills All skill definitions
 * @param {Array} agentProfiles All agent profiles
 * @return {Array} Agent profiles with matching skills
 */
export function findMatchingAgents(category, skills, agentProfiles) {
	if (!category) {
		// No category: return all available agents
		return agentProfiles.filter(p => p.isAvailable !== false)
	}

	const lowerCategory = category.toLowerCase()

	// Find skills that cover this category
	const matchingSkillIds = skills
		.filter(s => s.isActive !== false && (s.categories || []).some(
			c => c.toLowerCase() === lowerCategory,
		))
		.map(s => s.id)

	if (matchingSkillIds.length === 0) return []

	// Find agents with those skills
	return agentProfiles.filter(p => {
		if (p.isAvailable === false) return false
		const agentSkills = p.skills || []
		return agentSkills.some(skillId => matchingSkillIds.includes(skillId))
	})
}

/**
 * Sort agents by workload (fewest open items first).
 *
 * @param {Array} agents Array of { profile, workload } objects
 * @return {Array} Sorted array
 */
export function sortByWorkload(agents) {
	return [...agents].sort((a, b) => a.workload - b.workload)
}

/**
 * Filter out agents at capacity.
 *
 * @param {Array} agents Array of { profile, workload } objects
 * @return {{ available: Array, atCapacity: number }}
 */
export function filterByCapacity(agents) {
	const available = []
	let atCapacity = 0

	for (const agent of agents) {
		const max = agent.profile.maxConcurrent || 10
		if (agent.workload >= max) {
			atCapacity++
		} else {
			available.push(agent)
		}
	}

	return { available, atCapacity }
}
