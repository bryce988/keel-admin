<script setup lang="ts">
/**
 * 顶栏聊天入口
 *
 * 与公告铃铛（`<NoticeBell>`）并排，但行为完全不同：
 * 铃铛自己轮询，这里**一次网络请求都不发**——数字全部来自
 * `stores/chat`，而那边是长连接推送驱动的。
 *
 * 这也是「红点三处一致」的实现方式：顶栏、会话列表角标、浏览器标题
 * 都从同一个 store 派生，没有任何一处自己算（chat-prd.md §9 的验收项）。
 */
import { computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
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
})

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
