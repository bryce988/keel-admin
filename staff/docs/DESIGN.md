---
version: 1.0
name: Keel 移动工作台
description: 给系统人员用的 uni-app 工作台（首页概览、消息、我的）。借用 Apple 的设计语言——parchment 底上的白色分组面板、单一强调色、600 字重的紧凑标题、没有阴影——落成 iOS 原生应用的语法：大标题、内嵌分组列表、胶囊按钮、按下缩放。品牌色沿用后台的 #409eff，同一个账号在两端看到同一种蓝。

colors:
  primary: "#409eff"
  primary-light-9: "#ecf5ff"
  success: "#67c23a"
  warning: "#e6a23c"
  danger: "#f56c6c"
  danger-light-9: "#fef0f0"
  info: "#909399"
  info-light-9: "#f4f4f5"
  ink: "#1d1d1f"
  ink-regular: "#333333"
  ink-secondary: "#6e6e73"
  ink-tertiary: "#7a7a7a"
  canvas-parchment: "#f5f5f7"
  surface: "#ffffff"
  surface-pearl: "#fafafc"
  surface-dark: "#272729"
  on-dark-muted: "#cccccc"
  hairline: "#e0e0e0"
  pressed: "#e8e8ed"
  on-primary: "#ffffff"

typography:
  large-title:
    fontSize: 34px
    fontWeight: 600
    lineHeight: 1.2
    letterSpacing: -0.374px
  stat-value:
    fontSize: 34px
    fontWeight: 600
    lineHeight: 1.1
    letterSpacing: -0.374px
    fontVariantNumeric: tabular-nums
  title:
    fontSize: 28px
    fontWeight: 600
    lineHeight: 1.14
    letterSpacing: -0.28px
  headline:
    fontSize: 21px
    fontWeight: 600
    lineHeight: 1.19
  body:
    fontSize: 17px
    fontWeight: 400
    lineHeight: 1.47
  body-strong:
    fontSize: 17px
    fontWeight: 600
    lineHeight: 1.35
  subhead:
    fontSize: 15px
    fontWeight: 400
    lineHeight: 1.4
  caption:
    fontSize: 14px
    fontWeight: 400
    lineHeight: 1.43
  footnote:
    fontSize: 12px
    fontWeight: 400
    lineHeight: 1.5

fontFamily: '-apple-system, BlinkMacSystemFont, "SF Pro Text", "PingFang SC", "Hiragino Sans GB", "Helvetica Neue", "Microsoft YaHei", system-ui, sans-serif'

rounded:
  sm: 8px
  lg: 18px
  pill: 999px

spacing:
  xxs: 4px
  xs: 8px
  sm: 12px
  md: 16px
  lg: 20px
  xl: 24px
  xxl: 32px

motion:
  press-scale: 0.95
  press-duration: 0.12s

components:
  screen:
    backgroundColor: "{colors.canvas-parchment}"
    padding: "calc(var(--status-bar-height) + 12px) 16px 32px"
  large-title:
    typography: "{typography.large-title}"
    textColor: "{colors.ink}"
  welcome-tile:
    backgroundColor: "{colors.surface-dark}"
    textColor: "{colors.on-primary}"
    typography: "{typography.title}"
    rounded: "{rounded.lg}"
    padding: 24px 20px
  section-head:
    typography: "{typography.headline}"
    margin: 28px 4px 10px
  group:
    backgroundColor: "{colors.surface}"
    rounded: "{rounded.lg}"
  row:
    minHeight: 50px
    padding: 0 16px
    separator: "0.5px {colors.hairline}, inset 16px"
  row-pressed:
    backgroundColor: "{colors.pressed}"
  stat-grid:
    backgroundColor: "{colors.surface}"
    rounded: "{rounded.lg}"
    columns: 2
    cellPadding: 16px
  notice-row:
    padding: 14px 16px 14px 8px
    unreadDot: "8px {colors.primary}"
  article:
    backgroundColor: "{colors.surface}"
    padding: 24px 20px 48px
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.on-primary}"
    typography: "{typography.body}"
    rounded: "{rounded.pill}"
    height: 50px
  text-link:
    textColor: "{colors.primary}"
    typography: "{typography.subhead}"
  chip-on-dark:
    backgroundColor: "rgba(255, 255, 255, 0.14)"
    textColor: "{colors.on-primary}"
    typography: "{typography.footnote}"
    rounded: "{rounded.pill}"
    padding: 2px 10px
  role-chip:
    border: "1px solid {colors.hairline}"
    textColor: "{colors.ink-regular}"
    typography: "{typography.footnote}"
    rounded: "{rounded.pill}"
    padding: 2px 10px
  tab-bar:
    native: true
    backgroundColor: "{colors.surface}"
    color: "{colors.ink-tertiary}"
    selectedColor: "{colors.primary}"
    borderStyle: white
