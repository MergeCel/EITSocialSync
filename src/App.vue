<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter, RouterLink, RouterView } from 'vue-router'
import { Bell, CalendarDays, ChartNoAxesCombined, ChevronDown, Cloud, FileText, HardDrive, LayoutDashboard, LogOut, Menu, Plus, Settings, Share2, Users, X } from 'lucide-vue-next'
import { useAppStore } from './stores/app'
import MediaTaskIndicator from './components/MediaTaskIndicator.vue'

const route = useRoute()
const router = useRouter()
const store = useAppStore()
const mobileMenu = ref(false)
const profileOpen = ref(false)
const pageTitle = computed(() => route.meta.title as string ?? 'SocialSync')
const pageEyebrow = computed(() => route.meta.eyebrow as string ?? '')
const isPublic = computed(() => Boolean(route.meta.public))

watch(() => store.authenticated, (authenticated) => {
  if (!authenticated && !route.meta.public) void router.replace({ path: '/login', query: { redirect: route.fullPath } })
})

async function logout() {
  profileOpen.value = false
  await store.logout()
  await router.replace('/login')
}

const primaryNav = [
  { to: '/dashboard', label: 'Dashboard', icon: LayoutDashboard },
  { to: '/composer', label: 'Buat Post', icon: Plus, requiresEdit: true, create: true },
  { to: '/drafts', label: 'Draft', icon: FileText, requiresEdit: true },
  { to: '/drive', label: 'Drive', icon: HardDrive },
  { to: '/schedule', label: 'Jadwal', icon: CalendarDays },
  { to: '/engagement', label: 'Engagement', icon: ChartNoAxesCombined },
]
const adminNav = [
  { to: '/accounts', label: 'Koneksi Akun', icon: Share2 },
  { to: '/team', label: 'Tim & Akses', icon: Users },
  { to: '/settings', label: 'Pengaturan', icon: Settings },
]
</script>

<template>
  <RouterView v-if="isPublic" />
  <div v-else class="app-shell">
    <div v-if="mobileMenu" class="mobile-scrim" @click="mobileMenu = false" />
    <aside class="sidebar" :class="{ open: mobileMenu }">
      <div class="brand"><div class="brand-mark"><Cloud :size="25" /><span /></div><div><strong>SocialSync</strong><small>Content workspace</small></div></div>
      <button class="sidebar-close" @click="mobileMenu = false"><X /></button>
      <nav class="sidebar-nav">
        <p class="nav-label">Workspace</p>
        <template v-for="item in primaryNav" :key="item.to"><RouterLink v-if="!item.requiresEdit || !store.isViewer" :to="item.to" :class="{ 'nav-create': item.create }" @click="mobileMenu = false"><component :is="item.icon" :size="19" />{{ item.label }}</RouterLink></template>
        <p class="nav-label nav-label-spaced">Kelola</p>
        <RouterLink v-for="item in adminNav" :key="item.to" :to="item.to" @click="mobileMenu = false"><component :is="item.icon" :size="19" />{{ item.label }}</RouterLink>
      </nav>
      <div class="sidebar-user">
        <div class="avatar">{{ store.activeUser.initials }}</div>
        <div><strong>{{ store.activeUser.name }}</strong><span>{{ store.activeUser.role }}</span></div>
        <ChevronDown :size="17" />
      </div>
    </aside>

    <main class="main-column">
      <header class="topbar">
        <button class="mobile-menu-button" @click="mobileMenu = true"><Menu /></button>
        <div class="page-heading"><p>{{ pageEyebrow }}</p><h1>{{ pageTitle }}</h1></div>
        <div class="topbar-actions">
          <RouterLink v-if="!store.isViewer" to="/composer" class="button button-primary top-create"><Plus :size="18" />Buat Post</RouterLink>
          <RouterLink to="/notifications" class="icon-button notification-button" aria-label="Notifikasi"><Bell :size="20" /><b v-if="store.unreadCount">{{ store.unreadCount }}</b></RouterLink>
          <div class="profile-switcher">
            <button @click="profileOpen = !profileOpen"><span class="avatar avatar-small">{{ store.activeUser.initials }}</span><span class="profile-copy"><strong>{{ store.activeUser.name }}</strong><small>{{ store.activeUser.role }}</small></span><ChevronDown :size="15" /></button>
            <div v-if="profileOpen" class="profile-menu">
              <p>Akun aktif</p>
              <button type="button" @click="profileOpen = false"><span class="avatar avatar-small">{{ store.activeUser.initials }}</span><span><strong>{{ store.activeUser.name }}</strong><small>{{ store.activeUser.email }}</small></span></button>
              <button type="button" class="profile-logout" @click="logout"><LogOut :size="17"/><span><strong>Keluar</strong><small>Akhiri sesi di perangkat ini</small></span></button>
            </div>
          </div>
        </div>
      </header>
      <div class="page-container"><RouterView /></div>
    </main>

    <MediaTaskIndicator />
    <Transition name="toast">
      <div v-if="store.toast" class="toast" :class="`toast-${store.toast.type}`"><i /><div><strong>{{ store.toast.title }}</strong><p>{{ store.toast.message }}</p></div><button @click="store.toast = null"><X :size="17" /></button></div>
    </Transition>
  </div>
</template>
