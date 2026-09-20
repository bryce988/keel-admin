/**
 * 后端地址与客户端版本
 *
 * 这个 App 面向**系统人员**：身份走管理端（sys_users、同一套权限点），
 * 但接口是**员工移动端自己的一套** `/staff/v1/*`，不是直接调 `/admin/*`——
 * 后台接口是给宽屏与完整表单设计的，而移动端要聚合与瘦身，
 * 且迟早要长出强制更新、推送注册这类后台没有的东西（PROJECT.md §8.1）。
 *
 * ## BASE_URL 按环境自动选，不要手改
 *
 * 此前这里固定写死线上预览地址，要连本地后端就得临时改一行、提交前再改回去。
 * 忘一次，别人拉下来就连不上后端——而且表现是「登录转圈」，指不到这一行。
 *
 * 现在分三种情况自己判断：
 *
 * | 场景 | 取值 |
 * |---|---|
 * | 发行版（`NODE_ENV=production`） | 线上预览地址 |
 * | 开发 + H5（含手机浏览器访问 dev server） | `location.hostname` + `:8787` |
 * | 开发 + 真机基座 / 模拟器 | `DEV_HOST` 填了就用它，留空回落线上 |
 *
 * **H5 那条是关键**：手机访问的是 `http://192.168.1.8:5173`，`hostname` 就是开发机地址，
 * 直接借用即可。换 wifi、换机器、换同事都不用改配置；电脑上访问 localhost 也一样成立。
 * 手写 IP 只剩真机基座一种场景，因为它没有 `location` 可借。
 *
 * ⚠️ 连本地后端时，后端的 `CORS_ALLOW_ORIGINS` 必须放行局域网段
 * （默认只有 `http://localhost:*`），否则预检就被拦，浏览器只报一句「跨域失败」。
 */

/** 线上预览环境。发行版与「开发但没法自动推断」时都用它 */
const PROD_URL = 'http://43.143.249.52:8080'

/**
 * 真机基座 / 模拟器连本地后端时，填开发机的局域网 IP（如 `192.168.1.8`）。
 * **不能写 localhost**——那是手机自己的回环地址，手机上没跑我们的服务。
 * 留空则回落到线上，不会静默连到一个不存在的后端。
 * H5 用不到它（走 location.hostname 自动推断）。
 */
const DEV_HOST = ''

function resolveBaseUrl() {
	if (process.env.NODE_ENV !== 'development') return PROD_URL

	// #ifdef H5
	// 手机访问的就是开发机的地址，端口换成后端的即可
	return `http://${location.hostname}:8787`
	// #endif

	// #ifndef H5
	return DEV_HOST ? `http://${DEV_HOST}:8787` : PROD_URL
	// #endif
}

export const BASE_URL = resolveBaseUrl()

/**
 * 渠道标识与版本
 *
 * `/staff/v1/*` 与 C 端一样强制要求 X-Channel / X-App-Version / X-Device-Id，
 * 缺一个就是 400。灰度、强制更新、风控、埋点都要用它们，所以在入口就拦。
 * 打包 iOS 时 CHANNEL 改成 app-ios；浏览器里预览用 h5——后端只认登记过的渠道。
 */
export const CHANNEL = 'app-android'

/** 也显示在「我的」页底部，出问题时让人一眼看出手上装的是哪一版 */
export const APP_VERSION = '1.1.0'
