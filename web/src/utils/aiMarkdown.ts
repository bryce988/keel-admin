/**
 * 小k 回答的 Markdown 渲染
 *
 * 只认产品文档允许的那几样（docs/ai-prd.md §6）：段落、加粗、有序/无序列表、行内代码、表格，
 * 外加站内链接占位符 `[[link:N]]`。**不渲染图片、HTML、模型自己写的 Markdown 链接**。
 *
 * ## 为什么自己写而不引 markdown-it
 *
 * 安全：这里**先把整段文本做 HTML 转义，再做有限的替换**。模型输出里的任何 `<script>`、
 * `<img onerror>`、`javascript:` 链接都在第一步就变成了纯文本，后面的替换只会生成
 * 我们自己写死的几种标签——所以结果可以放心 `v-html`。用 markdown-it 要记得关 html、
 * 关 linkify、关图片、再配一个 sanitizer，漏一个就是 XSS；而我们用到的语法只有这么几样。
 *
 * 体积：主 chunk 已经 1.27MB（CLAUDE.md 的 M4 遗留），不为几十行功能再加一个依赖。
 */

export interface RenderOptions {
  /** [[link:N]] 的按钮文字，下标对应消息 extra.links */
  linkLabels: string[]
}

function escapeHtml(s: string): string {
  return s
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
}

/** 行内：代码、加粗、链接占位符。输入已经转义过 */
function inline(s: string, opt: RenderOptions): string {
  // 先把行内代码抠出来，里面的 ** 与 [[ ]] 不再解析
  const codes: string[] = []
  s = s.replace(/`([^`]+)`/g, (_, c: string) => {
    codes.push(c)
    return `\u0000${codes.length - 1}\u0000`
  })

  s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')

  s = s.replace(/\[\[link:(\d+)\]\]/g, (_, n: string) => {
    const i = Number(n)
    const label = opt.linkLabels[i]
    // 服务端已经把不合法的链接去掉了，这里再兜一次：没有对应的链接就什么都不显示
    if (label === undefined) return ''
    return `<button type="button" class="ai-link" data-link="${i}">${escapeHtml(label)} →</button>`
  })

  return s.replace(/\u0000(\d+)\u0000/g, (_, i: string) => `<code>${codes[Number(i)]}</code>`)
}

function isTableRow(line: string): boolean {
  const t = line.trim()
  return t.startsWith('|') && t.endsWith('|') && t.length > 1
}

function cells(line: string): string[] {
  return line.trim().replace(/^\||\|$/g, '').split('|').map((c) => c.trim())
}

export function renderAiMarkdown(text: string, opt: RenderOptions): string {
  const lines = escapeHtml(text.replace(/\r\n/g, '\n')).split('\n')
  const out: string[] = []
  let para: string[] = []

  const flushPara = () => {
    if (para.length) out.push(`<p>${para.map((l) => inline(l, opt)).join('<br>')}</p>`)
    para = []
  }

  let i = 0
  while (i < lines.length) {
    const line = lines[i]
    const t = line.trim()

    if (t === '') {
      flushPara()
      i++
      continue
    }

    // 表格：表头 + 分隔行 + 数据行
    if (isTableRow(line) && i + 1 < lines.length && /^\s*\|?[\s:|-]+\|?\s*$/.test(lines[i + 1]) && lines[i + 1].includes('-')) {
      flushPara()
      const head = cells(line)
      i += 2
      const body: string[][] = []
      while (i < lines.length && isTableRow(lines[i])) {
        body.push(cells(lines[i]))
        i++
      }
      out.push(
        '<div class="ai-table"><table><thead><tr>' +
          head.map((c) => `<th>${inline(c, opt)}</th>`).join('') +
          '</tr></thead><tbody>' +
          body.map((r) => '<tr>' + r.map((c) => `<td>${inline(c, opt)}</td>`).join('') + '</tr>').join('') +
          '</tbody></table></div>',
      )
      continue
    }

    // 列表：连续的 - / * / 1. 开头的行
    const ul = /^[-*]\s+(.*)$/
    const ol = /^\d+[.)]\s+(.*)$/
    if (ul.test(t) || ol.test(t)) {
      flushPara()
      const ordered = ol.test(t)
      const re = ordered ? ol : ul
      const items: string[] = []
      while (i < lines.length && re.test(lines[i].trim())) {
        items.push(lines[i].trim().replace(re, '$1'))
        i++
      }
      const tag = ordered ? 'ol' : 'ul'
      out.push(`<${tag}>${items.map((it) => `<li>${inline(it, opt)}</li>`).join('')}</${tag}>`)
      continue
    }

    // 标题：提示词里说了不要用，真用了就当加粗段落，不放大字号
    const h = /^#{1,6}\s+(.*)$/.exec(t)
    if (h) {
      flushPara()
      out.push(`<p><strong>${inline(h[1], opt)}</strong></p>`)
      i++
      continue
    }

    para.push(t)
    i++
  }
  flushPara()

  return out.join('')
}
