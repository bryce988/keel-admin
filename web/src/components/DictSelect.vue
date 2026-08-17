<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useDictStore } from '@/stores/dict'

/**
 * 字典下拉
 *
 * 用法与 el-select 一致，选项来自数据字典：
 *   <DictSelect v-model="query.status" code="user_status" placeholder="状态" clearable />
 */
const props = withDefaults(
  defineProps<{
    code: string
    modelValue?: string | number | Array<string | number>
    placeholder?: string
    clearable?: boolean
    multiple?: boolean
    disabled?: boolean
    /** 选项值转成数字，配合后端的 TINYINT 字段 */
    numeric?: boolean
  }>(),
  { placeholder: '请选择', clearable: true, multiple: false, disabled: false, numeric: false }
)

const emit = defineEmits<{
  'update:modelValue': [value: string | number | Array<string | number> | undefined]
  change: [value: string | number | Array<string | number> | undefined]
}>()

const dictStore = useDictStore()

onMounted(() => dictStore.load(props.code))

const options = computed(() =>
  dictStore.items(props.code).map((item) => ({
    label: item.label,
    value: props.numeric ? Number(item.value) : item.value
  }))
)

const inner = computed({
  get: () => props.modelValue,
  set: (value) => {
    emit('update:modelValue', value)
    emit('change', value)
  }
})
</script>

<template>
  <el-select
    v-model="inner"
    :placeholder="placeholder"
    :clearable="clearable"
    :multiple="multiple"
    :disabled="disabled"
  >
    <el-option v-for="opt in options" :key="String(opt.value)" :label="opt.label" :value="opt.value" />
  </el-select>
</template>
