# 页型模板

新模块从这里复制起步，不要从零搭。五种页型的选型规则、每种的交互规范见
[PROJECT.md §9](../../../../PROJECT.md)。

| 目录 | 页型 | 开发环境预览 | 现有实证 |
|---|---|---|---|
| `list/` | 标准列表页 | `/template/list` | 岗位、角色 |
| `tree-list/` | 树表联动页 | `/template/tree-list` | 用户（部门树）、部门 |
| `master-detail/` | 主从页 | `/template/master-detail` | 字典类型 + 字典项 |
| `form/` | 表单页 | `/template/form` | — |
| `detail/` | 详情页 | `/template/detail/1` | 个人中心 |

## 怎么用

1. 复制整个目录到 `views/你的模块/`
2. 把 `import ... from '../_demo'` 换成你自己的 `api/你的模块.ts`
3. 改列定义、筛选字段、表单字段与校验规则
4. **给每个按钮补 `v-permission`**，权限点要与后端 `config/route.php` 里的
   `perm` 声明逐字一致
5. 删掉文件顶部的「复制清单」注释块

## 为什么模板是可以打开的页面

只放在目录里、不被任何地方 import 的 `.vue` 文件，vite 根本不会编译它——
组件签名变了也没人知道，直到第一个复制的人踩坑。所以模板注册成了
**开发环境专属路由**（`router/index.ts` 的 `templateRoutes`），
`vite build` 时 `import.meta.env.DEV` 为 false，整块被 tree-shaking 掉，不进生产包。

想确认模板本身能否编译（`vue-tsc` **不解析模板结构**，查不出漏闭合标签这类问题）：

```bash
# dev server 会真的把 SFC 编译一遍，模板写错就是 500 + "Element is missing end tag"
for m in list tree-list master-detail form detail; do
  curl -s -o /dev/null -w "$m %{http_code}\n" "http://localhost:5173/src/views/template/$m/index.vue"
done
```

⚠️ 不要指望 `vite build --mode development`：`vite build` 固定 `NODE_ENV=production`，
`import.meta.env.DEV` 仍是 false，模板照样不会被编译进去。

## `_demo.ts`

模板用的内存假数据，函数签名与真接口一致（分页入参、`PageResult` 出参），
换成真接口时页面代码一行都不用改。**复制后删掉对它的引用。**
