import { onMounted, onUnmounted, ref } from 'vue'
import { ElMessage } from 'element-plus'

/**
 * 全屏切换
 *
 * ## 状态必须从 document 读，不能自己记一个布尔值
 *
 * 用户按 **Esc** 或 **F11** 退出全屏时不经过我们的按钮。自己记状态的话，
 * 图标会停在「退出全屏」上，再点一下反而又进了全屏——按钮和实际状态对不上。
 * 所以 `isFullscreen` 只由 `fullscreenchange` 事件驱动，
 * 点击函数本身不改状态，只负责发起请求。
 *
 * ## Safari 的前缀
 *
 * Safari 直到 16.4 才支持不带前缀的 `Element.requestFullscreen`，
 * `document.exitFullscreen` 至今仍要 `webkitExitFullscreen`。
 * 三个 API（进、出、读状态）各留一条回退，缺一个都会在 Safari 上半残——
 * 比如只回退了「进」，那进得去出不来，只能按 Esc。
 *
 * ## 失败要出声
 *
 * `requestFullscreen()` 返回 Promise，且**会 reject**：不是用户手势触发、
 * 或者被 iframe 的 `allow-fullscreen` 挡住时都会。不 catch 的话是一个
 * 未处理的 rejection——控制台报错，界面上则是「点了没反应」，最难排查的那种。
 */

/** 各家前缀的类型补丁：TS 的 lib.dom 里只有标准名 */
interface FullscreenDocument extends Document {
  webkitFullscreenElement?: Element | null
  webkitExitFullscreen?: () => Promise<void>
}

interface FullscreenElement extends HTMLElement {
  webkitRequestFullscreen?: () => Promise<void>
}

/** 浏览器是否支持——不支持时顶栏那个按钮直接不渲染，而不是点了没反应 */
export function fullscreenSupported(): boolean {
  const el = document.documentElement as FullscreenElement

  return Boolean(el.requestFullscreen || el.webkitRequestFullscreen)
}

export function useFullscreen() {
  const isFullscreen = ref(false)

  function sync() {
    const doc = document as FullscreenDocument
    isFullscreen.value = Boolean(doc.fullscreenElement ?? doc.webkitFullscreenElement)
  }

  async function toggle() {
    const doc = document as FullscreenDocument
    const el = document.documentElement as FullscreenElement

    try {
      if (isFullscreen.value) {
        await (doc.exitFullscreen?.() ?? doc.webkitExitFullscreen?.())
      } else {
        await (el.requestFullscreen?.() ?? el.webkitRequestFullscreen?.())
      }
    } catch {
      ElMessage.warning('当前浏览器不允许进入全屏')
    }
  }

  onMounted(() => {
    sync()
    document.addEventListener('fullscreenchange', sync)
    // Safari 只发前缀事件，两个都听；同一次变化最多把状态算两遍，无副作用
    document.addEventListener('webkitfullscreenchange', sync)
  })

  onUnmounted(() => {
    document.removeEventListener('fullscreenchange', sync)
    document.removeEventListener('webkitfullscreenchange', sync)
  })

  return { isFullscreen, toggle }
}
