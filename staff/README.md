# Keel 移动工作台（uni-app）

给**系统人员**用的 App：登的是后台**同一套账号**（`sys_users`）、同一套权限点、
同一份数据权限。四个页面：登录、首页（工作台）、消息（系统公告）、我的。

**身份共用，接口不共用**：调的是员工移动端自己的一套 `/staff/v1/*`（后端 `app/staff`），
不是后台的 `/admin/*`。理由见 PROJECT.md §8.1——后台接口是给宽屏与完整表单设计的，
移动端要聚合与瘦身，且迟早要长出强制更新、推送注册这类后台没有的东西；
反过来后台改字段也不该波及已经装在别人手机上的 App。业务逻辑仍然复用同一批 service。

C 端那套（`app_users` + `/client/v1/*`）是脚手架给真·C 端 App 留的示例，
与这里是**两套身份体系**，令牌互调一律 401。

技术栈：**uni-app + Vue 3 + `<script setup>`**，写法与 `web/` 基本一致
（`ref` / `computed`），页面生命周期从 `@dcloudio/uni-app` 引入。

## 跑起来

1. HBuilderX 打开这个目录（项目已由 HBuilderX 创建，`appid` 已就位）
2. **后端地址不用改**：`common/config.js` 的 `BASE_URL` 固定指向线上预览环境
   `http://43.143.249.52:8080`。要连本地后端时临时改成开发机的局域网 IP
   （`http://192.168.x.x:8787`）——**不能写 localhost**，那是手机自己的回环地址
3. 运行到手机 / 模拟器 / 浏览器都行
4. **演示账号**：`admin` / `admin123`（验证码点图片可换一张）

浏览器预览要后端放行跨域：`.env` 的 `CORS_ALLOW_ORIGINS`（线上已配
`http://localhost:*`）。真机不需要——App 没有同源策略。

## 打包 APK

HBuilderX → 发行 → 原生 App-云打包 → Android，用公共测试证书即可出包。

- `versionName` / `versionCode`：发版时两个一起改，应用市场按 `versionCode` 判新旧
- 权限已收敛到三条（联网、网络状态、读图片）。默认模板申请了相机、通话状态、
  读日志、改系统设置——这个 App 一条都用不上，**多声明一条就多一次被问用途**

## 目录

```
staff/
├── manifest.json      应用配置（appid 由 HBuilderX 分配，别手改）
├── pages.json         页面注册与 tabBar（第一项 login 是启动页）
├── App.vue            启动时按有没有令牌决定进哪一页
├── common/
│   ├── config.js      后端地址、客户端版本号
│   ├── request.js     请求层：令牌、401 统一踢回登录、权限判断、文件上传
│   └── api.js         接口定义，只放请求不放业务判断
├── pages/
│   ├── login/         登录（账号 + 密码 + 图形验证码）
│   ├── index/         首页：工作台概览
│   ├── notice/        消息：list 列表（下拉刷新 + 触底加载）· detail 详情
│   └── mine/          我的：资料、换头像、退出
├── static/
│   ├── icons/         App 图标 48/72/96/144/192/512/1024（脚本生成，见下）
│   ├── tabbar/        底部导航图标（脚本生成，见下）
│   └── logo.png       品牌标记 512（就是 icon-512，模板自带的 uni logo 已顶掉）
└── scripts/
    ├── make-app-icon.py       生成 App 图标
    └── make-tabbar-icons.py   生成 tabBar 图标
```

## App 图标

图形与 `web/public/favicon.svg`、`web/src/components/BrandLogo.vue` 是**同一份**：
船体肋骨的横剖 + 一根贯穿的龙骨主梁——龙骨是全船肋骨唯一的附着点，
这个脚手架对业务代码就是这个关系。改标记要三处一起改。

**已经配进 `manifest.json` 了**，直接云打包即可：

```jsonc
"app-plus": { "distribute": { "icons": { "android": {
  "hdpi": "static/icons/icon-72.png",     // 72×72
  "xhdpi": "static/icons/icon-96.png",    // 96×96
  "xxhdpi": "static/icons/icon-144.png",  // 144×144
  "xxxhdpi": "static/icons/icon-192.png"  // 192×192，ldpi/mdpi 官方已废弃
}}}}
```

⚠️ **不配这一段，云打包出来是 uni-app 的默认图标**，而且不会有任何提示——
要装到手机上才发现。改图标后记得重新打包，已安装的包不会自己换图标。

