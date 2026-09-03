import request from '@/utils/request'
import { download } from '@/utils/request'
import type { PageResult, TableQuery } from '@/types/api'

/**
 * 数据导出（异步）
 *
 * 发起导出的接口**不在这里**，在各业务模块自己的 api 里（`/admin/users/export` 等），
 * 因为「谁能导出用户」是用户模块的权限。这里只有任务列表、下载与删除。
 *
 * 发起接口现在返回 `202` + `{task_id, message}`，不再直接给文件流——
 * 调用方要提示「已加入队列」，而不是等一个下载。
 */

export interface ExportTaskRow {
  id: number
  /** 业务标识，见后端 ExportService::BIZ；字典 export_biz */
  biz: string
  biz_name: string
  /** 0 排队 · 1 处理中 · 2 已完成 · 3 失败；字典 export_status */
  status: number
  row_count: number
  file_name: string
  file_size: number
  error_msg: string
  creator_name: string
  expired_at: string | null
  finished_at: string | null
  created_at: string
  /**
   * 能不能下载**由服务端算**，前端别自己按 status 判
   *
   * 文件会被回收（过期、容器重建把 runtime 清了），而 status 仍是「已完成」。
   * 只看 status 的话下载按钮点下去才发现文件没了。
   */
  downloadable: boolean
}

/** 发起导出后后端的回执 */
export interface ExportAccepted {
  task_id: number
  message: string
}

export function fetchExportTasks(params: TableQuery) {
  return request.get<unknown, PageResult<ExportTaskRow>>('/admin/exports', { params })
}

/** 文件名由后端的 Content-Disposition 决定，这里的兜底名只在拿不到头时用得上 */
export function downloadExportTask(row: ExportTaskRow) {
  return download(`/admin/exports/${row.id}/download`, undefined, row.file_name || 'export.xlsx')
}

export function deleteExportTask(id: number) {
  return request.delete<unknown, void>(`/admin/exports/${id}`)
}