---

## 概述

Keel 移动工作台是给系统人员在手机上用的工具，只做三件事：看一眼组织概况、读公告、维护自己的资料。
它不是营销页，没有产品图可以撑场面，所以「高级感」全部来自**克制**：

- 页面是 parchment 灰底，内容放在白色圆角面板里——**底色切换本身就是分隔**，全 App 没有一处阴影
- 只有一种强调色（品牌蓝），它只出现在可以点的东西上
- 标题 600 字重、负字距，正文 17px，字重只有 400 / 600 两档
- 醒目的地方只有一处：首页问候区那块深色面。其余地方都安静

设计语言借自 Apple（官网的 parchment / 墨色 / 单一强调色 / 无阴影），语法借自 iOS 原生应用
（大标题、内嵌分组列表、设置页式的表单、破坏性操作单独成组）。
唯一不照搬的是品牌色：Apple 用 `#0066cc`，这里用后台的 `#409eff`——两端是同一个账号，应该是同一种蓝。

**实现位置**：令牌在 `common/theme.css`，通用零件在 `common/ui.css`（App.vue 全局引入），
页面样式一律 `<style scoped>`。本文件与这两份 CSS 不一致时，以 CSS 为准并回头改本文件。

## 颜色

### 品牌与语义

- **品牌蓝** `{colors.primary}` #409eff：全 App 唯一的交互色——主按钮、文字链接、「更换」头像、未读圆点、tabBar 选中态。
  不做装饰：统计数字、角色标签、分区标题都不用它
- **语义色**只在「状态需要被看见」时出现，每种都有明确的触发条件：
  - `{colors.danger}` #f56c6c：今日登录失败数 > 0、紧急公告、退出登录、表单错误
  - `{colors.success}` / `{colors.warning}`：目前没有页面使用，保留给真实的状态信号
  - 后端 `dashboard.stats[].tone` 的前三张卡是固定配色（primary / success / warning），**不代表状态**，App 不渲染它们；
    只有 `danger` 会上色
- 语义色与品牌色都取自 Element Plus 官方色板，与后台 `web/` 同源

### 中性色

| 令牌 | 色值 | 用途 |
|---|---|---|
| `ink` | #1d1d1f | 所有主文字：标题、正文、行标签、输入内容、统计数字 |
| `ink-regular` | #333333 | 角色标签这类中性小件上的文字 |
| `ink-secondary` | #6e6e73 | 副标题、说明、只读值、时间、摘要 |
| `ink-tertiary` | #7a7a7a | 占位符、页脚小字、tabBar 未选中态 |
| `canvas-parchment` | #f5f5f7 | 页面底色（所有 tab 页、登录页） |
| `surface` | #ffffff | 分组面板、公告详情整页、tabBar |
| `surface-pearl` | #fafafc | 验证码图片的衬底 |
| `surface-dark` | #272729 | 首页问候区，全 App 唯一的深色面 |
| `on-dark-muted` | #cccccc | 深色面上的次要文字 |
| `hairline` | #e0e0e0 | 行间细线、角色标签描边（以 0.5px 绘制） |
| `pressed` | #e8e8ed | 列表行按下 |

**为什么次要文字是 #6e6e73 而不是 Apple 设计分析里的 #7a7a7a**：后者在 #f5f5f7 上对比度只有 4.0，
达不到 WCAG AA 的 4.5；#6e6e73 是 Apple 官网自己的次要灰，在灰底上 4.66、白底上 5.07。#7a7a7a 只用于占位符和页脚小字。

**实色底上的纯白前景**（按钮文字、深色面上的姓名、头像首字）可以直接写 `#fff`，这是唯一允许的字面色值。

## 字体

**字体栈**：`-apple-system, BlinkMacSystemFont, "SF Pro Text", "PingFang SC", …, system-ui, sans-serif`。
iOS 上就是 SF Pro + 苹方，Android 上是系统字体。**不加载网络字体**：App 要能离线打开，
而且系统字体在各平台都已经是为屏幕调校过的最佳选择。

