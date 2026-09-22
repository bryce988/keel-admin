<script setup lang="ts">
/**
 * 顶栏聊天入口
 *
 * 这里**一次网络请求都不发**——数字全部来自 `stores/chat`，而那边是长连接推送驱动的。
 * 数字含未读系统公告（公告已经进了消息列表），所以有聊天权限的人顶栏不再显示公告铃铛。
 *
 * 这也是「红点三处一致」的实现方式：顶栏、会话列表角标、浏览器标题
 * 都从同一个 store 派生，没有任何一处自己算（chat-prd.md §9 的验收项）。
 */
import { computed, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { ElNotification } from 'element-plus'
import { ChatDotRound } from '@element-plus/icons-vue'
import { useChatStore } from '@/stores/chat'
import { useUserStore } from '@/stores/user'
import { chatSocket } from '@/utils/chatSocket'

const router = useRouter()
const chatStore = useChatStore()
const userStore = useUserStore()

/** 没有 chat:use 的人不该看到这个入口——点了也只会 403 */
const visible = computed(() => {
  const perms = userStore.profile?.permissions ?? []
  return perms.includes('*') || perms.includes('chat:use')
})

const hasUnread = computed(() => chatStore.total > 0)

onMounted(() => {
  if (!visible.value) return

  /*
   * 长连接在这里建，不在聊天页里建
   *
   * 放聊天页的话，只有打开那个页面才收得到消息——而红点要在**每个页面**上都对。
   * 布局是全站唯一常驻的组件，连接的生命周期挂在它身上才合理。
   */
  chatStore.bind()
  chatSocket.connect()
  void chatStore.refresh()

  offNotice = chatSocket.on('notice.changed', onNoticeChanged)
})

onUnmounted(() => offNotice?.())

/**
 * 新公告的页内弹窗（原来在顶栏铃铛上，铃铛对有聊天权限的人下掉之后挪到这里）
 *
 * 弹窗是呈现层的事，所以放组件里而不是 stores/chat——store 只负责数字、提示音与桌面通知。
 * 只弹「发布」，撤回、删除、编辑只让数字对齐。点弹窗直接打开聊天页里的那条公告
 */
let offNotice: (() => void) | undefined

function onNoticeChanged(data: unknown) {
  const d = data as { id: number; action: string; title: string }
  if (d.action !== 'published') return

  const n = ElNotification({
    title: '新公告',
    message: d.title,
    type: 'info',
    // 不自动关：公告是要人看到的，几秒后自己消失等于没发
    duration: 0,
    onClick: () => {
      n.close()
      void router.push({ path: '/collab/chat', query: { notice: String(d.id) } })
    },
  })
}

function go() {
  void router.push('/collab/chat')
}
</script>

<template>
  <span v-if="visible" class="chat-trigger" @click="go">
    <el-badge :value="chatStore.total" :max="99" :hidden="!hasUnread" :offset="[-2, 2]">
      <el-tooltip content="聊天">
        <el-icon class="icon-btn"><ChatDotRound /></el-icon>
      </el-tooltip>
    </el-badge>
  </span>
</template>

<style scoped>
.chat-trigger {
  display: inline-flex;
  align-items: center;
  cursor: pointer;
}
</style>
