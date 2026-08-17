<script setup lang="ts">
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
</script>

<template>
  <ModulePlaceholder
    title="参数配置"
    description="系统级参数的读写。改参数只落库并刷新缓存，不会去改 webman 的运行期配置——常驻内存下改配置只会影响当前进程，进程间立刻不一致。"
    perm-prefix="sys:param:*"
  />
</template>
