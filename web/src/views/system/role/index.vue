<script setup lang="ts">
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
</script>

<template>
  <ModulePlaceholder
    title="角色管理"
    description="RBAC 三层里的「授权」层：给角色勾选功能权限与数据范围。角色不在这里分配给具体的人，那是用户管理的事。"
    perm-prefix="sys:role:*"
  />
</template>
