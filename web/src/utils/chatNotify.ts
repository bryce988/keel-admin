/**
 * 新消息提醒：桌面通知 + 提示音
 *
 * 两个开关都存 `localStorage`，不入库。它们是**每个浏览器各自的偏好**——
 * 同一个人在办公室电脑上要声音、在会议室的公共电脑上不要，存服务端反而错。
 * M3 定过「不建通用通知中心」，这里也不为两个开关去改个人中心的接口。
 *
 * 提示音用 Web Audio 合成，不引音频文件：一个 mp3 要进构建产物、要处理
 * 加载失败、还要挑一个不难听的音色；而这里需要的只是「响一下」。
 */

const KEY_DESKTOP = 'keel_chat_notify'
const KEY_SOUND = 'keel_chat_sound'

/** 默认开。用户不知道有这功能之前，静默才是意外 */
function read(key: string): boolean {
  try {
    return localStorage.getItem(key) !== '0'
  } catch {
    // 隐私模式下 localStorage 会抛，当成默认值
    return true
  }
}

function write(key: string, on: boolean): void {
  try {
    localStorage.setItem(key, on ? '1' : '0')
  } catch {
    /* 存不了就只在本次会话生效 */
  }
}

export const notifyPrefs = {
  get desktop() {
    return read(KEY_DESKTOP)
  },
  set desktop(on: boolean) {
    write(KEY_DESKTOP, on)
  },
  get sound() {
    return read(KEY_SOUND)
  },
  set sound(on: boolean) {
    write(KEY_SOUND, on)
  },
}

/**
 * 请求通知权限
 *
 * **只在用户主动打开开关时调**，不在页面加载时调：一进后台就弹权限框是
 * 最招人烦的做法，而且 Chrome 会把这种请求直接判为 spam 并永久拒绝。
 */
export async function requestPermission(): Promise<boolean> {
  if (!('Notification' in window)) return false
  if (Notification.permission === 'granted') return true
  if (Notification.permission === 'denied') return false

  return (await Notification.requestPermission()) === 'granted'
}

let lastSoundAt = 0

/**
 * 提示音
 *
 * 1 秒内只响一次：群里刷屏时每条都响会把人逼疯。
 *
 * ⚠️ 浏览器要求音频必须由用户手势触发过一次才能自动播放。所以第一次可能
 * 无声——这是浏览器的策略，不是 bug；用户点过页面之后就正常了。
 */
function beep(): void {
  const now = Date.now()
  if (now - lastSoundAt < 1000) return
  lastSoundAt = now

  try {
    const Ctx = window.AudioContext || (window as unknown as { webkitAudioContext: typeof AudioContext }).webkitAudioContext
    const ctx = new Ctx()
    const osc = ctx.createOscillator()
    const gain = ctx.createGain()

    osc.connect(gain)
    gain.connect(ctx.destination)
    osc.frequency.value = 880
    osc.type = 'sine'

    // 短促地淡出，不然会留一声「嘟」的尾音
    gain.gain.setValueAtTime(0.08, ctx.currentTime)
    gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.18)

    osc.start()
    osc.stop(ctx.currentTime + 0.2)
    osc.onended = () => void ctx.close()
  } catch {
    /* 音频不可用就算了，红点还在 */
  }
}

/**
 * 有新消息
 *
 * **只在窗口不可见时弹桌面通知**：用户正看着这个页面还弹一个系统通知，
 * 是重复打扰。提示音则不分前后台——前台时它是「有人找你」的即时反馈。
 *
 * @param onClick 点击通知后要做的事（聚焦窗口并打开对应会话）
 */
export function notifyNewMessage(
  title: string,
  body: string,
  onClick?: () => void,
): void {
  if (notifyPrefs.sound) beep()

  if (!notifyPrefs.desktop || !document.hidden) return
  if (!('Notification' in window) || Notification.permission !== 'granted') return

  try {
    const n = new Notification(title, {
      body: body.slice(0, 120),
      // 同一个 tag 的通知会互相替换，不会在通知中心堆成一列
      tag: 'keel-chat',
    })

    n.onclick = () => {
      window.focus()
      n.close()
      onClick?.()
    }
  } catch {
    /* 通知构造失败（某些浏览器要求 ServiceWorker）不影响其他 */
  }
}
