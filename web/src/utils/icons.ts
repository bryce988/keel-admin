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
 * 没有单图标入口。而布局外壳（layout、ProTable、SearchForm）静态 import 了
 * Search / Refresh / Fold 等约 25 个图标——只要有一处静态 import，这个模块就落在
 * 主 chunk 里；此时再写 `import('@element-plus/icons-vue')` 拿全量，
 * Rollup 只会把它**并回**主 chunk（实测：加上动态 import 后主 chunk 从 674KB 涨到 818KB，
 * 而不是多出一个异步 chunk）。写成异步只会平添一个永远瞬时完成的 loading 态。
 *
 * 要真正把这 144KB（gzip 38KB）拆出去，唯一的办法是**外壳里一个图标都不从这个包静态引**，
 * 也就是把那 25 个图标的 SVG 抄成本地组件。代价是与 EP 版本漂移、且违背「不写死资源」的取向，
 * 收益是首屏 JS 从 gzip 270KB 降到 232KB。目前判断不值，如果哪天首屏预算收紧，
 * 这就是下一个可动的地方。
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
