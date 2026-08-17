<script setup lang="ts">
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
</script>

<template>
  <ModulePlaceholder
    title="部门管理"
    description="维护组织架构树。部门的 ancestors 祖级路径决定了「本部门及下属」这一数据范围能看到哪些数据，移动部门时要同步刷新所有子孙的路径。"
    perm-prefix="sys:dept:*"
  />
</template>
