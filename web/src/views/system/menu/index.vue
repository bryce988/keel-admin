<script setup lang="ts">
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
</script>

<template>
  <ModulePlaceholder
    title="菜单权限"
    description="RBAC 三层里的「定义」层：菜单、按钮、接口、字段权限合并为一棵树。前端的动态路由就是由这里的 path 与 component 下发的，加页面不用发版。"
    perm-prefix="sys:menu:*"
  />
</template>
