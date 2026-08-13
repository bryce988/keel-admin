<script setup lang="ts">
import { useUserStore } from '@/stores/user'

const userStore = useUserStore()
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <template #header>
        <b>登录成功 · 链路已跑通</b>
      </template>

      <el-descriptions :column="2" border>
        <el-descriptions-item label="账号">
          {{ userStore.profile?.user.username }}
        </el-descriptions-item>
        <el-descriptions-item label="姓名">
          {{ userStore.profile?.user.realName }}
        </el-descriptions-item>
        <el-descriptions-item label="部门">
          {{ userStore.profile?.user.deptName || '—' }}
        </el-descriptions-item>
        <el-descriptions-item label="超级管理员">
          <el-tag :type="userStore.isSuper ? 'success' : 'info'" size="small">
            {{ userStore.isSuper ? '是' : '否' }}
          </el-tag>
        </el-descriptions-item>
        <el-descriptions-item label="角色">
          <el-tag v-for="r in userStore.profile?.roles" :key="r" size="small" class="tag">
            {{ r }}
          </el-tag>
        </el-descriptions-item>
        <el-descriptions-item label="数据范围">
          {{ ['', '全部数据', '本部门及下属', '本部门', '仅本人', '自定义'][userStore.profile?.dataScope ?? 4] }}
        </el-descriptions-item>
        <el-descriptions-item label="权限点" :span="2">
          <el-tag
            v-for="p in userStore.profile?.permissions"
            :key="p"
            size="small"
            type="info"
            class="tag"
          >
            {{ p }}
          </el-tag>
        </el-descriptions-item>
      </el-descriptions>

      <el-alert class="tip" type="info" :closable="false" show-icon>
        这是 M1 的验证页：登录 → 签发 JWT → 鉴权中间件 → 拉取用户与菜单，整条链路已打通。
        后续按 docs/api.md 的接口清单逐个模块实现即可。
      </el-alert>
    </el-card>
  </div>
</template>

<style scoped>
.page {
  max-width: 1000px;
}
.tag {
  margin: 2px 6px 2px 0;
}
.tip {
  margin-top: 16px;
}
</style>