也可以走 HBuilderX 的可视化界面（manifest.json → App 图标配置 → 选
`static/icons/icon-1024.png` → 自动生成所有图标），它会把图标生成到
`unpackage/res/icons` 并改写 manifest 里的路径。两种方式二选一即可，
区别是可视化生成的图标进不了 Git（`unpackage/` 是编译产物，不提交），
换台机器 clone 下来就没有了——所以这里选的是前一种。

**启动图（splash）还是默认的**：要换的话在同一个界面配，或者告诉我，
我按同一份标记生成几张。

图标与 tabBar 图标都是纯几何图形，用脚本画而不是丢一堆 png 进来：二进制进了仓库就没人知道它从哪来，
改颜色或尺寸时只能重新找设计稿。tabBar 图标的颜色与 `pages.json` 的
`tabBar.color` / `selectedColor` 对齐，改了要一起改。重生成：

```bash
python3 scripts/make-app-icon.py       # static/icons/*
python3 scripts/make-tabbar-icons.py   # static/tabbar/*
```

两个脚本都只用标准库（本机没有 PIL / rsvg / magick）：按 PNG 规范拼字节，
App 图标用距离场算覆盖率抗锯齿，tabBar 图标用超采样。

## 四条必须知道的

1. **验证码是一次性的**。后端验过就从 Redis 删掉，无论对错。所以登录失败后
   **必须换一张**，否则用户拿着已作废的码重试，只会一直看到「验证码错误」，
   明明密码已经改对了。
2. **判成败看 `statusCode`，不是看有没有进 fail**。`uni.request` 的 `fail` 只在
   网络层出错时触发，后端返回 400/401/403/500 一律走 `success`。这与 web 端
   「先按 HTTP 状态码分派，再按业务码细化」是同一条约定。
3. **权限判断以服务端为准**。工作台的 `dashboard.visible` 是后端算好下发的，
   客户端不拿缓存的权限点自己判断——权限是登录那一刻的快照，撤权之后本地还以为有，
   界面上就是一块永远加载失败的区域。本地的 `can()` 只用于纯展示性的收敛，
   和 web 端的 `v-permission` 一样**不是安全边界**，真正的拦截在后端路由的 `perm` 声明上。
4. **令牌会自动续期，不要在登录页清本地令牌**。access 2 小时、refresh 7 天，
   `request.js` 收到 401 会先用 refresh 换一次再重试原请求，换不动才回登录页；
   刷新做了单飞（多个请求同时 401 只发一次刷新），因为后端的 refresh 是用过即废的轮换。
   ⚠️ 登录页的 `onLoad` 里**不能** `clearAuth()`——冷启动时登录页是入口页，
   即使 `App.vue` 已经判断有令牌切去了首页，它照样会触发 `onLoad`，
   清掉令牌的结果就是「退出 App 再打开还要重新登录」。
5. **上传不要手写 `Content-Type`**。`uni.uploadFile` 要自己拼 multipart 的 boundary，
   手写会漏掉它，后端解析不出文件；另外它回来的 `data` 是**字符串**，要自己 `JSON.parse`。

## 与后端的契约

见 `docs/api.md` §13：

| 用途 | 接口 | 权限 |
|---|---|---|
| 图形验证码 | `GET /staff/v1/auth/captcha` | 免登录 |
| 刷新令牌（自动，7 天内免登录） | `POST /staff/v1/auth/refresh` | 免登录 |
| 登录（**一次返回令牌 + 身份 + 权限**） | `POST /staff/v1/auth/login` | 免登录 |
| 工作台（**一次返回身份 + 概览**） | `GET /staff/v1/workbench` | 登录即可 |
| 消息列表（含未读数） | `GET /staff/v1/notices` | 登录即可 |
| 读一条（顺带标已读） | `GET /staff/v1/notices/{id}` | 登录即可 |
| 全部已读 | `POST /staff/v1/notices/read-all` | 登录即可 |
| 个人资料 | `GET` / `PUT /staff/v1/profile` | 登录即可 |
| 换头像 | `POST /staff/v1/profile/avatar` | 登录即可 |
| 退出 | `POST /staff/v1/auth/logout` | 登录即可 |

两个「一次返回」是接口分端后立刻拿到的收益：后台那边登录要两次请求、
首页要三次，手机上每多一次往返就多一次转圈。

请求要带三个渠道头（`X-Channel` / `X-App-Version` / `X-Device-Id`），
缺一个就是 400——都在 `request.js` 的 `headers()` 里统一加。

头像由服务端拼成绝对地址（`APP_URL`）：「用哪个域名」是部署方的知识，
不该编译进别人手机里的安装包。`absUrl()` 只是 `APP_URL` 没配时的兜底。
