/**
 * 通用组件的**类型**出口
 *
 * 这里只导出类型。组件本体不从这里走，也不再提供 `app.use()` 的全局注册。
 *
 * ## 为什么去掉全局注册
 *
 * `src/components/` 是 unplugin-vue-components 的默认扫描目录，用到 `<ProTable>`
 * 的页面**早就**被插件按页注入了 import——`src/types/components.d.ts` 里列着。
 * 也就是说原先 `main.ts` 里的 `app.use(components)` 一个组件都没多注册，
 * 纯粹是历史遗留的第二条路径。
 *
 * 但它不是无害的。这个文件为了注册要静态 import 全部九个组件，而 `main.ts` 又静态
 * import 了这个文件，于是整条链被钉进**首屏** chunk：`ProTable` 带着 `el-table`，
 * `SearchForm` 带着 `el-date-picker` 与 `el-time-picker`——三个加起来 320KB（未压缩），
 * 连只有一个输入框的登录页都得先下完。
 *
 * 删掉之后这些组件只被懒加载的页面引用，自然落进异步 chunk。
 *
 * ⚠️ 别把组件本体重新 `export` 回来：只要有一处在首屏路径上按值 import 这个 barrel，
 * 上面那条链就原样回来了。页面要用直接写 `<ProTable>`（插件注入），
 * 或显式 `import ProTable from '@/components/ProTable.vue'`。
 * `src/layout/` 与 `src/views/` 不在扫描目录里，那边本来就是显式 import。
 */
export type { ProColumn } from './ProTable.vue'
/*
 * PageResult / TableQuery 不在这里转出：它们是接口契约，住在 `@/types/api`。
 * 组件 barrel 转出接口类型会让 `api/*.ts` 反过来依赖组件层。
 */
export type { SearchField } from './SearchForm.vue'
/*
 * 表单壳的类型在 composable 里，两个组件共用。
 * 旧名 FormDrawerOptions / FormDrawerInstance 保留为别名——十几个页面在用，
 * 为改个名字去动它们不值当，而且「抽屉的实例类型」这个叫法在抽屉那边仍然准确。
 */
export type {
  FormShellOptions,
  FormShellInstance,
  FormShellOptions as FormDrawerOptions,
  FormShellInstance as FormDrawerInstance
} from '@/composables/useFormShell'

/** 列表页拿 ref 用的实例类型，页面不用各写各的内联注解 */
export interface ProTableInstance {
  reload: () => void
  refresh: () => void
}
