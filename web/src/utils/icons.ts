import * as ElIcons from '@element-plus/icons-vue'
import { Menu } from '@element-plus/icons-vue'
import type { Component } from 'vue'

/**
 * 菜单图标的按名解析
 *
 * 后端菜单表存的是 Element Plus 的图标名（如 `Odometer`），前端按名取组件。
 * 原来这件事散在三个文件里各写一遍 `import * as ElIcons`，还在 `main.ts` 里
 * 用 `app.component()` 把 293 个图标全部注册成全局组件。收口到这里之后：
 *
 * - 启动时少注册 293 个全局组件
 * - 模板不再依赖「图标是全局组件」这个隐含前提（全仓已核对，都是显式 import）
 * - 兜底策略只有一处，不会出现「侧边栏兜底成 Menu、菜单管理页兜底成空」这种不一致
 *
 * ## 关于打包体积：这里**没有**做懒加载，原因如下
 *
 * `@element-plus/icons-vue` 发布的是**一个** `dist/index.js`，293 个图标全在里面，
 * 没有单图标入口。而布局外壳（layout、TagsView、MenuSearch）静态 import 了
 * Fold / Search / Moon 等十来个图标——只要有一处静态 import，这个模块就落在
 * 首屏 chunk 里；此时再写 `import('@element-plus/icons-vue')` 拿全量，
 * Rollup 只会把它**并回**首屏（实测：加上动态 import 后主 chunk 从 674KB 涨到 818KB，
 * 而不是多出一个异步 chunk）。Rollup 是按模块分包的，而这个包只有一个模块：
 * 静态可达就整个进首屏，动态那侧要用到全部导出，两边取并集就是 293 个。
 *
 * 它现在被 `vite.config.ts` 的 manualChunks 单独切成 `icons` chunk：
 * 171KB / gzip 44KB，占首屏 JS（gzip 135KB）的三分之一。切出来不为省字节
 * ——照样首屏加载——是因为它内容固定，值得单独长期缓存。
 *
 * 要真正让它变成按需加载，唯一的办法是**首屏一个图标都不从这个包静态引**，
 * 把那十来个外壳图标的 SVG 抄成本地组件。但真正的拦路虎不是抄图标，是**侧边栏**：
 * 菜单图标名由后端下发，可以是 293 个里的任意一个。改成异步之后，用户给菜单选了
 * `Football`，侧边栏就会先显示兜底的 `Menu` 再跳成 `Football`——
 * 而这是脚手架的常规用法，不是边角情况。省 44KB 换全站侧边栏图标闪一下，不值。
 *
 * 换句话说：这 44KB 不是「还没来得及优化」，是 DB 驱动图标名的固有代价。
 * 真要动，得先改设计（比如把可选图标收敛成一个白名单），而不是改打包配置。
 */

/**
 * 侧边栏用：一定返回一个能渲染的组件，解析不到用 `Menu` 兜底
 *
 * 菜单少一个图标是小事，抛渲染错误把整个侧边栏搞白是大事。
 */
export function resolveMenuIcon(name?: string | null): Component {
  if (!name) return Menu

  return ((ElIcons as unknown as Record<string, Component>)[name] ?? Menu)
}

/**
 * 列表/详情用：解析不到返回 `undefined`，由调用方显示「—」
 *
 * 与 `resolveMenuIcon` 的区别只在兜底：管理菜单时把一个填错的图标名显示成 Menu，
 * 会让人以为填对了。
 */
export function resolveIconOrNone(name?: string | null): Component | undefined {
  if (!name) return undefined

  return (ElIcons as unknown as Record<string, Component>)[name]
}

/** 图标全集，只有图标选择器需要（它要列出所有图标供人挑） */
export function allIconNames(): string[] {
  return Object.keys(ElIcons).sort()
}