| 令牌 | 字号 / 字重 / 行高 / 字距 | 用在哪 |
|---|---|---|
| `large-title` | 34 / 600 / 1.2 / -0.374px | tab 页大标题（工作台、消息、我的）、登录页的 Keel |
| `stat-value` | 34 / 600 / 1.1 / -0.374px，等宽数字 | 概览数字 |
| `title` | 28 / 600 / 1.14 / -0.28px | 首页问候语、公告详情标题 |
| `headline` | 21 / 600 / 1.19 | 分区标题（概览）、「我的」里的姓名、空状态标题 |
| `body` | 17 / 400 / 1.47 | 行标签、输入内容、公告正文、按钮文字 |
| `body-strong` | 17 / 600 / 1.35 | 未读公告标题、状态卡标题 |
| `subhead` | 15 / 400 / 1.4 | 大标题下的副标题、文字链接、公告摘要、账号与部门 |
| `caption` | 14 / 400 / 1.43 | 统计标签与单位、公告时间、详情的发布信息、错误提示 |
| `footnote` | 12 / 400 / 1.5 | 统计说明、公告类型与发布人、标签胶囊、页脚 |

**规则**：

- 字重只有 **400 和 600**。不写 `bold`（=700），不用 500
- 负字距只用在 28px 及以上；中文在更小字号收紧会挤
- 数字列用 `font-variant-numeric: tabular-nums`，刷新时位数不跳
- 正文 17px 而不是 16px——iOS 的默认阅读字号

## 布局

### 间距

基准 4px，结构间距取 4 / 8 / 12 / 16 / 20 / 24 / 32：

- 页面左右边距 **16px**（登录页与公告详情页 20px）
- 面板与面板之间 **20px**；分区标题上方 **28px**、下方 10px；退出登录这类破坏性操作组上方 **32px**，刻意拉开
- 行内左右 16px，行高至少 **50px**（触控目标 ≥ 44px）
- tab 页顶部 = 状态栏高度 `var(--status-bar-height)` + 12px

### 页面骨架

**tab 页**（首页、消息、我的）用自定义导航（`pages.json` 的 `navigationStyle: custom`），大标题写在页面内容里，
随内容滚动；原生导航栏只剩状态栏。**tabBar 保持原生**。

```
状态栏
工作台                  ← large-title，左对齐
9月11日 星期五          ← subhead，ink-secondary
┌── surface-dark ──────┐
│ 你好，系统管理员      │ ← title，白
│ 总公司 [超级管理员]   │ ← subhead on-dark-muted + chip-on-dark
└──────────────────────┘
概览               刷新 ← headline + text-link
┌──────────┬──────────┐
│ 用户      │ 组织      │ ← stat-grid：一块面板，0.5px 细线分成四格
│ 5 人      │ 3 个部门  │
├──────────┼──────────┤
│ …        │ …        │
└──────────┴──────────┘
页脚说明                ← footnote，不做卡片
```

**二级页**（公告详情）用原生导航栏（白底），整页白色阅读面：从灰底的列表点进来，底色切换本身就说明「进到正文里了」。

**对齐**：全 App 左对齐，一个页面只有一条左边线。居中只用于两处：空状态、「退出登录」这类单独成组的按钮行。

## 层次与深度

| 层级 | 做法 | 用在哪 |
|---|---|---|
| 底 | `canvas-parchment` | 页面 |
| 面板 | `surface` + 18px 圆角，无描边无阴影 | 分组列表、概览、身份卡 |
| 强调面 | `surface-dark` | 首页问候区（只此一处） |
| 分隔 | 0.5px `hairline`，左缩进 16px | 行与行之间 |

**不用阴影**，一处都没有。层次只靠底色切换。
0.5px 细线用伪元素 + `transform: scaleY(0.5)` 画——直接写 `border: 1px` 在高分屏上是 2~3 个物理像素，显粗。

## 形状

| 令牌 | 值 | 用在哪 |
|---|---|---|
| `sm` | 8px | 验证码图片这类嵌在行里的小件 |
| `lg` | 18px | 所有面板：分组列表、概览、深色问候区 |
| `pill` | 999px | 主按钮、标签胶囊 |

三档不混用，不出现 10px、12px 这类中间值。头像是正圆。

## 组件

### 大标题 `.large-title` / `.large-title-sub`

tab 页顶部，34px。副标题放**有信息量**的东西（日期、未读数），不放装饰性的口号。
消息页的「全部标为已读」作为 text-link 放在大标题同一行的右侧，底对齐。

### 分组列表 `.group` + `.row`

一整块白色面板，行间 0.5px 细线从文字起点开始（左缩进 16px）。**不要把内容切成一张张独立小卡片**——
这是这套设计和一般后台移动端最大的区别。

- 表单行：左侧 `row-label`（80px 宽，`ink`），右侧输入或只读值；只读值用 `ink-secondary`
- 可点的行加 `hover-class="row-pressed"`
- 破坏性操作（退出登录）单独成一组，文字居中、`danger` 色

### 概览 `stat-grid`

一块面板里的 2×2：标签（caption）→ 数字（stat-value）+ 单位 → 说明（footnote）。
数字一律墨色；说明只在告警时变 `danger`。竖线上下各缩进 16px，横线两格拼成一条。

