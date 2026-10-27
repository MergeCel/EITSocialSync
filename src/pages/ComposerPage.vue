<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { CalendarClock, Check, ChevronDown, Clock, FileImage, Film, GripVertical, Image, Images, Info, LoaderCircle, MapPin, Music2, Plus, Send, Settings2, Sparkles, Upload, X } from 'lucide-vue-next'
import PlatformIcon from '../components/PlatformIcon.vue'
import { useAppStore } from '../stores/app'
import type { ContentType, Platform } from '../types'
import { contentLabels, platformLabels } from '../types'

const store = useAppStore()
const route = useRoute()
const router = useRouter()
const existing = route.params.id ? store.state.posts.find((post) => post.id === route.params.id) : undefined
const mode = ref<'now' | 'schedule'>(existing?.scheduledAt || route.query.mode === 'schedule' ? 'schedule' : 'now')
const advancedOpen = ref(false)
const mediaPickerOpen = ref(false)
const submitting = ref(false)
const errors = ref<string[]>([])
const contentTypes: { type: ContentType; icon: typeof Film; detail: string }[] = [
  { type: 'story', icon: Sparkles, detail: '1 foto / video' },
  { type: 'video', icon: Film, detail: 'Reels & video' },
  { type: 'photo', icon: Image, detail: '1 foto' },
  { type: 'carousel', icon: Images, detail: '2–15 foto' },
]
const platforms: Platform[] = ['instagram', 'tiktok', 'facebook']
const initialType = existing?.type ?? 'video'
const initialPlatforms = existing?.platforms.map((item) => item.platform) ?? ['instagram'] as Platform[]
const existingSettings = Object.fromEntries(existing?.platforms.map((item) => [item.platform, item.settings]) ?? []) as Partial<Record<Platform, Record<string, string | boolean>>>
const form = reactive({
  title: existing?.title ?? '', type: initialType as ContentType, caption: existing?.caption ?? '', hashtags: existing?.hashtags ?? '',
  mediaIds: existing ? [...existing.mediaIds] : [] as string[], platforms: [...initialPlatforms] as Platform[],
  scheduledAt: existing?.scheduledAt ? new Date(new Date(existing.scheduledAt).getTime() - new Date(existing.scheduledAt).getTimezoneOffset() * 60000).toISOString().slice(0, 16) : (route.query.date as string ?? ''),
  settings: { instagram: { location: '', branded: false, partner: '', altText: '', music: '', ...existingSettings.instagram }, tiktok: { music: '', duet: true, stitch: true, comments: true, photoMode: ['photo', 'carousel'].includes(initialType) && initialPlatforms.includes('tiktok'), ...existingSettings.tiktok }, facebook: { location: '', audience: 'public', comments: true, altText: '', ...existingSettings.facebook } } as Record<Platform, Record<string, string | boolean>>,
})

const availableMedia = computed(() => store.state.media.filter((media) => form.type === 'video' ? media.kind === 'video' : form.type === 'story' ? true : media.kind === 'image'))
const selectedMedia = computed(() => form.mediaIds.map((id) => store.state.media.find((media) => media.id === id)).filter(Boolean))
const needsCaption = computed(() => form.type !== 'story')

function compatibility(platform: Platform) {
  if (form.type === 'story' && platform === 'tiktok') return 'Story TikTok tidak didukung pada MVP.'
  if (form.type === 'carousel' && platform === 'facebook') return 'Facebook tidak mendukung carousel organik melalui API ini.'
  return ''
}

function platformDescription(platform: Platform) {
  if (platform === 'tiktok' && ['photo', 'carousel'].includes(form.type)) return 'TikTok Photo Mode aktif'
  return compatibility(platform) || 'Tersedia untuk format ini'
}

function chooseType(type: ContentType) {
  form.type = type
  form.mediaIds = []
  form.platforms = form.platforms.filter((platform) => !compatibility(platform))
  if (!form.platforms.length) form.platforms = ['instagram']
  form.settings.tiktok.photoMode = ['photo', 'carousel'].includes(type) && form.platforms.includes('tiktok')
}

function togglePlatform(platform: Platform) {
  const reason = compatibility(platform)
  if (reason) { store.notify('warning', `${platformLabels[platform]} tidak tersedia`, reason); return }
  const index = form.platforms.indexOf(platform)
  if (index >= 0) form.platforms.splice(index, 1)
  else form.platforms.push(platform)
  if (platform === 'tiktok') form.settings.tiktok.photoMode = index < 0 && ['photo', 'carousel'].includes(form.type)
}

