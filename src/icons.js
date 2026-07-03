// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Conduction B.V.
//
// Schema icons used across the app. CnIcon (and CnIndexPage/CnDetailPage
// headers + empty states) look up a schema's `icon` by PascalCase name in
// the @conduction/nextcloud-vue icon registry; unregistered names fall back
// to a help-circle. These names mirror the `icon` field of each schema in
// lib/Settings/pipelinq_register.json — keep the two in sync.

import AccountCog from 'vue-material-design-icons/AccountCog.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import BookOpenPageVariant from 'vue-material-design-icons/BookOpenPageVariant.vue'
import Calendar from 'vue-material-design-icons/Calendar.vue'
import CalendarCheck from 'vue-material-design-icons/CalendarCheck.vue'
import CardAccountDetails from 'vue-material-design-icons/CardAccountDetails.vue'
import CartArrowDown from 'vue-material-design-icons/CartArrowDown.vue'
import CartOutline from 'vue-material-design-icons/CartOutline.vue'
import CashRefund from 'vue-material-design-icons/CashRefund.vue'
import ClipboardCheck from 'vue-material-design-icons/ClipboardCheck.vue'
import ClipboardCheckOutline from 'vue-material-design-icons/ClipboardCheckOutline.vue'
import ClipboardTextOutline from 'vue-material-design-icons/ClipboardTextOutline.vue'
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import FileDocumentCheck from 'vue-material-design-icons/FileDocumentCheck.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import FolderOpen from 'vue-material-design-icons/FolderOpen.vue'
import FormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue'
import FormTextboxPassword from 'vue-material-design-icons/FormTextboxPassword.vue'
import Heart from 'vue-material-design-icons/Heart.vue'
import History from 'vue-material-design-icons/History.vue'
import Package from 'vue-material-design-icons/Package.vue'
import PhoneMessage from 'vue-material-design-icons/PhoneMessage.vue'
import Pipe from 'vue-material-design-icons/Pipe.vue'
import ReceiptText from 'vue-material-design-icons/ReceiptText.vue'
import SchoolOutline from 'vue-material-design-icons/SchoolOutline.vue'
import Tag from 'vue-material-design-icons/Tag.vue'
import TagOutline from 'vue-material-design-icons/TagOutline.vue'
import ThumbsUpDown from 'vue-material-design-icons/ThumbsUpDown.vue'
import Tray from 'vue-material-design-icons/Tray.vue'
import TrendingUp from 'vue-material-design-icons/TrendingUp.vue'

export default {
	AccountCog,
	AccountGroup,
	AccountMultiple,
	AlertCircleOutline,
	BookOpenPageVariant,
	Calendar,
	CalendarCheck,
	CardAccountDetails,
	CartArrowDown,
	CartOutline,
	CashRefund,
	ClipboardCheck,
	ClipboardCheckOutline,
	ClipboardTextOutline,
	EmailOutline,
	Eye,
	FileDocumentCheck,
	FileDocumentOutline,
	FolderOpen,
	FormatListBulleted,
	FormTextboxPassword,
	Heart,
	History,
	Package,
	PhoneMessage,
	Pipe,
	ReceiptText,
	SchoolOutline,
	Tag,
	TagOutline,
	ThumbsUpDown,
	Tray,
	TrendingUp,
}