### 消息行 `notice-row`

邮件式：未读圆点**独占一列**（16px 宽），有没有圆点标题都从同一条竖线开始；
标题行右侧是时间（今天显示时刻、今年显示月日、更早显示完整日期）；摘要最多两行；
底部是类型与发布人（footnote），只有「紧急」用 `danger` 并加粗。未读标题用 `body-strong`，圆点用品牌蓝。

### 按钮 `.btn .btn-primary`

胶囊形，高 50px，全宽，17px / 400。按下 `hover-class="btn-pressed"` 缩放到 0.95。
忙碌态用 `.is-busy`（透明度 0.6）+ 文字改成进行时（「正在登录」「正在保存」），**不用 `disabled` 属性**
（uni 内置的 `button[disabled]` 灰底选择器覆盖不掉，见 `ui.css` 注释）。

### 文字链接 `.text-link`

15px 品牌蓝，无下划线。只用于次要动作：刷新、全部标为已读。头像下方的「更换」同为品牌蓝，14px。

### 标签胶囊

- `chip-on-dark`：深色面上，白色 14% 透明底——不引入第二种颜色
- `role-chip`：浅色面上，`hairline` 描边 + `ink-regular` 字。角色是信息不是动作，所以不用品牌色

### tabBar（原生）

白底，未选中 `#7a7a7a`、选中品牌蓝，`borderStyle: white`（白底与灰页面的色差本身就是分隔）。
图标由 `scripts/make-tabbar-icons.py` 生成，颜色与 `pages.json` 保持一致。

## 交互

- **按钮**：按下缩放 0.95，0.12s
- **列表行**：按下背景变 `pressed`，不缩放（iOS 的做法）
- **头像**：按下透明度 0.6
- 不做入场动画、不做悬浮效果（手机没有悬浮）
- `prefers-reduced-motion: reduce` 时去掉按钮的缩放过渡
- **弹层用原生 API**：`uni.showModal` / `uni.showActionSheet` / `uni.showToast`。
  App 端原生 tabBar 在 WebView 之上，页面里自己画的遮罩盖不住它，原生弹层可以
- 错误就地显示（登录页表单下方），不用一闪而过的 toast；操作结果用 toast

## 文案

- 按钮写动作本身：「登录」「保存资料」「退出登录」，进行时写「正在登录」「正在保存」
- 同一个动作全程同名：按钮「全部标为已读」→ 提示「已全部标为已读」
- 空状态说清楚是「真没有」还是「出错了」，并告诉用户接下来会发生什么
- 没权限不是出错：说明缺哪个权限点、找谁开通，不用红色
- 元信息不用「A · B」中点拼接：拆成两个元素，或用标签胶囊

## 可以 / 不要

### 可以

- 把新页面的内容放进 `.group`，用 `.row` 排
- 用底色切换（灰底 ↔ 白面板 ↔ 深色面）表达层次
- 状态真的需要被注意时才用语义色
- 新的共用结构先加进 `ui.css`，再在页面里用

### 不要

- 不要加阴影、渐变、描边卡片
- 不要引入第二种强调色，也不要给数字、标签、图标做装饰性上色
- 不要写十六进制色值（实色底上的 `#fff` 除外）
- 不要用 `bold` / 500 字重
- 不要写全局的页面样式（不带 `scoped`），不要在页面里写 `page { … }`
- 不要引入第三方组件库；tabBar、导航栏、弹层都用原生的

## 改色要同步的地方

CSS 变量管不到这三处，它们只收字面色值：

1. `uni.scss` 的 `$uni-*`（给插件市场组件用）
2. `pages.json` 的 `globalStyle`、各页 `navigationBarBackgroundColor`、`tabBar`
3. `scripts/make-tabbar-icons.py` 的 `NORMAL` / `ACTIVE`，改完重跑生成图标

## 已知缺口

- **暗色模式**：没做。原生 tabBar 与导航栏的颜色只能在运行时用 `uni.setTabBarStyle` / `uni.setNavigationBarColor` 改，
  图标还得另备一套，要做时整体设计
- **Android 字体**：系统字体栈在 Android 上落到 Roboto / 思源黑体，600 字重的观感比 iOS 略重，未单独调校
- **App 真机**：本规范的效果在 H5 上核对过，App 真机（状态栏留白、按下动画、WebView 内核差异）需要在设备上确认
- **品牌蓝上的白字对比度只有 2.78:1**：连大字号要求的 3:1 都不到。现在它只出现在 50px 高的主按钮上（17px 文字），
  是跟随后台色板的已知代价；**不要把品牌蓝铺成大面积底色再放白字**——深色问候区用 #272729 就是这个原因（白字 14.9:1）
