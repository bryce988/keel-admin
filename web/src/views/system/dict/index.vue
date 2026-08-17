<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox, type FormRules } from 'element-plus'
import { Delete, Edit, Plus, Search } from '@element-plus/icons-vue'
import {
  batchDeleteDictItems,
  createDictItem,
  createDictType,
  deleteDictItem,
  deleteDictType,
  fetchDictItems,
  fetchDictTypes,
  updateDictItem,
  updateDictType,
  type DictItemRow,
  type DictTypeRow
} from '@/api/system'
import type {
  FormDrawerInstance,
  ProColumn,
  ProTableInstance,
  SearchField,
  TableQuery
} from '@/components'
import { useDictStore } from '@/stores/dict'

/**
 * 数据字典（主从页：左类型、右字典项）
 *
 * 字典是全站枚举与状态色的唯一来源，页面里不写 `status === 1 ? 'success' : 'info'`。
 * 所以这一页改完要立刻在**别的页面**生效——后端写入即清缓存，
 * 前端这边还要清 Pinia 里那份，否则界面拿的仍是本次会话早先缓存的旧选项。
 */
const dictStore = useDictStore()

// ---------------------------------------------------------------- 左：字典类型
const types = ref<DictTypeRow[]>([])
const typeKeyword = ref('')
const currentCode = ref('')
const typeLoading = ref(false)

const filteredTypes = computed(() => {
  const kw = typeKeyword.value.trim().toLowerCase()
  if (!kw) return types.value

  // 类型总共十几条，前端过滤即可，不必为一个搜索框再跑一趟接口
  return types.value.filter(
    (t) => t.name.toLowerCase().includes(kw) || t.code.toLowerCase().includes(kw)
  )
})

const currentType = computed(() => types.value.find((t) => t.code === currentCode.value) ?? null)

async function loadTypes(preferCode = '') {
  typeLoading.value = true
  try {
    // 字典类型不分页：一个系统撑死几十个，一次给全，左侧列表才能直接滚
    const result = await fetchDictTypes({ page_num: 1, page_size: 200 } as TableQuery)
    types.value = result.list

    const wanted = preferCode || currentCode.value
    const hit = types.value.find((t) => t.code === wanted)
    currentCode.value = hit?.code ?? types.value[0]?.code ?? ''
  } finally {
    typeLoading.value = false
  }
}

async function selectType(code: string) {
  if (code === currentCode.value) return

  currentCode.value = code
  query.value = { ...query.value, type_code: code, keyword: '', status: '' }
  // reload() 里已经 await nextTick()，这里不用再等 prop 回流
  await tableRef.value?.reload()
}

// ---------------------------------------------------------------- 右：字典项
const tableRef = ref<ProTableInstance | null>(null)

const query = ref<Record<string, unknown>>({ type_code: '', keyword: '', status: '' })
const paramParsers = { status: Number }

const searchFields: SearchField[] = [
  { prop: 'keyword', label: '关键词', placeholder: '文案 / 值' },
  { prop: 'status', label: '状态', type: 'dict', dict: 'enable_status', numeric: true }
]

const columns: ProColumn[] = [
  { prop: 'label', label: '显示文案', minWidth: 140 },
  { prop: 'value', label: '存储值', minWidth: 120, slot: 'value' },
  { prop: 'tag_type', label: '标签预览', width: 120, align: 'center', slot: 'preview' },
  { prop: 'sort', label: '排序', width: 80, align: 'center', sortable: true },
  { prop: 'status', label: '状态', width: 90, align: 'center', dict: 'enable_status' },
  { prop: 'remark', label: '备注', minWidth: 160, hidden: true },
  { prop: 'actions', label: '操作', width: 180, align: 'center', fixed: 'right', slot: 'actions' }
]

const selected = ref<DictItemRow[]>([])

/** type_code 跟着筛选条件一起进 URL，刷新后左侧选中项才能还原 */
function requestItems(params: TableQuery) {
  return fetchDictItems(String(params.type_code || currentCode.value), params)
}

// ---------------------------------------------------------------- 类型的增改删
const typeDrawerRef = ref<FormDrawerInstance | null>(null)
const editingTypeId = ref(0)

const typeRules: FormRules = {
  name: [{ required: true, message: '请输入字典名称', trigger: 'blur' }],
  code: [
    { required: true, message: '请输入字典编码', trigger: 'blur' },
    { pattern: /^[A-Za-z0-9_.-]+$/, message: '只能包含字母、数字与 _ . -', trigger: 'blur' }
  ]
}

function onCreateType() {
  editingTypeId.value = 0
  typeDrawerRef.value?.open({
    title: '新增字典',
    data: { name: '', code: '', status: 1, remark: '' }
  })
}

