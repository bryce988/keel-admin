import type { App } from 'vue'
import ProTable from './ProTable.vue'
import SearchForm from './SearchForm.vue'
import FormDrawer from './FormDrawer.vue'
import DictSelect from './DictSelect.vue'
import DictTag from './DictTag.vue'

export { ProTable, SearchForm, FormDrawer, DictSelect, DictTag }
export type { ProColumn, PageResult, TableQuery } from './ProTable.vue'
export type { SearchField } from './SearchForm.vue'
export type { FormDrawerOptions, FormDrawerInstance } from './FormDrawer.vue'

/** 列表页拿 ref 用的实例类型，页面不用各写各的内联注解 */
export interface ProTableInstance {
  reload: () => void
  refresh: () => void
}

/**
 * 全局注册
 *
 * 这五个组件在每个列表页都会用到，逐页 import 只是噪音。
 * 业务组件不要往这里加——全局注册的东西进不了 tree-shaking。
 */
export default {
  install(app: App) {
    app.component('ProTable', ProTable)
    app.component('SearchForm', SearchForm)
    app.component('FormDrawer', FormDrawer)
    app.component('DictSelect', DictSelect)
    app.component('DictTag', DictTag)
  }
}
