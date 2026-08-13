import axios, { type AxiosError, type AxiosInstance } from 'axios'
import { ElMessage } from 'element-plus'

/**
 * 统一请求封装
 *
 * 契约见 docs/api.md §1.2：
 * - 成功只有 2xx，响应体就是数据本体，不含 code 信封
 * - 错误走 4xx/5xx，响应体为 { code, message, traceId, details? }
 * 因此这里的判断顺序是：先看 HTTP 状态码（网络/服务层），再看业务码（细化交互）
 */

export interface ApiError {
  code: number
  message: string
  traceId: string
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
  (res) => res.data,

  (err: AxiosError<ApiError>) => {
    // 无响应：断网、超时、服务没起来
    if (!err.response) {
      ElMessage.error('网络异常，请检查连接后重试')
      return Promise.reject(new BizError(0, 0, '网络异常'))
    }

    const { status, data } = err.response
    const { code = 0, message = '请求失败', traceId = '', details } = data ?? {}

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

export default request
