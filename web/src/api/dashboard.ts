import request from '@/utils/request'

/**
 * 系统概览接口
 *
 * 只汇总系统自身已有的模块，不含业务指标——脚手架里摆假数字，
 * 接业务的人第一件事还是得全删掉。
 */

export interface StatCard {
  key: string
  label: string
  value: number
  unit: string
  hint: string
  tone: 'primary' | 'success' | 'warning' | 'danger' | 'info'
  /** 点击跳转的页面 */
  to: string
  /** 没有这个权限时不给跳，卡片本身仍然显示（值已经由后端按数据权限算过） */
  perm: string
  extra?: Record<string, number>
}

export interface TrendPoint {
  day: string
  label: string
  total: number
  success: number
  failed: number
}

export interface RecentOperation {
  id: number
  username: string
  module: string
  action: number
  title: string
  target: string
  status: number
  error_msg: string
  created_at: string
}

export interface ModuleSummary {
  name: string
  count: number
  to: string
  perm: string
}

export interface SystemStatus {
  php_version: string
  workerman: string
  memory_mb: number
  memory_peak_mb: number
  db: boolean
  redis: boolean
  slow_query_ms: number
  server_time: string
}

export interface Overview {
  stats: StatCard[]
  trend: TrendPoint[]
  recent: RecentOperation[]
  modules: ModuleSummary[]
  system: SystemStatus
}

export function fetchOverview() {
  return request.get<unknown, Overview>('/admin/dashboard/overview')
}
