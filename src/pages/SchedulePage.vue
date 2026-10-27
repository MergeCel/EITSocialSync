<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { CalendarPlus, ChevronLeft, ChevronRight, Clock3, MoreHorizontal, Plus } from 'lucide-vue-next'
import PlatformIcon from '../components/PlatformIcon.vue'
import StatusBadge from '../components/StatusBadge.vue'
import ConfirmDialog from '../components/ConfirmDialog.vue'
import EmptyState from '../components/EmptyState.vue'
import { useAppStore } from '../stores/app'
import { formatDate } from '../utils'

const store = useAppStore()
const router = useRouter()
const cursor = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1))
const selectedDay = ref(new Date().getDate())
const cancelTarget = ref<string | null>(null)
const monthLabel = computed(() => new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric' }).format(cursor.value))
const daysInMonth = computed(() => new Date(cursor.value.getFullYear(), cursor.value.getMonth() + 1, 0).getDate())
const firstDay = computed(() => (new Date(cursor.value.getFullYear(), cursor.value.getMonth(), 1).getDay() + 6) % 7)
const monthPosts = computed(() => store.state.posts.filter((post) => post.scheduledAt && new Date(post.scheduledAt).getMonth() === cursor.value.getMonth() && new Date(post.scheduledAt).getFullYear() === cursor.value.getFullYear() && post.status !== 'cancelled'))
const selectedPosts = computed(() => monthPosts.value.filter((post) => new Date(post.scheduledAt!).getDate() === selectedDay.value).sort((a, b) => a.scheduledAt!.localeCompare(b.scheduledAt!)))
const scheduleCounts = computed(() => monthPosts.value.reduce<Record<number, number>>((counts, post) => {
  const day = new Date(post.scheduledAt!).getDate()
  counts[day] = (counts[day] ?? 0) + 1
  return counts
}, {}))
const cells = computed<Array<number | null>>(() => [...Array<number | null>(firstDay.value).fill(null), ...Array.from({ length: daysInMonth.value }, (_, index) => index + 1)])
const weekdays = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min']

function moveMonth(offset: number) {
  cursor.value = new Date(cursor.value.getFullYear(), cursor.value.getMonth() + offset, 1)
  selectedDay.value = 1
}

function compose(day = selectedDay.value) {
  const date = new Date(cursor.value.getFullYear(), cursor.value.getMonth(), day, 10, 0)
  const local = new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16)
  router.push({ path: '/composer', query: { mode: 'schedule', date: local } })
}
</script>

<template>
  <div class="calendar-toolbar card"><button class="icon-button" @click="moveMonth(-1)"><ChevronLeft :size="20"/></button><div><span>PERIODE AKTIF</span><h2>{{ monthLabel }}</h2></div><button class="icon-button" @click="moveMonth(1)"><ChevronRight :size="20"/></button><button class="button" @click="cursor = new Date(new Date().getFullYear(), new Date().getMonth(), 1); selectedDay = new Date().getDate()">Bulan ini</button><button v-if="!store.isViewer" class="button button-primary" @click="compose()"><Plus :size="18"/> Tambah Jadwal</button></div>

  <div v-if="!monthPosts.length" class="card empty-calendar"><span><CalendarPlus :size="34"/></span><h2>Belum ada jadwal di {{ monthLabel }}</h2><p>Mulai susun ritme konten bulanan dan biarkan SocialSync mengingat waktu tayangnya.</p><button v-if="!store.isViewer" class="button button-primary" @click="compose(1)"><Plus :size="18"/> Buat Jadwal Bulan Ini</button></div>

  <div v-else class="calendar-layout">
    <section class="card month-calendar">
      <div class="weekday-row"><span v-for="day in weekdays" :key="day">{{ day }}</span></div>
      <div class="calendar-grid">
        <button v-for="(day, index) in cells" :key="index" :disabled="!day" :class="{ selected: day === selectedDay, today: day === new Date().getDate() && cursor.getMonth() === new Date().getMonth(), 'has-events': day && scheduleCounts[day] }" :aria-label="day ? `${day} ${monthLabel}${scheduleCounts[day] ? `, ${scheduleCounts[day]} jadwal` : ', tidak ada jadwal'}` : undefined" @click="day && (selectedDay = day)">
          <span v-if="day" class="day-number">{{ day }}</span>
          <span v-if="day && scheduleCounts[day]" class="schedule-count"><i/>{{ scheduleCounts[day] }} jadwal</span>
        </button>
      </div>
    </section>

    <aside class="card day-agenda">
      <div class="agenda-head"><div><span>{{ selectedDay }}</span><div><strong>{{ new Intl.DateTimeFormat('id-ID', { weekday: 'long' }).format(new Date(cursor.getFullYear(), cursor.getMonth(), selectedDay)) }}</strong><small>{{ monthLabel }}</small></div></div><button v-if="!store.isViewer" class="icon-button" @click="compose()"><Plus :size="19"/></button></div>
      <div v-if="selectedPosts.length" class="agenda-list">
        <article v-for="post in selectedPosts" :key="post.id" class="agenda-card"><img :src="store.state.media.find((media) => media.id === post.mediaIds[0])?.thumbnail" alt=""/><div class="agenda-copy"><span><Clock3 :size="14"/>{{ new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit' }).format(new Date(post.scheduledAt!)) }}</span><strong>{{ post.title }}</strong><div><PlatformIcon v-for="item in post.platforms" :key="item.platform" :platform="item.platform" size="sm"/><StatusBadge :status="post.status"/></div></div><div v-if="!store.isViewer" class="agenda-menu"><button class="icon-button"><MoreHorizontal :size="18"/></button><div><RouterLink :to="`/composer/${post.id}`">Edit post</RouterLink><button @click="cancelTarget = post.id">Batalkan jadwal</button></div></div></article>
      </div>
      <EmptyState v-else title="Hari ini masih kosong" message="Pilih tanggal lain atau tambahkan konten pada hari ini."><button v-if="!store.isViewer" class="button button-primary" @click="compose()">Tambah post</button></EmptyState>
      <div v-if="selectedPosts.length" class="agenda-summary">{{ selectedPosts.length }} post · Jadwal berikutnya {{ formatDate(selectedPosts[0].scheduledAt!, true) }}</div>
    </aside>
  </div>
  <ConfirmDialog :open="Boolean(cancelTarget)" title="Batalkan Jadwal Post Ini?" :message="`Post '${store.state.posts.find((post) => post.id === cancelTarget)?.title ?? ''}' tidak akan dipublikasikan sesuai jadwal.`" confirm-label="Ya, Batalkan" danger @close="cancelTarget = null" @confirm="cancelTarget && store.cancelPost(cancelTarget); cancelTarget = null"/>
</template>