function onEditType(row: DictTypeRow) {
  editingTypeId.value = row.id
  typeDrawerRef.value?.open({ title: '编辑字典', data: { ...row } })
}

function submitType(form: Record<string, any>) {
  const payload = {
    name: form.name,
    code: form.code,
    status: form.status ?? 1,
    remark: form.remark ?? ''
  }

  return editingTypeId.value ? updateDictType(editingTypeId.value, payload) : createDictType(payload)
}

async function onTypeSaved(saved: unknown) {
  const code = (saved as DictTypeRow)?.code ?? ''
  await dictStore.refresh(code)
  await loadTypes(code)
  await tableRef.value?.reload()
}

async function onDeleteType(row: DictTypeRow) {
  await ElMessageBox.confirm(
    `确定删除字典「${row.name}」吗？字典下还有字典项时无法删除。`,
    '删除确认',
    { type: 'warning', confirmButtonText: '删除', confirmButtonClass: 'el-button--danger' }
  )

  await deleteDictType(row.id)
  ElMessage.success('已删除')
  dictStore.forget(row.code)   // 类型已经没了，重新拉只会 404，直接丢掉

  if (row.code === currentCode.value) currentCode.value = ''
  await loadTypes()
  await tableRef.value?.reload()
}

// ---------------------------------------------------------------- 字典项的增改删
const itemDrawerRef = ref<FormDrawerInstance | null>(null)
const editingItem = ref<DictItemRow | null>(null)

const itemRules: FormRules = {
  label: [{ required: true, message: '请输入显示文案', trigger: 'blur' }],
  value: [{ required: true, message: '请输入存储值', trigger: 'blur' }]
}

const errorFields = { 20501: 'value', 20502: 'value' }

const TAG_TYPES = [
  { label: '默认（灰）', value: '' },
  { label: 'success 绿', value: 'success' },
  { label: 'primary 蓝', value: 'primary' },
  { label: 'warning 橙', value: 'warning' },
  { label: 'danger 红', value: 'danger' },
  { label: 'info 灰', value: 'info' }
]

function onCreateItem() {
  if (!currentCode.value) return

  editingItem.value = null
  itemDrawerRef.value?.open({
    title: `新增字典项 · ${currentType.value?.name ?? ''}`,
    data: {
      type_code: currentCode.value,
      label: '',
      value: '',
      tag_type: '',
      sort: 0,
      status: 1,
      remark: ''
    }
  })
}

function onEditItem(row: DictItemRow) {
  editingItem.value = row
  itemDrawerRef.value?.open({ title: '编辑字典项', data: { ...row } })
}

function submitItem(form: Record<string, any>) {
  const payload = {
    type_code: form.type_code || currentCode.value,
    label: form.label,
    value: form.value,
    tag_type: form.tag_type ?? '',
    sort: form.sort ?? 0,
    status: form.status ?? 1,
    remark: form.remark ?? ''
  }

  return editingItem.value ? updateDictItem(editingItem.value.id, payload) : createDictItem(payload)
}

/**
 * 保存后除了刷新表格，还要把 Pinia 里的这份缓存丢掉
 *
 * 后端写入时已经清了 Redis，但前端 store 是**本次会话内**的另一层缓存：
 * 不清的话，切到用户列表看到的仍是改之前的标签颜色，
 * 而且要刷新浏览器才好——正是验收要盯的那个点。
 */
async function afterItemSaved() {
  await dictStore.refresh(currentCode.value)
  await loadTypes(currentCode.value)   // item_count 会变
  tableRef.value?.refresh()
}

async function onDeleteItem(row: DictItemRow) {
  await ElMessageBox.confirm(`确定删除字典项「${row.label}」吗？`, '删除确认', {
    type: 'warning',
    confirmButtonText: '删除',
    confirmButtonClass: 'el-button--danger'
  })

  await deleteDictItem(row.id)
  ElMessage.success('已删除')
  await afterItemSaved()
}

async function onBatchDeleteItems() {
  const ids = selected.value.map((r) => r.id)
  if (!ids.length) return

  await ElMessageBox.confirm(`确定删除选中的 ${ids.length} 个字典项吗？`, '批量删除', {
    type: 'warning',
    confirmButtonText: '删除',
    confirmButtonClass: 'el-button--danger'
  })

  const result = await batchDeleteDictItems(ids)

  // 已被引用的项会删不掉，逐条告知原因，而不是笼统报一句成功
  if (result.fail_count === 0) {
    ElMessage.success(`已删除 ${result.success_count} 个字典项`)
  } else {
    ElMessageBox.alert(
      result.failed.map((f) => `#${f.id}：${f.reason}`).join('<br>'),
      `成功 ${result.success_count} 个，失败 ${result.fail_count} 个`,
      { dangerouslyUseHTMLString: true, type: 'warning' }
    )
  }

  await afterItemSaved()
}

