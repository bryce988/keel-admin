import type { App } from 'vue'
import ProTable from './ProTable.vue'
import SearchForm from './SearchForm.vue'
import FormDrawer from './FormDrawer.vue'
import FormDialog from './FormDialog.vue'
import DictSelect from './DictSelect.vue'
import DictTag from './DictTag.vue'
import EmptyState from './EmptyState.vue'
import PageSkeleton from './PageSkeleton.vue'
import BrandLogo from './BrandLogo.vue'

export {
  BrandLogo,
  ProTable,
  SearchForm,
  FormDrawer,
  FormDialog,
  DictSelect,
  DictTag,
  EmptyState,
  PageSkeleton
}
export type { ProColumn, PageResult, TableQuery } from './ProTable.vue'
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

/**
 * 全局注册
 *
 * 这几个组件在每个列表页都会用到，逐页 import 只是噪音。
 * 业务组件不要往这里加——全局注册的东西进不了 tree-shaking。
 */
export default {
  install(app: App) {
    app.component('ProTable', ProTable)
    app.component('SearchForm', SearchForm)
    app.component('FormDrawer', FormDrawer)
    app.component('FormDialog', FormDialog)
    app.component('DictSelect', DictSelect)
    app.component('DictTag', DictTag)
    app.component('EmptyState', EmptyState)
    app.component('PageSkeleton', PageSkeleton)
  }
}
