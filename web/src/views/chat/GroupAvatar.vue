<script setup lang="ts">
/**
 * 会话头像：单聊 / 设了头像的群显示图片，没设头像的群拼成员头像（微信、钉钉同款）
 *
 * 拼接在前端做而不是服务端合成一张图：成员换头像、有人进出群时不用重新生成文件，
 * 服务端只下发前几位成员（`avatar_members`，最多 4 个），排版全在这里。
 *
 * 排法：1 人占满；2 人左右各半；3 人上一下二；4 人 2×2。
 * 没有头像的成员用姓名首字，底色按名字取一个固定色——同一个人在不同群里颜色一致
 */
import { computed, ref, watch } from 'vue'
import type { ChatFace } from '@/api/chat'

const props = withDefaults(
  defineProps<{
    /** 自定义头像（单聊是对方头像，群是群主设的头像）；有就直接用它 */
    src?: string
    /** 群成员（拼接用）。src 为空且这里有人时才拼 */
    faces?: ChatFace[]
    /** 兜底首字：既没 src 也没成员时显示 */
    name?: string
    size?: number
  }>(),
  { src: '', faces: () => [], name: '', size: 38 },
)

const faces = computed(() => props.faces.slice(0, 4))

/**
 * 加载失败的格子退回首字
 *
 * 与 el-avatar 的行为一致：图片 404（文件被清理、地址失效）时显示姓名首字，
 * 而不是一个破图标。成员变了就清掉记录，新的一批重新试
 */
const broken = ref(new Set<number>())
watch(faces, () => (broken.value = new Set()))

function onImgError(i: number) {
  broken.value = new Set(broken.value).add(i)
}
const tiled = computed(() => !props.src && faces.value.length > 0)

/*
 * 首字底色：取自 EP 的语义色，按名字哈希选一个。
 * 用固定的几种而不是随机色：随机色每次刷新都变，而且容易出现浅底白字看不清
 */
const TONES = [
  'var(--el-color-primary)',
  'var(--el-color-success)',
  'var(--el-color-warning)',
  'var(--el-color-danger)',
  'var(--el-color-info)',
]

function toneOf(name: string) {
  let h = 0
  for (const ch of name) h = (h * 31 + ch.codePointAt(0)!) >>> 0
  return TONES[h % TONES.length]
}

/** 每一格的位置（百分比）：按人数排版 */
const cells = computed(() => {
  const n = faces.value.length
  if (n === 1) return [{ x: 0, y: 0, w: 100, h: 100 }]
  if (n === 2) return [{ x: 0, y: 0, w: 50, h: 100 }, { x: 50, y: 0, w: 50, h: 100 }]
  if (n === 3)
    return [
      { x: 25, y: 0, w: 50, h: 50 },
      { x: 0, y: 50, w: 50, h: 50 },
      { x: 50, y: 50, w: 50, h: 50 },
    ]
  return [
    { x: 0, y: 0, w: 50, h: 50 },
    { x: 50, y: 0, w: 50, h: 50 },
    { x: 0, y: 50, w: 50, h: 50 },
    { x: 50, y: 50, w: 50, h: 50 },
  ]
})

/** 格子越小字越小；一格占满时与普通头像的首字一样大 */
const initialSize = computed(() => {
  const n = faces.value.length
  return `${Math.round(props.size * (n === 1 ? 0.42 : 0.26))}px`
})
</script>

<template>
  <div v-if="tiled" class="gavatar" :style="{ width: `${size}px`, height: `${size}px` }">
    <div
      v-for="(f, i) in faces"
      :key="i"
      class="gavatar__cell"
      :style="{
        left: `${cells[i].x}%`,
        top: `${cells[i].y}%`,
        width: `${cells[i].w}%`,
        height: `${cells[i].h}%`,
        background: f.avatar && !broken.has(i) ? 'transparent' : toneOf(f.real_name),
        fontSize: initialSize,
      }"
    >
      <img v-if="f.avatar && !broken.has(i)" :src="f.avatar" alt="" class="gavatar__img" @error="onImgError(i)" />
      <span v-else>{{ f.real_name.slice(0, 1) }}</span>
    </div>
  </div>

  <el-avatar v-else :size="size" :src="src || undefined" :style="{ '--keel-avatar-size': `${size}px` }">
    {{ name.slice(0, 1) }}
  </el-avatar>
</template>

<style scoped>
/* 外框与普通头像一样是圆形；格子之间 1px 白缝，拼起来才看得出是几个人 */
.gavatar {
  position: relative;
  flex: none;
  overflow: hidden;
  border-radius: 50%;
  background: var(--el-bg-color);
}

.gavatar__cell {
  position: absolute;
  display: flex;
  align-items: center;
  justify-content: center;
  box-sizing: border-box;
  border: 0.5px solid var(--el-bg-color);
  overflow: hidden;
  color: var(--el-color-white);
  line-height: 1;
}

.gavatar__img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
</style>
