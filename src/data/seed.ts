import type { AppState, Metrics, Platform } from '../types'

const now = new Date()
const at = (monthOffset: number, day: number, hour = 10) => {
  const date = new Date(now.getFullYear(), now.getMonth() + monthOffset, day, hour, 0)
  return date.toISOString()
}

const metrics = (views: number, likes: number, comments: number, shares: number, saves: number): Metrics => ({
  views, likes, comments, shares, saves, fetchedAt: new Date(Date.now() - 42 * 60_000).toISOString(),
})

const success = (platform: Platform) => ({
  platform,
  status: 'success' as const,
  platformPostId: `demo-${platform}-${Math.floor(Math.random() * 9000) + 1000}`,
  publishedAt: at(0, Math.max(1, now.getDate() - 4)),
  settings: {},
})

export function createSeed(): AppState {
  return {
    version: 1,
    activeUserId: 'user-1',
    users: [
      { id: 'user-1', name: 'Nadia Putri', email: 'nadia@socialsync.id', role: 'Admin', active: true, initials: 'NP', passwordTemplate: true },
      { id: 'user-2', name: 'Raka Pratama', email: 'raka@socialsync.id', role: 'Editor', active: true, initials: 'RP', passwordTemplate: true },
      { id: 'user-3', name: 'Maya Anindita', email: 'maya@socialsync.id', role: 'Viewer', active: true, initials: 'MA', passwordTemplate: true },
    ],
    folders: [
      { id: 'folder-1', name: 'Kampanye September', parentId: null, createdAt: at(0, 1) },
      { id: 'folder-2', name: 'Behind The Scene', parentId: null, createdAt: at(-1, 12) },
      { id: 'folder-3', name: 'Produk & Katalog', parentId: null, createdAt: at(-2, 7) },
    ],
    media: [
      { id: 'media-1', folderId: 'folder-1', name: 'Hari Jadi Kantor', originalName: 'IMG_8492.jpg', kind: 'image', mimeType: 'image/jpeg', size: 2480000, dimensions: '1080 x 1350', thumbnail: 'https://images.unsplash.com/photo-1519167758481-83f550bb49b3?auto=format&fit=crop&w=900&q=80', uploadedBy: 'user-1', createdAt: at(0, 3) },
      { id: 'media-2', folderId: 'folder-1', name: 'Teaser Produk Baru', originalName: 'final_teaser_v4.mp4', kind: 'video', mimeType: 'video/mp4', size: 48200000, duration: 28, dimensions: '1080 x 1920', thumbnail: 'https://images.unsplash.com/photo-1523726491678-bf852e717f6a?auto=format&fit=crop&w=900&q=80', uploadedBy: 'user-2', createdAt: at(0, 5) },
      { id: 'media-3', folderId: 'folder-2', name: 'Tim Kreatif', originalName: 'team-day.jpg', kind: 'image', mimeType: 'image/jpeg', size: 3100000, dimensions: '1920 x 1080', thumbnail: 'https://images.unsplash.com/photo-1521737711867-e3b97375f902?auto=format&fit=crop&w=900&q=80', uploadedBy: 'user-2', createdAt: at(-1, 18) },
      { id: 'media-4', folderId: 'folder-3', name: 'Koleksi Marigold 01', originalName: 'DSC_1033.webp', kind: 'image', mimeType: 'image/webp', size: 1900000, dimensions: '1080 x 1350', thumbnail: 'https://images.unsplash.com/photo-1525507119028-ed4c629a60a3?auto=format&fit=crop&w=900&q=80', uploadedBy: 'user-1', createdAt: at(0, 6) },
      { id: 'media-5', folderId: 'folder-3', name: 'Koleksi Marigold 02', originalName: 'DSC_1037.webp', kind: 'image', mimeType: 'image/webp', size: 2100000, dimensions: '1080 x 1350', thumbnail: 'https://images.unsplash.com/photo-1485230895905-ec40ba36b9bc?auto=format&fit=crop&w=900&q=80', uploadedBy: 'user-1', createdAt: at(0, 6) },
      { id: 'media-6', folderId: null, name: 'Morning Routine Reel', originalName: 'morning-routine.mov', kind: 'video', mimeType: 'video/quicktime', size: 73100000, duration: 42, dimensions: '1080 x 1920', thumbnail: 'https://images.unsplash.com/photo-1494438639946-1ebd1d20bf85?auto=format&fit=crop&w=900&q=80', uploadedBy: 'user-2', createdAt: at(0, 8) },
    ],
    mediaTasks: [],
    posts: [
      { id: 'post-1', title: 'Hari Jadi Kantor', type: 'video', caption: 'Merayakan perjalanan dan semua orang hebat di baliknya.', hashtags: '#TeamSocialSync #Anniversary', mediaIds: ['media-2'], status: 'published', scheduledAt: null, createdAt: at(0, 4), createdBy: 'user-1', platforms: [success('instagram'), success('tiktok'), success('facebook')], metrics: { instagram: metrics(32800, 4820, 319, 188, 640), tiktok: metrics(72100, 9400, 821, 1100, 901), facebook: metrics(19400, 2200, 142, 390, 88) } },
      { id: 'post-2', title: 'Koleksi Marigold', type: 'carousel', caption: 'Warna baru untuk cerita yang baru.', hashtags: '#Marigold #NewCollection', mediaIds: ['media-4', 'media-5'], status: 'published', scheduledAt: null, createdAt: at(0, 6), createdBy: 'user-1', platforms: [success('instagram'), success('tiktok')], metrics: { instagram: metrics(21900, 5310, 188, 241, 1200), tiktok: metrics(38800, 6100, 330, 480, 740) } },
      { id: 'post-3', title: 'Cerita di Balik Layar', type: 'photo', caption: 'Yang tidak terlihat dari proses kreatif kami.', hashtags: '#BehindTheScene', mediaIds: ['media-3'], status: 'published', scheduledAt: null, createdAt: at(0, 9), createdBy: 'user-2', platforms: [success('instagram'), success('facebook')], metrics: { instagram: metrics(12400, 1920, 91, 103, 344), facebook: metrics(25700, 3600, 274, 610, 51) } },
      { id: 'post-4', title: 'Morning Routine', type: 'video', caption: 'Mulai hari dengan ritme yang tepat.', hashtags: '#MorningRoutine', mediaIds: ['media-6'], status: 'scheduled', scheduledAt: at(0, now.getDate() + 2, 9), createdAt: at(0, 10), createdBy: 'user-2', platforms: [{ platform: 'instagram', status: 'pending', settings: {} }, { platform: 'tiktok', status: 'pending', settings: {} }], metrics: {} },
      { id: 'post-5', title: 'Promo Akhir Pekan', type: 'story', caption: '', hashtags: '', mediaIds: ['media-1'], status: 'scheduled', scheduledAt: at(0, now.getDate() + 4, 16), createdAt: at(0, 11), createdBy: 'user-1', platforms: [{ platform: 'instagram', status: 'pending', settings: {} }, { platform: 'facebook', status: 'pending', settings: {} }], metrics: {} },
      { id: 'post-old', title: 'Launch Agustus', type: 'video', caption: 'A new chapter.', hashtags: '#Launch', mediaIds: ['media-2'], status: 'published', scheduledAt: null, createdAt: at(-1, 14), createdBy: 'user-1', platforms: [success('instagram'), success('tiktok'), success('facebook')], metrics: { instagram: metrics(18000, 2800, 180, 121, 340), tiktok: metrics(46000, 5900, 410, 620, 590), facebook: metrics(14200, 1700, 110, 210, 42) } },
    ],
    accounts: [
      { id: 'account-1', platform: 'instagram', accountName: 'SocialSync Official', handle: '@socialsync.id', status: 'connected', expiresAt: at(2, 22), followers: 28400 },
      { id: 'account-2', platform: 'tiktok', accountName: 'SocialSync Creative', handle: '@socialsync.creative', status: 'connected', expiresAt: at(1, 17), followers: 52100 },
      { id: 'account-3', platform: 'facebook', accountName: 'SocialSync Indonesia', handle: 'SocialSync Indonesia', status: 'expired', expiresAt: at(0, 2), followers: 19700 },
    ],
    bufferConnection: { configured: true, status: 'active', accountName: 'Nadia Putri', accountEmail: 'nadia@example.com', organizationName: 'SocialSync', channelCount: 3, maxChannels: 3, limitReached: true, manageUrl: 'https://publish.buffer.com/channels', lastSyncedAt: new Date().toISOString(), lastError: null },
    driveConnection: { provider: 'google-drive', accountEmail: '', connected: false, connectedAt: null },
    notifications: [
      { id: 'notif-1', type: 'warning', title: 'Koneksi Facebook perlu diperbarui', message: 'Sesi SocialSync Indonesia telah berakhir. Hubungkan kembali agar jadwal tidak terganggu.', read: false, createdAt: at(0, now.getDate(), 8) },
      { id: 'notif-2', type: 'success', title: 'Post berhasil diterbitkan', message: 'Koleksi Marigold telah tayang di Instagram dan TikTok.', read: false, createdAt: at(0, 6, 13) },
    ],
    activities: [
      { id: 'activity-1', actor: 'Nadia Putri', action: 'menjadwalkan', subject: 'Promo Akhir Pekan', createdAt: at(0, 11) },
      { id: 'activity-2', actor: 'Raka Pratama', action: 'mengunggah media', subject: 'Morning Routine Reel', createdAt: at(0, 8) },
    ],
  }
}
