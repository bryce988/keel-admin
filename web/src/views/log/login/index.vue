<script setup lang="ts">
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
</script>

<template>
  <ModulePlaceholder
    title="登录日志"
    description="登录与登出记录。失败也会入库并记下原因，连续失败锁定的审计依据就在这里。"
    perm-prefix="sys:log:login:*"
  />
</template>
