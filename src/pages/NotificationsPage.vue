<script setup lang="ts">
import { ArrowLeft, Bell, CheckCheck, CircleAlert, CircleCheck, Info, TriangleAlert } from 'lucide-vue-next'
import EmptyState from '../components/EmptyState.vue'
import { useAppStore } from '../stores/app'
import { formatDate } from '../utils'

const store = useAppStore()
const icons = { success: CircleCheck, error: CircleAlert, warning: TriangleAlert, info: Info }
</script>

<template>
  <RouterLink to="/dashboard" class="back-link"><ArrowLeft :size="17"/> Kembali ke dashboard</RouterLink>
  <section class="card notifications-card"><div class="card-section-head"><div><span class="card-icon"><Bell :size="20"/></span><div><h3>Pusat Notifikasi</h3><p>Hasil publish, koneksi akun, dan pembaruan sistem</p></div></div><button v-if="store.unreadCount" class="button" @click="store.markAllNotifications"><CheckCheck :size="17"/> Tandai semua dibaca</button></div><div v-if="store.state.notifications.length" class="notification-list"><button v-for="item in store.state.notifications" :key="item.id" :class="{ unread: !item.read }" @click="store.markNotification(item.id)"><span class="notification-symbol" :class="`notification-${item.type}`"><component :is="icons[item.type]" :size="20"/></span><div><strong>{{ item.title }}</strong><p>{{ item.message }}</p><small>{{ formatDate(item.createdAt, true) }}</small></div><i v-if="!item.read"/></button></div><EmptyState v-else title="Belum ada notifikasi" message="Hasil publikasi dan pembaruan sistem akan muncul di sini."/></section>
</template>