onMounted(async () => {
  dictStore.preload(['enable_status'])

  // ProTable 在 mounted 时就把 URL 里的 type_code 还原进了 query，
  // 用它作为首选，刷新后左右两侧才是同一个字典
  await loadTypes(String(query.value.type_code || ''))
  query.value = { ...query.value, type_code: currentCode.value }
  await tableRef.value?.reload()
})
</script>

<template>
  <div class="page dict-page">
    <!-- 左：字典类型 -->
    <el-card v-loading="typeLoading" class="type-panel" shadow="never">
      <template #header>
        <div class="panel-header">
          <span>字典类型</span>
          <el-button
            v-permission="'sys:dict:create'"
            type="primary"
            link
            :icon="Plus"
            @click="onCreateType"
          >
            新增
          </el-button>
        </div>
      </template>

      <el-input
        v-model="typeKeyword"
        placeholder="搜索名称 / 编码"
        :prefix-icon="Search"
        clearable
        size="small"
      />

      <ul class="type-list">
        <li
          v-for="t in filteredTypes"
          :key="t.code"
          :class="{ active: t.code === currentCode }"
          @click="selectType(t.code)"
        >
          <div class="type-main">
            <span class="type-name">{{ t.name }}</span>
            <el-tag v-if="!t.status" size="small" type="info" effect="plain">停用</el-tag>
          </div>
          <div class="type-sub">
            <code>{{ t.code }}</code>
            <span class="count">{{ t.item_count }} 项</span>
          </div>

          <div class="type-actions">
            <el-button
              v-permission="'sys:dict:update'"
              link
              type="primary"
              :icon="Edit"
              @click.stop="onEditType(t)"
            />
            <el-button
              v-permission="'sys:dict:delete'"
              link
              type="danger"
              :icon="Delete"
              @click.stop="onDeleteType(t)"
            />
          </div>
        </li>
        <li v-if="!filteredTypes.length" class="empty">没有匹配的字典</li>
      </ul>
    </el-card>

    <!-- 右：字典项 -->
    <div class="item-panel">
      <SearchForm
        v-model="query"
        :fields="searchFields"
        @search="tableRef?.reload()"
        @reset="tableRef?.reload()"
      />

      <ProTable
        ref="tableRef"
        v-model:params="query"
        :request="requestItems"
        :param-parsers="paramParsers"
        :columns="columns"
        :immediate="false"
        selection
        index
        @selection-change="selected = $event as DictItemRow[]"
      >
        <template #toolbar>
          <el-button
            v-permission="'sys:dict:create'"
            type="primary"
            :icon="Plus"
            :disabled="!currentCode"
            @click="onCreateItem"
          >
            新增字典项
          </el-button>
          <el-button
            v-permission="'sys:dict:delete'"
            type="danger"
            plain
            :icon="Delete"
            :disabled="!selected.length"
            @click="onBatchDeleteItems"
          >
            批量删除{{ selected.length ? `（${selected.length}）` : '' }}
          </el-button>
          <span v-if="currentType" class="current-hint">
            当前字典：{{ currentType.name }} <code>{{ currentType.code }}</code>
          </span>
        </template>

        <template #value="{ row }">
          <code>{{ row.value }}</code>
          <el-tooltip v-if="row.ref_count" :content="`已被 ${row.ref_count} 条数据引用，值不可修改`">
            <el-tag size="small" type="warning" effect="plain">引用 {{ row.ref_count }}</el-tag>
          </el-tooltip>
        </template>

        <!-- 标签预览：改 tag_type 的效果在这一列直接看得见 -->
        <template #preview="{ row }">
          <el-tag :type="row.tag_type || 'info'" size="small">{{ row.label }}</el-tag>
        </template>

        <template #actions="{ row }">
          <div class="table-actions">
            <el-button v-permission="'sys:dict:update'" link type="primary" @click="onEditItem(row)">
              编辑
            </el-button>
            <el-button
              v-permission="'sys:dict:delete'"
              link
              type="danger"
              :disabled="!!row.ref_count"
              @click="onDeleteItem(row)"
            >
              删除
            </el-button>
          </div>
        </template>
      </ProTable>
    </div>

    <!-- 字典类型表单 -->
    <FormDrawer
      ref="typeDrawerRef"
      :submit="submitType"
      :rules="typeRules"
      :error-fields="{ 20501: 'code', 20502: 'code' }"
      size="480px"
      @success="onTypeSaved"
    >
      <template #default="{ form, errors }">
        <el-form-item label="字典名称" prop="name">
          <el-input v-model="form.name" maxlength="64" show-word-limit />
        </el-form-item>
        <el-form-item label="字典编码" prop="code" :error="errors.code">
          <el-input
            v-model="form.code"
            maxlength="64"
            placeholder="如 order_status"
            :disabled="editingTypeId > 0 && (currentType?.item_count ?? 0) > 0"
          />
          <div class="tip">
            编码是字典项的关联键，页面里用它取值（<code>&lt;DictTag code="…" /&gt;</code>）。
            已经有字典项之后不可修改
          </div>
        </el-form-item>
        <el-form-item label="状态" prop="status">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">启用</el-radio>
            <el-radio :value="0">停用</el-radio>
          </el-radio-group>
          <div class="tip">停用后前台取不到这个字典的选项，已存的数据不受影响</div>
        </el-form-item>
        <el-form-item label="备注" prop="remark">
          <el-input v-model="form.remark" type="textarea" :rows="2" maxlength="255" />
        </el-form-item>
      </template>
    </FormDrawer>

    <!-- 字典项表单 -->
    <FormDrawer
      ref="itemDrawerRef"
      :submit="submitItem"
      :rules="itemRules"
      :error-fields="errorFields"
      size="480px"
      @success="afterItemSaved"
    >
      <template #default="{ form, errors }">
        <el-form-item label="显示文案" prop="label">
          <el-input v-model="form.label" maxlength="64" show-word-limit />
        </el-form-item>
        <el-form-item label="存储值" prop="value" :error="errors.value">
          <el-input
            v-model="form.value"
            maxlength="64"
            :disabled="!!editingItem?.ref_count"
            placeholder="落库的值，如 1"
          />
          <div v-if="editingItem?.ref_count" class="tip warn">
            已被 {{ editingItem.ref_count }} 条数据引用，改值会让这些数据的含义发生变化，因此锁定
          </div>
        </el-form-item>
        <el-form-item label="标签颜色" prop="tag_type">
          <el-select v-model="form.tag_type" style="width: 100%">
            <el-option v-for="t in TAG_TYPES" :key="t.value" :label="t.label" :value="t.value">
              <el-tag :type="t.value || 'info'" size="small">{{ t.label }}</el-tag>
            </el-option>
          </el-select>
          <div class="tip">
            全站 <code>&lt;DictTag&gt;</code> 的颜色由它驱动，页面里不再各写各的颜色判断
          </div>
        </el-form-item>
        <el-form-item label="排序" prop="sort">
          <el-input-number v-model="form.sort" :min="0" :max="9999" controls-position="right" />
        </el-form-item>
        <el-form-item label="状态" prop="status">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">启用</el-radio>
            <el-radio :value="0">停用</el-radio>
          </el-radio-group>
          <div class="tip">停用的项不会出现在下拉里，但已存的数据仍能正常显示标签</div>
        </el-form-item>
        <el-form-item label="备注" prop="remark">
          <el-input v-model="form.remark" type="textarea" :rows="2" maxlength="255" />
        </el-form-item>
      </template>
    </FormDrawer>
  </div>
