<script setup lang="ts">
import { Shield, Terminal, UserCheck, UserRoundCog, Users } from 'lucide-vue-next'
import StatusBadge from '../components/StatusBadge.vue'
import { useAppStore } from '../stores/app'
import type { Role } from '../types'

const store = useAppStore()
const roleCopy: Record<Role, string> = { Admin: 'Akses penuh termasuk akun sosial dan pengguna.', Editor: 'Kelola media, post, dan jadwal publikasi.', Viewer: 'Hanya melihat dashboard dan engagement.' }
</script>

<template>
  <section class="team-stats"><article><span><Users :size="22"/></span><div><strong>{{ store.state.users.length }}</strong><p>Total pengguna</p></div></article><article><span><Shield :size="22"/></span><div><strong>{{ store.state.users.filter((user) => user.role === 'Admin').length }}</strong><p>Administrator</p></div></article><article><span><UserCheck :size="22"/></span><div><strong>{{ store.state.users.filter((user) => user.active).length }}</strong><p>Akun aktif</p></div></article></section>
  <div class="role-notice cli-user-notice"><Terminal :size="20"/><p>Penambahan pengguna dinonaktifkan dari web. Developer hanya dapat memprovisikan pengguna dan API key Buffer melalui perintah <code>php artisan socialsync:user-provision</code> di server.</p></div>
  <section class="card team-card"><div class="card-section-head"><div><span class="card-icon"><UserRoundCog :size="20"/></span><div><h3>Anggota Tim</h3><p>Role berlaku pada API dan seluruh aksi workspace</p></div></div></div><div class="team-list"><article v-for="user in store.state.users" :key="user.id"><div class="avatar">{{ user.initials }}</div><div class="team-identity"><strong>{{ user.name }} <small v-if="user.id === store.activeUser.id">Anda</small></strong><span>{{ user.email }}</span></div><div class="role-chip" :class="`role-${user.role.toLowerCase()}`"><Shield :size="14"/>{{ user.role }}</div><p>{{ roleCopy[user.role] }}</p><StatusBadge :status="user.active ? 'active' : 'inactive'"/><button v-if="store.isAdmin && user.id !== store.activeUser.id" class="button button-small" @click="store.toggleUser(user.id)">{{ user.active ? 'Nonaktifkan' : 'Aktifkan' }}</button></article></div></section>
  <div v-if="!store.isAdmin" class="role-notice"><Shield :size="19"/><p>Perubahan status pengguna hanya tersedia untuk Admin. Pembuatan pengguna tetap hanya dapat dilakukan lewat Artisan.</p></div>
</template>
