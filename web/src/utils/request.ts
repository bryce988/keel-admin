import axios, { type AxiosError, type AxiosInstance, type AxiosResponse } from 'axios'
import { ElMessage } from 'element-plus'

/**
 * 统一请求封装
 *
 * 契约见 docs/api.md §1.2：
 * - 成功只有 2xx，响应体就是数据本体，不含 code 信封
 * - 错误走 4xx/5xx，响应体为 { code, message, trace_id, details? }
 * - 接口字段一律 snake_case；BizError 是前端自己的对象，属性用小驼峰
 * 因此这里的判断顺序是：先看 HTTP 状态码（网络/服务层），再看业务码（细化交互）
 */

export interface ApiError {
  code: number
  message: string
  trace_id: string
  details?: Record<string, string[]>
}

/** 业务异常：调用方需要针对具体 code 做交互时可捕获 */
export class BizError extends Error {
  constructor(
    public status: number,
    public code: number,
    message: string,
    public traceId = '',
    public details?: Record<string, string[]>
  ) {
    super(message)
    this.name = 'BizError'
  }
}

let onUnauthorized: (() => void) | null = null
export function setUnauthorizedHandler(fn: () => void) {
  onUnauthorized = fn
}

const request: AxiosInstance = axios.create({
  baseURL: import.meta.env.VITE_API_BASE ?? '',
  timeout: 15000
})

request.interceptors.request.use((config) => {
  const token = localStorage.getItem('keel_token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

request.interceptors.response.use(
  // 2xx：直接把数据本体交给调用方
  // 例外是文件下载：blob 请求要留住整个响应，文件名在 Content-Disposition 头里
  (res) => (res.config.responseType === 'blob' ? res : res.data),

  async (err: AxiosError<ApiError>) => {
    // 无响应：断网、超时、服务没起来
    if (!err.response) {
      ElMessage.error('网络异常，请检查连接后重试')
      return Promise.reject(new BizError(0, 0, '网络异常'))
    }

    // 下载失败时后端返回的是 JSON，但因为 responseType=blob 被包成了 Blob，
    // 不解开的话错误提示会变成 "[object Blob]"
    if (err.response.data instanceof Blob) {
      try {
        err.response.data = JSON.parse(await err.response.data.text())
      } catch {
        // 不是 JSON 就维持原样，下面按状态码兜底提示
      }
    }

    const { status, data } = err.response
    const { code = 0, message = '请求失败', trace_id: traceId = '', details } = data ?? {}

    switch (status) {
      case 401:
        // 登录页自己处理错误提示，不跳转
        if (!location.pathname.startsWith('/login')) {
          onUnauthorized?.()
        }
        break
      case 403:
        ElMessage.error(message || '无权限访问')
        break
      case 404:
        ElMessage.error(message || '数据不存在或已被删除')
        break
      case 422:
        // 字段级错误交给表单处理，这里不弹提示
        break
      case 429:
        ElMessage.warning(message || '操作过于频繁，请稍后再试')
        break
      default:
        ElMessage.error(
          status >= 500 ? `服务暂时不可用（${traceId}）` : message
        )
    }

    return Promise.reject(new BizError(status, code, message, traceId, details))
  }
)

/**
 * 下载文件
 *
 * 不能用 `<a href>` 直接指过去——那样带不上 Authorization 头，后端会当未登录拒掉。
 * 所以走 axios 拿 blob，再在前端造一个临时链接点一下。
 *
 * 文件名优先取 `filename*=UTF-8''`（RFC 6266），中文名只有这种形式不会乱码；
 * 取不到再退回 `filename="..."`，最后才用调用方给的兜底名。
 */
export async function download(
  url: string,
  params?: Record<string, unknown>,
  fallbackName = 'download'
): Promise<void> {
  const res = (await request.get(url, {
    params,
    responseType: 'blob'
  })) as unknown as AxiosResponse<Blob>

  const disposition = String(res.headers['content-disposition'] ?? '')
  const encoded = /filename\*=UTF-8''([^;]+)/i.exec(disposition)?.[1]
  const plain = /filename="?([^";]+)"?/i.exec(disposition)?.[1]

  const filename = encoded ? decodeURIComponent(encoded) : (plain ?? fallbackName)

  const href = URL.createObjectURL(res.data)
  const link = document.createElement('a')
  link.href = href
  link.download = filename
  document.body.appendChild(link)
  link.click()

  // 立刻回收，否则这份 blob 会一直占着内存直到页面关闭
  document.body.removeChild(link)
  URL.revokeObjectURL(href)
}

export default request
