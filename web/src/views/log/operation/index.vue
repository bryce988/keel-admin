<script setup lang="ts">
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
</script>

<template>
  <ModulePlaceholder
    title="操作日志"
    description="所有写操作的审计留痕，含请求参数（密码已脱敏）、字段级变更、traceId 与耗时。越权被拒的尝试同样入库，日志本身也受数据权限约束。"
    perm-prefix="sys:log:operation:*"
  />
</template>
