<script setup lang="ts">
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
</script>

<template>
  <ModulePlaceholder
    title="岗位管理"
    description="岗位是 HR 概念，不是角色。它只在新建用户时带出一个默认角色作为初始值，之后改岗位不会影响已有账号的授权。"
    perm-prefix="sys:post:*"
  />
</template>
