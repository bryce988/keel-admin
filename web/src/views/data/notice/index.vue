<script setup lang="ts">
import { ref } from 'vue'
import { ElMessage, ElMessageBox, type FormRules } from 'element-plus'
import { Delete, EditPen, Plus, Promotion, RefreshLeft, View } from '@element-plus/icons-vue'
import {
  batchDeleteNotices,
  createNotice,
  deleteNotice,
  fetchNotice,
  fetchNotices,
  publishNotice,
  revokeNotice,
  updateNotice,
  type NoticePayload,
  type NoticeRow
} from '@/api/notice'
import type { FormShellInstance, ProColumn, ProTableInstance, SearchField } from '@/components'
import { useNoticeStore } from '@/stores/notice'

/**
 * 系统公告（标准列表页）
 *
 * 与其他列表页最大的不同：这一页的写操作会**立刻影响别人的界面**——
 * 一发布，所有在线用户下一次轮询就会收到弹窗。所以：
 * - 发布 / 撤回是独立按钮与独立接口，不藏在编辑表单的状态字段里
 * - 发布前二次确认，并在弹窗里写明「所有登录用户都会收到」
 *
 * 草稿与已发布用同一张表，靠 status 区分（字典 notice_status，不复用
 * enable_status——公告没有「停用」这回事）。
 *
 * ## 这一页用 <FormDialog> 而不是列表页惯例的 <FormDrawer>
 *
 * 惯例（PROJECT.md §9.4）是「列表页的新增/编辑用抽屉」，理由是抽屉不盖住列表、
 * 改完能立刻看到那一行。公告这里不成立：主体是一个带工具栏的富文本编辑区，
 * 写的时候要的是尽量宽的行宽和居中的注意力，而 560px 的抽屉会把正文挤成窄条，
 * 一行放不下十几个字。写完再看列表那一行也没什么可看的——公告的内容在正文里。
 */
const tableRef = ref<ProTableInstance | null>(null)
const dialogRef = ref<FormShellInstance<NoticePayload> | null>(null)
const noticeStore = useNoticeStore()

const query = ref<Record<string, unknown>>({ keyword: '', status: '', type: '' })

/** status 是数字，type 是字符串；不登记 status 的话刷新后下拉显示空白 */
const paramParsers = { status: Number }

const searchFields: SearchField[] = [
  { prop: 'keyword', label: '关键词', placeholder: '标题 / 正文' },
  { prop: 'status', label: '状态', type: 'dict', dict: 'notice_status', numeric: true },
  { prop: 'type', label: '类型', type: 'dict', dict: 'notice_type' }
]

const columns: ProColumn<NoticeRow>[] = [
  { prop: 'title', label: '标题', minWidth: 180 },
  // 摘要是正文剥成纯文字后的前 60 字（服务端算好），列表里不渲染 HTML
  { prop: 'summary', label: '摘要', minWidth: 220, hidden: true },
  { prop: 'type', label: '类型', width: 90, align: 'center', dict: 'notice_type' },
  { prop: 'status', label: '状态', width: 90, align: 'center', dict: 'notice_status' },
  { prop: 'publisher_name', label: '发布人', width: 110, align: 'center' },
  { prop: 'published_at', label: '发布时间', minWidth: 170, align: 'center', sortable: true },
  { prop: 'read_count', label: '已读', width: 80, align: 'center' },
  { prop: 'created_at', label: '创建时间', minWidth: 170, align: 'center', sortable: true, hidden: true },
  { prop: 'actions', label: '操作', width: 220, align: 'center', fixed: 'right', slot: 'actions' }
]

const selected = ref<NoticeRow[]>([])

// ---------------------------------------------------------------- 增改删
const editingId = ref(0)

const rules: FormRules = {
  title: [{ required: true, message: '请输入标题', trigger: 'blur' }],
  content: [{ required: true, message: '请输入正文', trigger: 'blur' }]
}

function onCreate() {
  editingId.value = 0
  // 默认存草稿：发布是「让所有人收到」，不该是新建表单的默认动作
  dialogRef.value?.open({
    title: '新增公告',
    data: { title: '', content: '', type: 'notice', status: 0 }
  })
}

async function onEdit(row: NoticeRow) {
  editingId.value = row.id
  // 列表只有摘要，编辑要正文，所以先取详情再开抽屉
  const detail = await fetchNotice(row.id)
  dialogRef.value?.open({ title: '编辑公告', data: { ...detail } })
}

async function onView(row: NoticeRow) {
  editingId.value = 0
  const detail = await fetchNotice(row.id)
  dialogRef.value?.open({ title: '公告详情', data: { ...detail }, mode: 'view' })
}

function submit(form: Partial<NoticePayload>) {
  const payload: NoticePayload = {
    title: form.title ?? '',
    content: form.content ?? '',
    type: form.type || 'notice',
    status: form.status ?? 0
  }

  return editingId.value ? updateNotice(editingId.value, payload) : createNotice(payload)
}

/**
 * 写操作之后顺手刷一次自己的铃铛
 *
 * 发布的人自己也是接收人，不刷的话他要等最多一个轮询间隔才看到角标变化，
 * 而他刚刚就是在这里点的发布——界面没反应最容易让人以为没成功。
 */
async function afterWrite() {
  tableRef.value?.refresh()
  await noticeStore.poll()
}

async function onPublish(row: NoticeRow) {
  await ElMessageBox.confirm(
    `确定发布「${row.title}」吗？发布后所有登录用户都会收到这条消息。`,
    '发布确认',
    { type: 'warning', confirmButtonText: '发布' }
  )

  await publishNotice(row.id)
  ElMessage.success('已发布')
  await afterWrite()
}

