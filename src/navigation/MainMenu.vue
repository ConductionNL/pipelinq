<template>
	<NcAppNavigation>
		<template #list>
			<NcAppNavigationItem
				:name="t('pipelinq', 'Dashboard')"
				:to="{ name: 'Dashboard' }"
				:exact="true">
				<template #icon>
					<ViewDashboard :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('pipelinq', 'Clients')"
				:to="{ name: 'Clients' }">
				<template #icon>
					<AccountGroup :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('pipelinq', 'Contacts')"
				:to="{ name: 'Contacts' }">
				<template #icon>
					<AccountBox :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('pipelinq', 'Leads')"
				:to="{ name: 'Leads' }">
				<template #icon>
					<TrendingUp :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('pipelinq', 'Requests')"
				:to="{ name: 'Requests' }">
				<template #icon>
					<FileDocument :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('pipelinq', 'Tasks')"
				:to="{ name: 'Tasks' }">
				<template #icon>
					<ClipboardCheck :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('pipelinq', 'Contactmomenten')"
				:to="{ name: 'Contactmomenten' }">
				<template #icon>
					<PhoneMessage :size="20" />
				:name="t('pipelinq', 'Complaints')"
				:to="{ name: 'Complaints' }">
				<template #icon>
					<AlertCircleOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('pipelinq', 'Products')"
				:to="{ name: 'Products' }">
				<template #icon>
					<PackageVariant :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('pipelinq', 'Pipeline')"
				:to="{ name: 'Pipeline' }">
				<template #icon>
					<ViewColumn :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('pipelinq', 'Surveys')"\n\t\t\t\t:to="{ name: 'Surveys' }">\n\t\t\t\t<template #icon>\n\t\t\t\t\t<EmoticonHappyOutline :size="20" />\n\t\t\t\t</template>\n\t\t\t</NcAppNavigationItem>\n\t\t\t<NcAppNavigationItem\n\t\t\t\t:name="t('pipelinq', 'Kennisbank')"
				:name="t('pipelinq', 'Queues')"
				:to="{ name: 'Queues' }">
				<template #icon>
					<InboxMultiple :size="20" />
				:name="t('pipelinq', 'Kennisbank')"
				:to="{ name: 'Kennisbank' }">
				<template #icon>
					<BookOpenPageVariant :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('pipelinq', 'My Work')"
				:to="{ name: 'MyWork' }">
				<template #icon>
					<AccountCheck :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('pipelinq', 'Contact Moments')"
				:to="{ name: 'Contactmomenten' }">
				<template #icon>
					<PhoneInTalk :size="20" />
				:name="t('pipelinq', 'Knowledge Base')"
				:to="{ name: 'Kennisbank' }">
				<template #icon>
					<BookOpenPageVariant :size="20" />
				:name="t('pipelinq', 'Reporting')"
				:to="{ name: 'Rapportage' }">
				<template #icon>
					<ChartBar :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('pipelinq', 'Documentation')"
				@click="openLink('https://conduction.gitbook.io/pipelinq-nextcloud', '_blank')">
				<template #icon>
					<BookOpenVariantOutline :size="20" />
				</template>
			</NcAppNavigationItem>
		</template>
		<template #footer>
			<NcAppNavigationSettings>
				<NcAppNavigationItem
					:name="t('pipelinq', 'Pipelines')"
					:to="{ name: 'Pipelines' }">
					<template #icon>
						<Pipe :size="20" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="t('pipelinq', 'Configuration')"
					@click="$emit('open-settings')">
					<template #icon>
						<Cog :size="20" />
					</template>
				</NcAppNavigationItem>
			</NcAppNavigationSettings>
		</template>
	</NcAppNavigation>
</template>

<script>
import { NcAppNavigation, NcAppNavigationItem, NcAppNavigationSettings } from '@nextcloud/vue'
import ViewDashboard from 'vue-material-design-icons/ViewDashboard.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import AccountBox from 'vue-material-design-icons/AccountBox.vue'
import FileDocument from 'vue-material-design-icons/FileDocument.vue'
import TrendingUp from 'vue-material-design-icons/TrendingUp.vue'
import ViewColumn from 'vue-material-design-icons/ViewColumn.vue'
import AccountCheck from 'vue-material-design-icons/AccountCheck.vue'
import ClipboardCheck from 'vue-material-design-icons/ClipboardCheck.vue'
import BookOpenPageVariant from 'vue-material-design-icons/BookOpenPageVariant.vue'
import BookOpenVariantOutline from 'vue-material-design-icons/BookOpenVariantOutline.vue'
import PhoneInTalk from 'vue-material-design-icons/PhoneInTalk.vue'
import BookOpenPageVariant from 'vue-material-design-icons/BookOpenPageVariant.vue'
import ChartBar from 'vue-material-design-icons/ChartBar.vue'
import EmoticonHappyOutline from 'vue-material-design-icons/EmoticonHappyOutline.vue'
import PackageVariant from 'vue-material-design-icons/PackageVariant.vue'
import PhoneMessage from 'vue-material-design-icons/PhoneMessage.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import InboxMultiple from 'vue-material-design-icons/InboxMultiple.vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import Pipe from 'vue-material-design-icons/Pipe.vue'

export default {
	name: 'MainMenu',
	components: {
		NcAppNavigation,
		NcAppNavigationItem,
		NcAppNavigationSettings,
		ViewDashboard,
		AccountGroup,
		AccountBox,
		FileDocument,
		TrendingUp,
		ViewColumn,
		AccountCheck,
		BookOpenPageVariant,
		BookOpenVariantOutline,
		PackageVariant,
		EmoticonHappyOutline,
		ClipboardCheck,
		BookOpenPageVariant,
		BookOpenVariantOutline,
		PhoneInTalk,
		BookOpenPageVariant,
		ChartBar,
		PackageVariant,
		InboxMultiple,
		PhoneMessage,
		AlertCircleOutline,
		Cog,
		Pipe,
	},
	methods: {
		openLink(url, target) {
			window.open(url, target)
		},
	},
}
</script>
