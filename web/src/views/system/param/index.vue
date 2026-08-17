<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox, type FormRules } from 'element-plus'
import { Delete, Edit, Plus, RefreshLeft } from '@element-plus/icons-vue'
import {
  createParam,
  deleteParam,
  fetchParamGroups,
  fetchParams,
  saveParams,
  updateParam,
  PARAM_MASK,
  type ParamRow
} from '@/api/system'
import type { FormDrawerInstance } from '@/components'

/**
 * 参数配置（按分组分 tab，一组一张表单）
 *
 * 与其他模块不同，这页不是列表页：参数是**成组生效**的配置
 * （失败次数与锁定时长这类彼此相关），所以整组一次提交，
 * 而不是每行一个保存按钮留下半新半旧的中间态。
 *
 * ⚠️ 改参数只落库，不会热改后端配置——webman 是常驻内存多进程，
 * 运行期改配置只影响当前 worker（PROJECT.md §14）。
 */
const groups = ref<Array<{ code: string; name: string }>>([])
const activeGroup = ref('')

const rows = ref<ParamRow[]>([])
const loading = ref(false)
const saving = ref(false)

/** param_key → 当前编辑值。表单状态与接口数据分开存，才能算出「改了哪些」 */
const form = ref<Record<string, string>>({})

const dirtyKeys = computed(() =>
  rows.value.filter((r) => form.value[r.param_key] !== r.param_value).map((r) => r.param_key)
)

async function loadGroups() {
  groups.value = await fetchParamGroups()
  if (!activeGroup.value) activeGroup.value = groups.value[0]?.code ?? ''
}

async function loadParams() {
  if (!activeGroup.value) return

  loading.value = true
  try {
    rows.value = await fetchParams(activeGroup.value)
    resetForm()
  } finally {
    loading.value = false
  }
}

/** 表单值回到接口给的那一份。密钥回填的是掩码，原样送回后端就表示不改 */
function resetForm() {
  const next: Record<string, string> = {}
  rows.value.forEach((r) => (next[r.param_key] = r.param_value))
  form.value = next
}

/**
 * 切换分组
 *
 * el-tabs 用 `:model-value` 受控而不是 `v-model`：v-model 会在确认框弹出**之前**
 * 就把选中项改掉，用户点「留在本页」时界面已经切走了，只能再掰回来。
 * 受控之后不改 activeGroup 就等于没切，行为与用户的选择一致。
 */
async function onSwitchGroup(code: string | number) {
  if (String(code) === activeGroup.value) return

  if (dirtyKeys.value.length) {
    try {
      await ElMessageBox.confirm(
        `当前分组有 ${dirtyKeys.value.length} 项未保存的修改，切换后会丢失。`,
        '未保存的修改',
        { type: 'warning', confirmButtonText: '放弃修改', cancelButtonText: '留在本页' }
      )
    } catch {
      return
    }
  }

  activeGroup.value = String(code)
  await loadParams()
}

async function onSave() {
  const items = dirtyKeys.value.map((key) => ({
    param_key: key,
    param_value: form.value[key] ?? ''
  }))

  if (!items.length) {
    ElMessage.info('没有需要保存的修改')
    return
  }

  saving.value = true
  try {
    const result = await saveParams(items)
    ElMessage.success(`已保存 ${result.saved_count} 项`)
    await loadParams()
  } finally {
    saving.value = false
  }
}

// ---------------------------------------------------------------- 自定义参数的增删改
const drawerRef = ref<FormDrawerInstance | null>(null)
const editingId = ref(0)

const rules: FormRules = {
  name: [{ required: true, message: '请输入参数名称', trigger: 'blur' }],
  param_key: [
    { required: true, message: '请输入参数键', trigger: 'blur' },
    { pattern: /^[A-Za-z0-9_.-]+$/, message: '只能包含字母、数字与 _ . -', trigger: 'blur' }
  ]
}

function onCreate() {
  editingId.value = 0
  drawerRef.value?.open({
    title: '新增参数',
    data: {
      group: activeGroup.value,
      name: '',
      param_key: '',
      param_value: '',
      value_type: 'string',
      is_secret: false,
      remark: ''
    }
  })
}

function onEdit(row: ParamRow) {
  editingId.value = row.id
  drawerRef.value?.open({ title: '编辑参数', data: { ...row } })
}

function submit(formData: Record<string, any>) {
  const payload = {
    group: formData.group,
    name: formData.name,
    param_key: formData.param_key,
    param_value: formData.param_value ?? '',
    value_type: formData.value_type ?? 'string',
    is_secret: !!formData.is_secret,
    remark: formData.remark ?? ''
  }

  return editingId.value ? updateParam(editingId.value, payload) : createParam(payload)
}

async function onDelete(row: ParamRow) {
  await ElMessageBox.confirm(`确定删除参数「${row.name}」吗？`, '删除确认', {
    type: 'warning',
    confirmButtonText: '删除',
    confirmButtonClass: 'el-button--danger'
  })

  await deleteParam(row.id)
  ElMessage.success('已删除')
  await loadParams()
}

onMounted(async () => {
  await loadGroups()
  await loadParams()
})
</script>