async function onRevoke(row: NoticeRow) {
  await ElMessageBox.confirm(
    `确定撤回「${row.title}」吗？撤回后它回到草稿，别人不再看得到；已读记录保留。`,
    '撤回确认',
    { type: 'warning', confirmButtonText: '撤回' }
  )

  await revokeNotice(row.id)
  ElMessage.success('已撤回')
  await afterWrite()
}

async function onDelete(row: NoticeRow) {
  await ElMessageBox.confirm(
    `确定删除「${row.title}」吗？删除后不可恢复，已读记录一并清除。`,
    '删除确认',
    { type: 'warning', confirmButtonText: '删除', confirmButtonClass: 'el-button--danger' }
  )

  await deleteNotice(row.id)
  ElMessage.success('已删除')
  await afterWrite()
}

async function onBatchDelete() {
  const ids = selected.value.map((r) => r.id)
  if (!ids.length) return

  await ElMessageBox.confirm(`确定删除选中的 ${ids.length} 条公告吗？`, '批量删除', {
    type: 'warning',
    confirmButtonText: '删除',
    confirmButtonClass: 'el-button--danger'
  })

  const result = await batchDeleteNotices(ids)

  if (result.fail_count === 0) {
    ElMessage.success(`已删除 ${result.success_count} 条`)
  } else {
    ElMessageBox.alert(
      result.failed.map((f) => `#${f.id}：${f.reason}`).join('<br>'),
      `成功 ${result.success_count} 条，失败 ${result.fail_count} 条`,
      { dangerouslyUseHTMLString: true, type: 'warning' }
    )
  }

  await afterWrite()
}
</script>

<template>
  <div class="page">
    <SearchForm
      v-model="query"
      :fields="searchFields"
      @search="tableRef?.reload()"
      @reset="tableRef?.reload()"
    />

    <ProTable
      ref="tableRef"
      v-model:params="query"
      :request="fetchNotices"
      :param-parsers="paramParsers"
      :columns="columns"
      id-column
      selection
      @selection-change="selected = $event as NoticeRow[]"
    >
      <template #toolbar>
        <el-button v-permission="'sys:notice:create'" type="primary" :icon="Plus" @click="onCreate">
          新增
        </el-button>
        <el-button
          v-permission="'sys:notice:delete'"
          type="danger"
          plain
          :icon="Delete"
          :disabled="!selected.length"
          @click="onBatchDelete"
        >
          批量删除
        </el-button>
      </template>

      <template #actions="{ row }">
        <div class="table-actions">
          <el-button
            v-permission="'sys:notice:detail'"
            :icon="View"
            link
            type="primary"
            @click="onView(row)"
          >
            详情
          </el-button>
          <!-- 发布与撤回互斥：同一个位置只出现其中一个，避免误点 -->
          <el-button
            v-if="!row.status"
            v-permission="'sys:notice:publish'"
            :icon="Promotion"
            link
            type="success"
            @click="onPublish(row)"
          >
            发布
          </el-button>
          <el-button
            v-else
            v-permission="'sys:notice:publish'"
            :icon="RefreshLeft"
            link
            type="warning"
            @click="onRevoke(row)"
          >
            撤回
          </el-button>
          <el-button
            v-permission="'sys:notice:update'"
            :icon="EditPen"
            link
            type="primary"
            @click="onEdit(row)"
          >
            编辑
          </el-button>
          <el-button
            v-permission="'sys:notice:delete'"
            :icon="Delete"
            link
            type="danger"
            @click="onDelete(row)"
          >
            删除
          </el-button>
        </div>
      </template>
    </ProTable>

    <FormDialog ref="dialogRef" :submit="submit" :rules="rules" size="720px" @success="afterWrite">
      <template #default="{ form, readonly }">
        <el-descriptions v-if="readonly" :column="1" border>
          <el-descriptions-item label="标题">{{ form.title }}</el-descriptions-item>
          <el-descriptions-item label="类型">
            <DictTag code="notice_type" :value="form.type" />
          </el-descriptions-item>
          <el-descriptions-item label="状态">
            <DictTag code="notice_status" :value="form.status" />
          </el-descriptions-item>
          <el-descriptions-item label="正文">
            <!--
              v-html 在这里是安全的：正文入库前已由服务端按白名单净化
              （support/Html.php），script、on* 事件属性、javascript: 协议都进不来。
              前端不再净化一遍——那只是给自己看的体面，真正的边界在写入侧。
            -->
            <div class="content-view rich-content" v-html="form.content" />
          </el-descriptions-item>
        </el-descriptions>

        <template v-else>
          <el-form-item label="标题" prop="title">
            <el-input v-model="form.title" maxlength="128" show-word-limit />
          </el-form-item>
          <el-form-item label="类型" prop="type">
            <DictSelect v-model="form.type" code="notice_type" />
          </el-form-item>
          <el-form-item label="正文" prop="content">
            <RichEditor v-model="form.content" :min-height="260" placeholder="请输入公告正文" />
          </el-form-item>
          <el-form-item label="发布" prop="status">
            <el-radio-group v-model="form.status">
              <el-radio :value="0">存为草稿</el-radio>
              <el-radio :value="1">立即发布</el-radio>
            </el-radio-group>
            <div class="form-tip">发布后所有登录用户都会在顶栏收到这条消息。</div>
          </el-form-item>
        </template>
      </template>
    </FormDialog>
  </div>
</template>

<style scoped>
.content-view {
  max-height: 40vh;
  overflow-y: auto;
  word-break: break-word;
}

.form-tip {
  width: 100%;
  font-size: 12px;
  line-height: 1.5;
  color: var(--el-text-color-secondary);
}
</style>
