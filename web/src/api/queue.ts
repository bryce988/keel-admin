import request from '@/utils/request'
import type { PageResult, TableQuery } from '@/types/api'

/**
 * 队列监控
 *
 * 数据全部来自 Redis 的实时状态，后端没有对应的表，所以这里也没有「详情」接口
 * ——失败任务的全部信息就在列表行里。
 */

export interface QueueRow {
  /** 队列名，如 keel:log-cleanup */
  queue: string
  /**
   * 这个队列是干什么的
   *
   * 由消费者自己声明（`app/queue/XxxConsumer.php` 的 `public string $desc`），
   * 没写就是空串——后端不按类名编一个假说明。
   */
  desc: string
  /** 消费者类名，空串表示没有消费者 */
  consumer: string
  connection: string
  /** 有积压却没人消费：消息只会越堆越多，这是本页最该被看见的异常 */
  orphan: boolean
  waiting: number
  /**
   * ⚠️ 延迟与失败是**所有队列混在一条 Redis 结构里**的，
   * 这两个数只统计采样窗口（`redis.scan_limit`）内的部分。
   * `redis.sampled` 为真时它们会小于 `redis.delayed_total` / `failed_total`，
   * 这不是 bug，是积压已经超出窗口的信号。
   */
  delayed: number
  failed: number
}

export interface QueueRedisStatus {
  connected: boolean
  /** 连不上时才有，其余字段此时全部缺省 */
  error?: string
  db: number
  used_memory: string
  delayed_total: number
  failed_total: number
  sampled: boolean
  scan_limit: number
  max_attempts: number
  retry_seconds: number
}

export interface QueueProcesses {
  http: number
  task: number
  consumer: number
  consumer_dir: string
  queue_workers: number
  /** 响应这次请求的 HTTP worker 自报的 pid 与常驻内存 */
  pid: number
  memory_mb: number
}

export interface QueueTaskRow {
  name: string
  /** cron 规则，5 段或 6 段 */
  rule: string
  queue: string
  desc: string
  consumer: string
  /** 定时任务投的队列没有消费者：任务照跑，活没人接 */
  orphan: boolean
  /** 两天之内没有下一次的（比如一年一次的规则）为 null */
  next_run: string | null
}

export interface QueueOverview {
  queues: QueueRow[]
  redis: QueueRedisStatus
  processes: QueueProcesses
  tasks: QueueTaskRow[]
}

export interface FailedJobRow {
  /** 队列消息 id，字符串（`time().rand()` 拼的，不是自增数） */
  id: string
  queue: string
  attempts: number
  max_attempts: number
  delay: number
  created_at: string
  /** 投递时的业务参数，JSON 字符串；超过 2KB 由后端截断 */
  data: string
}

export function fetchQueueOverview() {
  return request.get<unknown, QueueOverview>('/admin/queues')
}

export function fetchFailedJobs(params: TableQuery) {
  return request.get<unknown, PageResult<FailedJobRow>>('/admin/queues/failed', { params })
}

/** 重投：attempts 归零后回到原队列，消费者会当成新消息再跑一遍 */
export function retryFailedJob(id: string) {
  return request.post<unknown, FailedJobRow>(`/admin/queues/failed/${id}/retry`)
}

/** 丢弃：Redis 之外没有第二份，删掉不可恢复 */
export function discardFailedJob(id: string) {
  return request.delete<unknown, void>(`/admin/queues/failed/${id}`)
}

/**
 * 定时任务执行记录
 *
 * 这是队列监控里**唯一落库**的东西：Redis 只有此刻的队列状态，消费成功的消息
 * 消费完就没了，「昨天凌晨那次清理跑没跑、删了多少行」只能靠这张表回答。
 */
export interface TaskLogRow {
  id: number
  /** 任务标识，见后端 TaskProcess::TASKS 的 name */
  task_name: string
  /** 投递那一刻的任务说明，冗余存储：改了代码里的措辞，历史记录仍是当时那句 */
  task_desc: string
  queue: string
  trigger: string
  /**
   * 0 排队中 · 1 成功 · 2 失败；字典 task_log_status
   *
   * ⚠️「排队中」不只是过渡态：投出去没人消费时会一直停在这里，那是故障信号。
   */
  status: number
  /** 成功时是结果摘要（清理任务会给出各表删除行数），失败时是错误信息 */
  message: string
  /** 消费耗时（毫秒），不含排队时间 */
  duration_ms: number
  finished_at: string | null
  /** 投递时间 */
  created_at: string
}

export function fetchTaskLogs(params: TableQuery) {
  return request.get<unknown, PageResult<TaskLogRow>>('/admin/queues/tasks/logs', { params })
}
