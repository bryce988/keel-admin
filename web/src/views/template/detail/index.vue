<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeft, Plus } from '@element-plus/icons-vue'
import type { ProColumn, ProTableInstance } from '@/components'
// ⛔ 复制后把这一行换成你自己的 api/xxx.ts
import { categoryName, fetchDemoChildren, fetchDemoDetail, type DemoRow } from '../_demo'

/**
 * 【模板 ⑤】详情页 —— 左栏静态属性，右栏动态区块
 *
 * 只读信息 + 多个关联对象 + 变更记录时用它（PROJECT.md §9.5）。
 * 信息少的详情不要单开页面，用 `FormDrawer` 的 `mode: 'view'` 就够
 * （系统管理七个模块的「详情」全是那种）。
 *
 * 个人中心 `views/profile/` 是这个页型的一个真实实现，可以对照着看。
 *
 * 复制清单：
 *   1. 换掉 `../_demo`；id 从路由参数取，不要从上一页 props 传——
 *      详情页要能直接分享链接打开
 *   2. 左栏只放不会变的属性，右栏放列表、关联对象、变更记录
 *   3. 关联区块为 0 条时显示空状态 + 新建入口，不要隐藏整个区块，
 *      否则用户不知道这里本来能挂东西
 */
const route = useRoute()
const router = useRouter()

const info = ref<DemoRow | null>(null)
const loading = ref(false)
const notFound = ref(false)

const childTableRef = ref<ProTableInstance | null>(null)

/*
 * 关联区块的筛选条件里带着主记录 id
 *
 * ⚠️ 光改 childQuery 是不够的：ProTable 挂载时就已经用 master_id=0
 * 取过一次数了，而它的约定是「筛选条件变化不自动请求，由页面显式 reload()」。
 * 少了下面那句 reload()，关联区块会永远是空的——而且不报错，
 * 看起来就像这条记录真的没有关联数据
 */
const childQuery = ref<Record<string, unknown>>({ master_id: 0 })

const childColumns: ProColumn[] = [
  { prop: 'label', label: '标签', minWidth: 140 },
  { prop: 'value', label: '值', minWidth: 120 },
  { prop: 'sort', label: '排序', width: 80, align: 'center' },
  { prop: 'status', label: '状态', width: 90, align: 'center', dict: 'enable_status' }
]

/**
 * 变更记录：字段级差异（旧值 → 新值），不可删除（§9.5）
 *
 * 真实项目里这块通常来自操作日志接口 `/admin/logs/operation`——
 * 按 target 过滤出这条记录的变更即可，不用另建一张表。
 */
const changes = ref([
  { id: 3, at: '2026-08-16 14:02:11', by: '王强', field: '负责人', old: '李娜', new: '王强' },
  { id: 2, at: '2026-08-12 09:31:40', by: '赵敏', field: '状态', old: '停用', new: '启用' },
  { id: 1, at: '2026-08-01 10:15:02', by: '赵敏', field: '—', old: '', new: '创建' }
])

async function load() {
  const id = Number(route.params.id ?? route.query.id ?? 1)

  loading.value = true
  notFound.value = false
  try {
    info.value = await fetchDemoDetail(id)
    childQuery.value.master_id = id
    childTableRef.value?.reload()
  } catch {
    // 404 与「无权查看」在服务端是同一个响应（api.md §2.2：含无权见的伪装），
    // 前端也就只能一起处理成「不存在」
    notFound.value = true
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div v-loading="loading" class="page detail-page">
    <el-result
      v-if="notFound"
      icon="warning"
      title="数据不存在或已被删除"
      sub-title="它可能已被删除，也可能不在你的数据权限范围内"
    >
      <template #extra>
        <el-button type="primary" @click="router.back()">返回列表</el-button>
      </template>
    </el-result>

    <template v-else>
      <div class="head">
        <el-button link :icon="ArrowLeft" @click="router.back()">返回</el-button>
        <span class="title">{{ info?.name || '—' }}</span>
        <DictTag code="enable_status" :value="info?.status" />
      </div>

      <div class="cols">
        <!-- 左栏：静态属性，只读 -->
        <el-card class="side" shadow="never">
          <template #header>基本属性</template>
          <dl class="meta">
            <dt>编码</dt>
            <dd class="num">{{ info?.code || '—' }}</dd>
            <dt>分类</dt>
            <dd>{{ info ? categoryName(info.category_id) : '—' }}</dd>
            <dt>负责人</dt>
            <dd>{{ info?.owner || '—' }}</dd>
            <dt>排序</dt>
            <dd class="num">{{ info?.sort ?? '—' }}</dd>
            <dt>创建时间</dt>
            <dd class="num">{{ info?.created_at || '—' }}</dd>
            <dt>备注</dt>
            <dd>{{ info?.remark || '—' }}</dd>
          </dl>
        </el-card>

        <!-- 右栏：动态数据与关联对象 -->
        <div class="body">
          <el-card shadow="never">
            <template #header>
              <div class="card-head">
                <span>关联明细</span>
                <el-button link type="primary" :icon="Plus">新增</el-button>
              </div>
            </template>

            <!--
              关联区块为 0 条时 ProTable 自己会显示空状态。
              区块本身不隐藏：隐藏了用户就不知道这里能挂东西
            -->
            <ProTable
              ref="childTableRef"
              v-model:params="childQuery"
              :request="fetchDemoChildren"
              :columns="childColumns"
              :sync-url="false"
            />
          </el-card>

          <el-card shadow="never">
            <template #header>变更记录</template>
            <el-timeline>
              <el-timeline-item
                v-for="item in changes"
                :key="item.id"
                :timestamp="item.at"
                placement="top"
              >
                <div class="change">
                  <b>{{ item.by }}</b>
                  <template v-if="item.old || item.new !== '创建'">
                    修改了「{{ item.field }}」：
                    <span class="old">{{ item.old || '空' }}</span>
                    <span class="arrow">→</span>
                    <span class="new">{{ item.new }}</span>
                  </template>
                  <template v-else>创建了这条记录</template>
                </div>
              </el-timeline-item>
            </el-timeline>
          </el-card>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.head {
  display: flex;
  align-items: center;
  gap: 10px;
}

.head .title {
  font-size: 16px;
  font-weight: 600;
  color: var(--el-text-color-primary);
}

.cols {
  display: grid;
  grid-template-columns: 300px minmax(0, 1fr);
  gap: 16px;
  align-items: start;
}

@media (max-width: 1100px) {
  .cols {
    grid-template-columns: minmax(0, 1fr);
  }
}

.body {
  display: flex;
  flex-direction: column;
  gap: 16px;
  min-width: 0;
}

/* dl 而不是 el-descriptions：300px 的窄栏里 descriptions 的边框
   会把值挤成两三行 */
.meta {
  display: grid;
  grid-template-columns: 64px 1fr;
  gap: 10px 12px;
  margin: 0;
}

.meta dt {
  color: var(--el-text-color-secondary);
}

.meta dd {
  margin: 0;
  color: var(--el-text-color-primary);
  word-break: break-all;
}

.card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.change {
  font-size: 13px;
  color: var(--el-text-color-regular);
}

.change .old {
  color: var(--el-text-color-secondary);
  text-decoration: line-through;
}

.change .arrow {
  margin: 0 4px;
  color: var(--el-text-color-secondary);
}

.change .new {
  color: var(--el-color-primary);
}
</style>
