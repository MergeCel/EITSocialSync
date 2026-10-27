import type { Metrics, Platform, Post } from './types'

export const formatNumber = (value: number) => new Intl.NumberFormat('id-ID', { notation: value >= 1000 ? 'compact' : 'standard', maximumFractionDigits: 1 }).format(value)
export const formatFullNumber = (value: number) => new Intl.NumberFormat('id-ID').format(value)
export const formatDate = (value: string, withTime = false) => new Intl.DateTimeFormat('id-ID', {
  day: 'numeric', month: 'short', year: 'numeric', ...(withTime ? { hour: '2-digit', minute: '2-digit' } : {}),
}).format(new Date(value))
export const formatBytes = (bytes: number) => {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / 1024 / 1024).toFixed(bytes > 10_000_000 ? 0 : 1)} MB`
}
export const engagement = (metric?: Metrics) => metric ? metric.likes + metric.comments + metric.shares + metric.saves : 0
export const latestMetric = (post: Post, platform: Platform) => post.metrics[platform]
export const isThisMonth = (value: string) => {
  const date = new Date(value)
  const now = new Date()
  return date.getMonth() === now.getMonth() && date.getFullYear() === now.getFullYear()
}

export const isLastMonth = (value: string) => {
  const date = new Date(value)
  const last = new Date()
  last.setDate(1)
  last.setMonth(last.getMonth() - 1)
  return date.getMonth() === last.getMonth() && date.getFullYear() === last.getFullYear()
}

export const timeAgo = (value?: string) => {
  if (!value) return 'Belum ada pembaruan'
  const seconds = Math.max(0, Math.floor((Date.now() - new Date(value).getTime()) / 1000))
  if (seconds < 60) return 'baru saja'
  if (seconds < 3600) return `${Math.floor(seconds / 60)} menit lalu`
  if (seconds < 86400) return `${Math.floor(seconds / 3600)} jam lalu`
  return `${Math.floor(seconds / 86400)} hari lalu`
}

export const platformColor: Record<Platform, string> = {
  instagram: '#b33b78',
  tiktok: '#15191f',
  facebook: '#2474d2',
}
