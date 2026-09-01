<script setup lang="ts">
import { computed, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElNotification } from 'element-plus'
import { Bell, Check } from '@element-plus/icons-vue'
import DictTag from '@/components/DictTag.vue'
import { useDictStore } from '@/stores/dict'
import { NOTICE_POLL_MS, useNoticeStore } from '@/stores/notice'
import { useUserStore } from '@/stores/user'

/**
 * 顶栏消息铃铛
 *
 * 三件事：未读角标、下拉里的最近十条、新公告的弹出提示。
 * 数据与轮询都在 `stores/notice.ts`，这里只负责呈现——
 * 弹提示留在组件里而不是 store 里，是因为 ElNotification 属于呈现，
 * store 一旦自己弹东西，任何引用它的地方（包括测试）都会跟着弹。
 */
const router = useRouter()
const noticeStore = useNoticeStore()
const userStore = useUserStore()
const dictStore = useDictStore()

/**
 * 有列表页权限的人才给「查看全部」，没权限点进去是一页 403
 *
 * 必须走 store 的 `can()` 而不是自己 `permissions.includes()`：
 * 超级管理员的权限列表是 `['*']` 一个元素，直接 includes 会把他判成没权限——
 * 而他恰恰是最需要这个入口的人。
 */
const canManage = computed(() => userStore.can('sys:notice:list'))

/** 99+ 的门槛交给 el-badge 的 max，这里只决定显不显示 */
const hasUnread = computed(() => noticeStore.unreadCount > 0)

async function pollAndPop() {
  const title = await noticeStore.poll()
  if (!title) return

  ElNotification({
    title: '新公告',
    message: title,
    type: 'info',
    // 不自动关：公告是要人看到的，3 秒后自己消失等于没发
    duration: 0,
    onClick: () => void openNotice(noticeStore.latestId)
  })
}

async function openNotice(id: number) {
  if (!id) return
  await noticeStore.open(id)
}

async function onReadAll() {
  if (!hasUnread.value) return

  await noticeStore.markAllRead()
  ElMessage.success('已全部标为已读')
}

function goList() {
  router.push('/data/notice')
}

/*
 * 轮询的启停跟着这个组件走
 *
 * 它挂在布局里，登录后一直在、登出即卸载，正好是「该轮询的时间段」。
 * 放进 main.ts 或路由守卫的话，登录页也会每分钟打一次 401。
 */
let timer = 0 as ReturnType<typeof setInterval> | 0

/** 标签页不可见时不轮询；切回来立刻补一次，否则最长要等一整个间隔才看到新消息 */
function onVisible() {
  if (document.visibilityState === 'visible') void pollAndPop()
}

onMounted(() => {
  dictStore.preload(['notice_type'])

  void pollAndPop()
  timer = setInterval(() => {
    if (document.visibilityState === 'visible') void pollAndPop()
  }, NOTICE_POLL_MS)

  document.addEventListener('visibilitychange', onVisible)
})

onUnmounted(() => {
  if (timer) clearInterval(timer)
  timer = 0
  document.removeEventListener('visibilitychange', onVisible)
})
</script>

