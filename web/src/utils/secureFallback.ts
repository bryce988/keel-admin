/**
 * 「安全上下文」专属 API 的兜底
 *
 * ⚠️ `crypto.randomUUID()` 与 `navigator.clipboard` 只在安全上下文（HTTPS 或 localhost）里存在。
 * 开发走 localhost 一切正常，而预览环境是 `http://IP:8080`——两者在那里直接是 undefined，
 * 表现是线上点「发送」抛 `crypto.randomUUID is not a function`、消息发不出去。
 * 本地永远复现不了，所以凡是用到这类 API 的地方都走这里，不要直接调。
 */

/**
 * UUID v4
 *
 * 优先用原生的；没有就用 `crypto.getRandomValues` 拼——它不要求安全上下文，
 * 随机性与 randomUUID 同源（CSPRNG），用作消息幂等 ID 足够
 */
export function uuid(): string {
  if (typeof crypto.randomUUID === 'function') return crypto.randomUUID()

  const b = crypto.getRandomValues(new Uint8Array(16))
  b[6] = (b[6] & 0x0f) | 0x40 // 版本 4
  b[8] = (b[8] & 0x3f) | 0x80 // RFC 4122 变体

  const hex = Array.from(b, (x) => x.toString(16).padStart(2, '0')).join('')
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}

/**
 * 写剪贴板
 *
 * 优先用异步剪贴板 API；没有（非安全上下文）或被拒绝时，退回「选中一个隐藏的
 * textarea + execCommand('copy')」。后者已被标为废弃，但各浏览器仍支持，
 * 而且是 http 页面下唯一可用的办法。两条都失败才返回 false
 */
export async function copyText(text: string): Promise<boolean> {
  if (navigator.clipboard?.writeText) {
    try {
      await navigator.clipboard.writeText(text)
      return true
    } catch {
      /* 用户拒绝或不支持，走下面的兜底 */
    }
  }

  const ta = document.createElement('textarea')
  ta.value = text
  // 放在视口外且只读：不闪一下、不弹移动端键盘、不把页面滚走
  ta.setAttribute('readonly', '')
  ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0'
  document.body.appendChild(ta)
  ta.select()

  try {
    return document.execCommand('copy')
  } catch {
    return false
  } finally {
    document.body.removeChild(ta)
  }
}
