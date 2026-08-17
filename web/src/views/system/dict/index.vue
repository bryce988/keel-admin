<script setup lang="ts">
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
</script>

<template>
  <ModulePlaceholder
    title="数据字典"
    description="全站枚举与状态色的唯一来源。字典项的 tagType 驱动 DictTag 的颜色，页面里不写 status === 1 ? 'success' : 'info' 这类判断。"
    perm-prefix="sys:dict:*"
  />
</template>
