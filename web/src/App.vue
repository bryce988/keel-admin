<script setup lang="ts">
import zhCn from 'element-plus/es/locale/lang/zh-cn'
</script>

<!--
  全局语言与尺寸
  ==============

  以前这两项走 `app.use(ElementPlus, { locale: zhCn, size: 'default' })`。
  改成按需导入之后没有那次 `use` 了，等价做法是在根节点套一层 el-config-provider——
  它把配置放进 provide/inject，效果与全局配置一致。

  **尺寸 default（32px / 14px）**

  原先是 small（24px / 12px），理由是「后台密集型界面，default 显得笨重」。
  实际用下来相反：12px 的正文在 1440 屏上偏小，筛选框里的长占位符
  （「请输入操作人 / 描述 / 对象」）也塞不下。改回 default 之后
  字号与控件高度跟主流后台一致，可读性明显好转。

  走全局配置而不是逐个加 size：67 个按钮逐个加迟早漏一个，
  而且按钮与输入框必须同尺寸——搜索栏里它们并排，差一截底边就对不齐。
  表格行高由 ProTable 的密度开关单独控制，不受这里影响。

  ⚠️ ElMessage / ElMessageBox 这类**函数式**调用不在组件树里，取不到这里的 inject，
  它们的语言由 EP 的默认值决定。目前提示文案都是我们自己传的中文，不受影响。
-->
<template>
  <el-config-provider :locale="zhCn" size="default">
    <router-view />
  </el-config-provider>
</template>