<template>
  <div class="page">
    <el-tabs :model-value="activeGroup" @tab-change="onSwitchGroup">
      <el-tab-pane v-for="g in groups" :key="g.code" :label="g.name" :name="g.code" />
    </el-tabs>

    <el-card v-loading="loading" shadow="never">
      <template #header>
        <div class="card-header">
          <span class="hint">
            改动只写入数据库并清缓存，不会热改后端配置——常驻内存下运行期改配置只影响单个进程
          </span>
          <div class="actions">
            <el-button
              v-permission="'sys:param:create'"
              :icon="Plus"
              @click="onCreate"
            >
              新增参数
            </el-button>
            <el-button :icon="RefreshLeft" :disabled="!dirtyKeys.length" @click="resetForm">
              撤销修改
            </el-button>
            <el-button
              v-permission="'sys:param:update'"
              type="primary"
              :loading="saving"
              :disabled="!dirtyKeys.length"
              @click="onSave"
            >
              保存{{ dirtyKeys.length ? `（${dirtyKeys.length} 项）` : '' }}
            </el-button>
          </div>
        </div>
      </template>

      <el-form label-width="160px" class="param-form">
        <el-form-item v-for="row in rows" :key="row.param_key">
          <template #label>
            <div class="param-label">
              <span>{{ row.name }}</span>
              <el-tag v-if="row.is_secret" size="small" type="warning" effect="plain">密钥</el-tag>
            </div>
          </template>

          <div class="param-body">
            <!-- bool 用开关，int 用数字框，其余走文本；json 给多行 -->
            <el-switch
              v-if="row.value_type === 'bool'"
              :model-value="form[row.param_key] === '1'"
              @update:model-value="form[row.param_key] = $event ? '1' : '0'"
            />
            <el-input
              v-else-if="row.value_type === 'json'"
              v-model="form[row.param_key]"
              type="textarea"
              :rows="3"
            />
            <el-input
              v-else
              v-model="form[row.param_key]"
              :type="row.value_type === 'int' ? 'number' : 'text'"
              :placeholder="row.is_secret ? '留空的掩码表示不修改' : ''"
            />

            <div class="param-meta">
              <code>{{ row.param_key }}</code>
              <el-tag v-if="row.is_builtin" size="small" type="info" effect="plain">内置</el-tag>
              <span
                v-if="form[row.param_key] !== row.param_value"
                class="dirty"
              >
                已修改
              </span>
              <span v-if="row.remark" class="remark">{{ row.remark }}</span>

              <span class="spacer" />

              <el-button
                v-permission="'sys:param:update'"
                link
                type="primary"
                :icon="Edit"
                @click="onEdit(row)"
              >
                元信息
              </el-button>
              <el-button
                v-permission="'sys:param:delete'"
                link
                type="danger"
                :icon="Delete"
                :disabled="row.is_builtin"
                @click="onDelete(row)"
              >
                删除
              </el-button>
            </div>
          </div>
        </el-form-item>

        <el-empty v-if="!rows.length && !loading" description="该分组下还没有参数" />
      </el-form>
    </el-card>

    <FormDrawer
      ref="drawerRef"
      :submit="submit"
      :rules="rules"
      :error-fields="{ 20602: 'param_key' }"
      size="520px"
      @success="loadParams"
    >
      <template #default="{ form: f, errors }">
        <el-alert v-if="f.is_builtin" type="info" :closable="false" show-icon>
          内置参数只能改值与备注：键、类型、分组都被后端代码按名字读取，改了会让代码读不到，
          而调用点都有默认值兜底，故障会以「配置怎么不生效」的形式出现。
        </el-alert>

        <el-form-item label="参数名称" prop="name">
          <el-input v-model="f.name" maxlength="64" :disabled="f.is_builtin" />
        </el-form-item>
        <el-form-item label="参数键" prop="param_key" :error="errors.param_key">
          <el-input
            v-model="f.param_key"
            maxlength="128"
            placeholder="如 sys.upload.maxSize"
            :disabled="f.is_builtin"
          />
        </el-form-item>
        <el-form-item label="分组" prop="group">
          <el-select v-model="f.group" style="width: 100%" :disabled="f.is_builtin">
            <el-option v-for="g in groups" :key="g.code" :label="g.name" :value="g.code" />
          </el-select>
        </el-form-item>
        <el-form-item label="值类型" prop="value_type">
          <el-select v-model="f.value_type" style="width: 100%" :disabled="f.is_builtin">
            <el-option label="string 文本" value="string" />
            <el-option label="int 整数" value="int" />
            <el-option label="bool 布尔" value="bool" />
            <el-option label="json 结构" value="json" />
          </el-select>
        </el-form-item>
        <el-form-item label="参数值" prop="param_value">
          <el-input v-model="f.param_value" :placeholder="f.is_secret ? PARAM_MASK : ''" />
          <div v-if="f.is_secret" class="tip">
            密钥只写不读：读接口一律返回 <code>{{ PARAM_MASK }}</code>，
            原样提交即表示不修改
          </div>
        </el-form-item>
        <el-form-item label="密钥" prop="is_secret">
          <el-switch v-model="f.is_secret" :disabled="f.is_builtin" />
          <div class="tip">打开后该参数的值在所有读接口与操作日志里都只显示掩码</div>
        </el-form-item>
        <el-form-item label="备注" prop="remark">
          <el-input v-model="f.remark" type="textarea" :rows="2" maxlength="255" />
        </el-form-item>
      </template>
    </FormDrawer>
  </div>
</template>

<style scoped>
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}

.card-header .hint {
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.card-header .actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
}

.param-form {
  max-width: 900px;
}

.param-label {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 6px;
}

.param-body {
  width: 100%;
}

.param-meta {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 2px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.param-meta .spacer {
  flex: 1;
}

.param-meta .dirty {
  color: var(--el-color-warning);
}

.param-meta .remark {
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.tip {
  margin-top: 4px;
  font-size: 12px;
  line-height: 1.5;
  color: var(--el-text-color-secondary);
}

code {
  padding: 0 4px;
  border-radius: 3px;
  background: var(--el-fill-color-light);
  font-size: 12px;
}
</style>