function toggleMedia(id: string) {
  const index = form.mediaIds.indexOf(id)
  if (index >= 0) form.mediaIds.splice(index, 1)
  else {
    const max = form.type === 'carousel' ? 15 : 1
    if (form.mediaIds.length >= max) form.mediaIds.splice(0, form.mediaIds.length)
    form.mediaIds.push(id)
  }
}

function validate() {
  const result: string[] = []
  if (!form.title.trim()) result.push('Judul internal wajib diisi.')
  if (!form.mediaIds.length) result.push('Pilih minimal satu media dari Drive.')
  if (form.type === 'carousel' && (form.mediaIds.length < 2 || form.mediaIds.length > 15)) result.push('Carousel membutuhkan 2–15 foto.')
  if (form.type === 'carousel' && form.platforms.includes('instagram') && form.mediaIds.length > 10) result.push('Instagram membatasi carousel maksimal 10 foto.')
  if (needsCaption.value && !form.caption.trim()) result.push('Caption wajib diisi untuk tipe konten ini.')
  if (!form.platforms.length) result.push('Pilih minimal satu platform tujuan.')
  if (mode.value === 'schedule' && (!form.scheduledAt || new Date(form.scheduledAt) <= new Date())) result.push('Jadwal harus berada di waktu yang akan datang.')
  errors.value = result
  return result.length === 0
}

async function submit() {
  form.settings.tiktok.photoMode = form.platforms.includes('tiktok') && ['photo', 'carousel'].includes(form.type)
  if (!validate()) return
  submitting.value = true
  const post = await store.savePost({ id: existing?.id, title: form.title, type: form.type, caption: form.caption, hashtags: form.hashtags, mediaIds: form.mediaIds, platforms: form.platforms, scheduledAt: mode.value === 'schedule' ? new Date(form.scheduledAt).toISOString() : null, settings: form.settings })
  submitting.value = false
  if (!post) return

  if (mode.value === 'schedule') {
    router.push('/schedule')
    return
  }

  router.push(`/engagement/${post.id}`)
}

async function saveDraft() {
  form.settings.tiktok.photoMode = form.platforms.includes('tiktok') && ['photo', 'carousel'].includes(form.type)
  const post = await store.savePost({
    id: existing?.id,
    title: form.title || 'Draft tanpa judul',
    type: form.type,
    caption: form.caption,
    hashtags: form.hashtags,
    mediaIds: form.mediaIds,
    platforms: form.platforms,
    scheduledAt: mode.value === 'schedule' && form.scheduledAt ? new Date(form.scheduledAt).toISOString() : null,
    settings: form.settings,
    asDraft: true,
  })
  if (!post) return
  store.notify('success', 'Draft tersimpan', 'Konten dapat dilanjutkan dari halaman Draft.')
  router.push('/drafts')
}
</script>