</template>

<style scoped>
.dict-page {
  display: grid;
  grid-template-columns: 280px minmax(0, 1fr);
  gap: 12px;
  align-items: start;
}

.panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.type-panel :deep(.el-card__body) {
  padding: 12px;
}

.type-list {
  margin: 12px 0 0;
  padding: 0;
  list-style: none;
  max-height: calc(100vh - 280px);
  overflow-y: auto;
}

.type-list li {
  position: relative;
  padding: 8px 10px;
  border-radius: 4px;
  cursor: pointer;
  border: 1px solid transparent;
}

.type-list li:hover {
  background: var(--el-fill-color-light);
}

.type-list li.active {
  background: var(--el-color-primary-light-9);
  border-color: var(--el-color-primary-light-7);
}

.type-main {
  display: flex;
  align-items: center;
  gap: 6px;
}

.type-name {
  font-size: 14px;
  color: var(--el-text-color-primary);
}

.type-sub {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 2px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

/* 操作按钮悬停才出现：常驻会让十几行列表显得很吵 */
.type-actions {
  position: absolute;
  top: 6px;
  right: 6px;
  display: none;
  gap: 0;
}

.type-list li:hover .type-actions {
  display: flex;
}

.type-list .empty {
  padding: 24px 0;
  text-align: center;
  color: var(--el-text-color-secondary);
  cursor: default;
}

.item-panel {
  display: flex;
  flex-direction: column;
  gap: 12px;
  min-width: 0;
}

.current-hint {
  margin-left: 8px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.tip {
  margin-top: 4px;
  font-size: 12px;
  line-height: 1.5;
  color: var(--el-text-color-secondary);
}

.tip.warn {
  color: var(--el-color-warning);
}

code {
  padding: 0 4px;
  border-radius: 3px;
  background: var(--el-fill-color-light);
  font-size: 12px;
}
</style>
