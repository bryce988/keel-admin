/**
 * 护栏：全局样式里覆盖 Element Plus 的规则必须带 `:root` 前缀
 *
 * ## 为什么需要这个检查
 *
 * 接了按需导入（`174f2f5`）之后，EP 的组件样式不再是一个大 CSS，而是拆成
 * `el-table-column-*.css` 这类**懒加载 chunk**，进到用得上它的页面时才插进 <head>。
 * 而 `styles/index.css` 是入口 CSS，index.html 里一个 <link> 就加载了。
 * 结果是：EP 的规则永远排在我们后面。
 *
 * 偏偏 EP 用的选择器和我们想写的一模一样——它把组件令牌声明在 `.el-table`、
 * `.el-button` 这种单类选择器上。特异性相同、它在后面，于是**它赢**。
 *
 * 这类失效不报错、不告警，DevTools 里我们那条规则也确确实实在，
 * 只是被划了删除线——不把两条规则并排列出来根本看不出是谁赢了。
 * 已经踩过三次：
 *
 *   - 分页左右箭头圆角一直是 EP 的 2px，与页码的 4px 对不上
 *   - 卡片圆角改了没反应（EP 的 el-card.css 后到）
 *   - 表头底色改了没反应，表头与表体一样白，一排表头看不出是表头
 *
 * 加一个 `:root` 前缀就够了：`:root .el-table` 是 (0,2,0)，EP 的 `.el-table`
 * 是 (0,1,0)，不用再跟加载顺序赌。这个脚本保证以后不会有人忘。
 *
 * ## 判断规则
 *
 * 只看选择器的**第一个复合选择器**：以 `.el-` 开头的，必须由 `:root` 起头。
 * `.table-actions .el-button + .el-button` 不算——它锚在我们自己的类上，
 * 特异性本来就比 EP 高。
 */
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const files = ['src/styles/index.css']

const problems = []

for (const file of files) {
  const raw = readFileSync(resolve(root, file), 'utf8')
  // 去掉注释，免得注释里举的例子被当成真规则
  const css = raw.replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ' '))

  // 每个 `{` 之前那一段就是选择器列表；@media 之类以 @ 开头，跳过
  const re = /(^|[};])\s*([^{}@;]+?)\s*\{/g
  let m
  while ((m = re.exec(css))) {
    // 从选择器自身的位置算行号：m.index 落在上一条规则的 `}` 上，
    // 中间隔着注释的话会报出一个差二十几行的行号，照着找不到
    const line = css.slice(0, css.indexOf(m[2], m.index)).split('\n').length
    for (const selector of m[2].split(',').map((s) => s.trim()).filter(Boolean)) {
      const first = selector.split(/[\s>+~]+/)[0]
      if (first.startsWith('.el-') && !selector.startsWith(':root')) {
        problems.push(`${file}:${line}  ${selector}`)
      }
    }
  }
}

if (problems.length) {
  console.error('覆盖 Element Plus 的规则缺少 :root 前缀，会被后加载的 EP 样式静默盖掉：\n')
  problems.forEach((p) => console.error('  ' + p))
  console.error('\n改成 `:root ' + '<原选择器>' + '` 即可（见本脚本顶部注释）。')
  process.exit(1)
}

console.log('EP 覆盖前缀检查通过')