<template>
  <div class="composer-layout">
    <form class="composer-main" @submit.prevent="submit">
      <div class="composer-mode card">
        <button type="button" :class="{ active: mode === 'now' }" @click="mode = 'now'"><Send :size="19"/><span><strong>Upload Sekarang</strong><small>Masuk antrean prioritas tinggi</small></span><i /></button>
        <button type="button" :class="{ active: mode === 'schedule' }" @click="mode = 'schedule'"><CalendarClock :size="20"/><span><strong>Upload Terjadwal</strong><small>Pilih tanggal dan waktu tayang</small></span><i /></button>
      </div>

      <section class="card form-section">
        <div class="form-section-title"><span>01</span><div><h2>Dasar Konten</h2><p>Informasi internal dan format yang akan dibuat</p></div></div>
        <label class="field-label">Judul Upload <em>Wajib</em><input v-model="form.title" maxlength="100" placeholder="Contoh: Hari Jadi Kantor"/><small>Hanya terlihat oleh tim, tidak dikirim ke platform.</small></label>
        <div class="field-label">Tipe Konten <em>Wajib</em></div>
        <div class="content-type-grid">
          <button v-for="item in contentTypes" :key="item.type" type="button" :class="{ active: form.type === item.type }" @click="chooseType(item.type)"><component :is="item.icon" :size="22"/><strong>{{ contentLabels[item.type] }}</strong><span>{{ item.detail }}</span><i><Check :size="12"/></i></button>
        </div>
      </section>

      <section class="card form-section">
        <div class="form-section-title"><span>02</span><div><h2>Media</h2><p>Pilih aset dari Drive dan atur urutannya</p></div></div>
        <div v-if="selectedMedia.length" class="selected-media-list">
          <div v-for="(media, index) in selectedMedia" :key="media!.id" class="selected-media"><GripVertical :size="18"/><img :src="media!.thumbnail" alt=""/><div><strong>{{ media!.name }}</strong><span>{{ media!.kind === 'video' ? 'Video' : 'Foto' }} · urutan {{ index + 1 }}</span></div><button type="button" @click="toggleMedia(media!.id)"><X :size="17"/></button></div>
        </div>
        <button type="button" class="media-picker-trigger" @click="mediaPickerOpen = true"><span><FileImage :size="25"/></span><div><strong>{{ selectedMedia.length ? 'Tambah atau ganti media' : 'Pilih dari Drive' }}</strong><p>{{ form.type === 'carousel' ? 'Pilih 2–15 foto dan atur urutannya' : 'Pilih 1 media yang sesuai format' }}</p></div><Plus :size="20"/></button>
      </section>

      <section class="card form-section">
        <div class="form-section-title"><span>03</span><div><h2>Platform Tujuan</h2><p>Pilih tempat konten akan dipublikasikan</p></div></div>
        <div class="platform-choice-grid">
          <button v-for="platform in platforms" :key="platform" type="button" :disabled="Boolean(compatibility(platform))" :class="{ active: form.platforms.includes(platform) }" :title="compatibility(platform)" @click="togglePlatform(platform)"><PlatformIcon :platform="platform" size="lg"/><span><strong>{{ platformLabels[platform] }}</strong><small>{{ platformDescription(platform) }}</small></span><i><Check :size="13"/></i></button>
        </div>
      </section>

      <section v-if="form.type !== 'story'" class="card form-section">
        <div class="form-section-title"><span>04</span><div><h2>Caption</h2><p>Pesan utama yang ikut dipublikasikan</p></div></div>
        <label class="field-label">Caption <em>Wajib</em><textarea v-model="form.caption" rows="5" maxlength="2200" placeholder="Tulis cerita untuk audiens Anda..."/><small>{{ form.caption.length }} / 2.200 karakter</small></label>
        <label class="field-label">Hashtag<input v-model="form.hashtags" placeholder="#SocialSync #Campaign"/></label>
        <div class="info-note"><Info :size="17"/><p><strong>Mention.</strong> Teks @username dikirim sebagai bagian caption. Validasi entity mention otomatis mengikuti dukungan masing-masing API platform.</p></div>
      </section>

      <section v-if="mode === 'schedule'" class="card form-section schedule-field-section">
        <div class="form-section-title"><span>{{ form.type === 'story' ? '04' : '05' }}</span><div><h2>Waktu Tayang</h2><p>Gunakan zona waktu browser Anda</p></div></div>
        <label class="field-label">Tanggal dan jam <em>Wajib</em><input v-model="form.scheduledAt" type="datetime-local" :min="new Date(Date.now() + 60000).toISOString().slice(0, 16)"/></label>
      </section>

      <section class="card advanced-section">
        <button type="button" @click="advancedOpen = !advancedOpen"><span><Settings2 :size="20"/><span><strong>Advanced Settings</strong><small>Pengaturan khusus platform terpilih</small></span></span><ChevronDown :size="19" :class="{ rotate: advancedOpen }"/></button>
        <div v-if="advancedOpen" class="advanced-body">
          <div v-if="form.platforms.includes('instagram')" class="advanced-platform"><h3><PlatformIcon platform="instagram"/> Instagram</h3><div class="field-grid"><label>Lokasi<div class="input-with-icon"><MapPin :size="17"/><input v-model="form.settings.instagram.location" placeholder="Cari lokasi..."/></div></label><label>Sound / Music<div class="input-with-icon"><Music2 :size="17"/><input v-model="form.settings.instagram.music" placeholder="Nama track (opsional)"/></div></label></div><label class="toggle-row"><span><strong>Branded Content</strong><small>Tampilkan label paid partnership</small></span><input v-model="form.settings.instagram.branded" type="checkbox"/></label><input v-if="form.settings.instagram.branded" v-model="form.settings.instagram.partner" placeholder="Nama brand partner"/></div>
          <div v-if="form.platforms.includes('tiktok')" class="advanced-platform"><h3><PlatformIcon platform="tiktok"/> TikTok</h3><label class="toggle-row"><span><strong>Izinkan komentar</strong><small>Audiens dapat memberikan komentar</small></span><input v-model="form.settings.tiktok.comments" type="checkbox"/></label><label class="toggle-row"><span><strong>Izinkan Duet & Stitch</strong><small>Konten dapat digunakan kreator lain</small></span><input v-model="form.settings.tiktok.duet" type="checkbox"/></label></div>
          <div v-if="form.platforms.includes('facebook')" class="advanced-platform"><h3><PlatformIcon platform="facebook"/> Facebook</h3><label>Audience<select v-model="form.settings.facebook.audience"><option value="public">Public</option><option value="friends">Friends</option></select></label><label class="toggle-row"><span><strong>Izinkan komentar</strong><small>Aktifkan diskusi pada post</small></span><input v-model="form.settings.facebook.comments" type="checkbox"/></label></div>
        </div>
      </section>

      <div v-if="errors.length" class="validation-box"><strong>Periksa kembali isian berikut:</strong><ul><li v-for="error in errors" :key="error">{{ error }}</li></ul></div>
      <div class="composer-actions"><button type="button" class="button" :disabled="submitting" @click="saveDraft">Simpan sebagai Draft</button><button class="button button-primary button-submit" :disabled="submitting"><component :is="mode === 'now' ? Send : CalendarClock" :size="18"/>{{ existing && existing.status !== 'draft' ? 'Simpan Perubahan' : mode === 'now' ? 'Upload Sekarang' : 'Jadwalkan Post' }}</button></div>
    </form>

    <aside class="composer-preview card"><div class="preview-title"><span>LIVE PREVIEW</span><p>Tampilan indikatif</p></div><div class="phone-frame"><div class="phone-top"/><div class="preview-account"><div class="avatar avatar-small">SS</div><div><strong>socialsync.id</strong><span>Sponsored · 1m</span></div><MoreIcon /></div><div class="preview-media"><img v-if="selectedMedia[0]" :src="selectedMedia[0].thumbnail" alt="Preview"/><div v-else><Upload :size="31"/><span>Media preview</span></div><b v-if="selectedMedia.length > 1">1/{{ selectedMedia.length }}</b></div><div class="preview-icons">♡　⌁　➤ <span>⌑</span></div><div class="preview-caption"><strong>socialsync.id</strong> {{ form.caption || 'Caption Anda akan terlihat di sini...' }} <span>{{ form.hashtags }}</span></div></div><div class="preview-platforms"><span>Preview platform:</span><PlatformIcon v-for="platform in form.platforms" :key="platform" :platform="platform" size="sm"/></div></aside>
  </div>

  <div v-if="mediaPickerOpen" class="modal-backdrop" @click.self="mediaPickerOpen = false"><section class="modal-card media-picker-modal"><button class="icon-button close-button" @click="mediaPickerOpen = false"><X :size="20"/></button><span class="modal-kicker">PILIH DARI DRIVE</span><h2>{{ contentLabels[form.type] }}</h2><p>{{ form.type === 'carousel' ? `${form.mediaIds.length}/15 foto dipilih` : 'Pilih satu media untuk post ini' }}</p><div class="picker-grid"><button v-for="media in availableMedia" :key="media.id" :class="{ selected: form.mediaIds.includes(media.id) }" @click="toggleMedia(media.id)"><img :src="media.thumbnail" alt=""/><span>{{ media.name }}</span><i><Check :size="14"/></i></button></div><button class="button button-primary button-full" :disabled="!form.mediaIds.length" @click="mediaPickerOpen = false">Gunakan {{ form.mediaIds.length }} Media</button></section></div>
  <div v-if="submitting" class="loading-overlay"><LoaderCircle :size="48"/><h2>{{ mode === 'now' ? 'Mempublikasikan konten...' : 'Menyimpan ke kalender...' }}</h2><p>{{ mode === 'now' ? `Mengirim ke ${form.platforms.map((platform) => platformLabels[platform]).join(', ')}` : 'Menyiapkan antrean publish otomatis' }}</p></div>
</template>

<script lang="ts">
import { MoreHorizontal as MoreIcon } from 'lucide-vue-next'
</script>