<template>
  <el-popover placement="bottom-end" :width="340" trigger="click" popper-class="notice-popper">
    <template #reference>
      <span class="bell-trigger">
        <el-badge
          :value="noticeStore.unreadCount"
          :max="99"
          :hidden="!hasUnread"
          :offset="[-2, 2]"
        >
          <el-tooltip content="消息">
            <el-icon class="icon-btn"><Bell /></el-icon>
          </el-tooltip>
        </el-badge>
      </span>
    </template>

    <div class="notice-panel">
      <div class="panel-head">
        <span class="panel-title">消息</span>
        <el-button v-if="hasUnread" link type="primary" :icon="Check" @click="onReadAll">
          全部已读
        </el-button>
      </div>

      <!-- 下拉里已读未读都列：只列未读的话，读完最后一条下拉就空了，
           用户会以为消息丢了 -->
      <ul v-if="noticeStore.list.length" class="notice-list">
        <li
          v-for="item in noticeStore.list"
          :key="item.id"
          :class="{ 'is-unread': !item.is_read }"
          @click="openNotice(item.id)"
        >
          <span class="dot" />
          <div class="item-main">
            <div class="item-title">
              <DictTag code="notice_type" :value="item.type" />
              <span class="text">{{ item.title }}</span>
            </div>
            <div class="item-summary">{{ item.summary }}</div>
            <div class="item-meta">{{ item.published_at }} · {{ item.publisher_name }}</div>
          </div>
        </li>
      </ul>

      <p v-else class="empty">暂无消息</p>

      <div v-if="canManage" class="panel-foot">
        <el-button link type="primary" @click="goList">查看全部</el-button>
      </div>
    </div>
  </el-popover>

  <!--
    正文是富文本，v-html 渲染
    ————————————————————————
    安全性由**写入侧**保证：正文入库前已经过 `support/Html.php` 的白名单净化，
    script、on* 事件属性、javascript: 协议都进不到库里。这里不再净化一遍——
    渲染点有三处（这里、公告详情、列表摘要），靠每处记得做一次迟早会漏一处，
    而漏没漏没有任何信号。样式走全局 `.rich-content`，与编辑器里所见一致。
  -->
  <el-dialog
    :model-value="!!noticeStore.current"
    :title="noticeStore.current?.title"
    width="560px"
    @close="noticeStore.close()"
  >
    <div v-if="noticeStore.current" class="detail">
      <div class="detail-meta">
        <DictTag code="notice_type" :value="noticeStore.current.type" />
        <span>{{ noticeStore.current.published_at }}</span>
        <span>{{ noticeStore.current.publisher_name }}</span>
      </div>
      <div class="detail-body rich-content" v-html="noticeStore.current.content" />
    </div>
  </el-dialog>
</template>

<style scoped>
.bell-trigger {
  display: inline-flex;
  align-items: center;
}

.notice-panel {
  margin: -12px;
}

.panel-head,
.panel-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 12px;
}

.panel-head {
  border-bottom: 1px solid var(--el-border-color-lighter);
}

.panel-foot {
  justify-content: center;
  border-top: 1px solid var(--el-border-color-lighter);
}

.panel-title {
  font-size: 14px;
  font-weight: 600;
  color: var(--el-text-color-primary);
}

.notice-list {
  max-height: 320px;
  margin: 0;
  padding: 0;
  overflow-y: auto;
  list-style: none;
}

.notice-list li {
  display: flex;
  gap: 8px;
  padding: 10px 12px;
  border-bottom: 1px solid var(--el-border-color-lighter);
  cursor: pointer;
  transition: background 0.2s;
}

.notice-list li:last-child {
  border-bottom: none;
}

.notice-list li:hover {
  background: var(--el-fill-color-light);
}

/* 未读标识：一个圆点，占位在已读条目上同样保留，
   否则两种条目的文字起点差 14px，列表看着是歪的 */
.dot {
  width: 6px;
  height: 6px;
  margin-top: 7px;
  flex: none;
  border-radius: 50%;
  background: transparent;
}

.is-unread .dot {
  background: var(--el-color-danger);
}

.item-main {
  min-width: 0;
  flex: 1;
}

.item-title {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  color: var(--el-text-color-regular);
}

.is-unread .item-title .text {
  font-weight: 600;
  color: var(--el-text-color-primary);
}

.item-title .text,
.item-summary {
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.item-summary {
  margin-top: 2px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.item-meta {
  margin-top: 2px;
  font-size: 12px;
  color: var(--el-text-color-placeholder);
}

.empty {
  margin: 0;
  padding: 28px 0;
  text-align: center;
  font-size: 13px;
  color: var(--el-text-color-placeholder);
}

.detail-meta {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 12px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

/* 排版细节（标题、列表、引用）在全局 .rich-content 里，与编辑器共用一套 */
.detail-body {
  max-height: 50vh;
  overflow-y: auto;
  font-size: 14px;
  color: var(--el-text-color-primary);
}
</style>
