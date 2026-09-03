# Keel C 端（uni-app）

App / 小程序端，对应后端的 `server/app/client`（URL 前缀 `/client/*`）。
三个页面：**登录**、**首页**、**我的**（改昵称、换头像、退出登录）。
链路是真的——换真令牌、资料从 `app_users` 表读、头像传到服务器并落库。

技术栈是 **uni-app + Vue 3 + `<script setup>`**（不是 uni-app x）：
写法与 `web/` 基本一致（`ref` / `computed` / 组合式 API），
页面生命周期从 `@dcloudio/uni-app` 引入（`onLoad` / `onShow` / `onLaunch`）。

## 跑起来

1. HBuilderX 打开这个目录（项目已由 HBuilderX 创建，`appid` 已就位）
2. **后端地址不用改**：`common/config.js` 的 `BASE_URL` 固定指向线上预览环境
   `http://43.143.249.52:8080`，真机、模拟器、别人的机器上都一样。
   要连本地后端时临时改成开发机的局域网 IP（`http://192.168.x.x:8787`）——
   **不能写 localhost**，那是手机自己的回环地址；改完记得别提交
3. 运行 → 运行到手机或模拟器 / 运行到浏览器都行
4. **演示账号**：`13900139001` / `app123456`（`server/scripts/seed.php --demo` 播的）

## 打包 APK

HBuilderX → 发行 → 原生 App-云打包 → Android，用公共测试证书即可出包。

- `versionName` / `versionCode`：发版时两个一起改，应用市场按 `versionCode` 判新旧
- 权限已收敛到三条（联网、网络状态、读图片）。默认模板申请了相机、通话状态、
  读日志、改系统设置——这个示例一条都用不上，**多声明一条就多一次被问用途**

## 目录

```
client/
├── manifest.json      应用配置（appid 由 HBuilderX 分配，别手改）
├── pages.json         页面注册与 tabBar（第一项 login 是启动页）
├── App.vue            启动时按有没有令牌决定进哪一页
├── common/
│   ├── config.js      后端地址、渠道标识
│   ├── request.js     请求层：渠道头、令牌、401 统一踢回登录页、文件上传
│   └── api.js         接口定义，只放请求不放业务判断
└── pages/
    ├── login/         登录
    ├── index/         首页
    └── mine/          我的
```

## 三条必须知道的

1. **渠道头是强制的**。`X-Channel` / `X-App-Version` / `X-Device-Id` 缺一个就是 400
   （后端 `ChannelMiddleware`）。它们在 `request.js` 的 `headers()` 里统一加，
   页面不要自己调 `uni.request`。
2. **判成败看 `statusCode`，不是看有没有进 fail**。`uni.request` 的 `fail` 只在网络层
   出错时触发，后端返回 400/401/500 一律走 `success`。这与 web 端「先按 HTTP 状态码
   分派，再按业务码细化」是同一条约定。
3. **上传不要手写 `Content-Type`**。`uni.uploadFile` 要自己拼 multipart 的 boundary，
   手写会漏掉它，后端解析不出文件；另外它回来的 `data` 是**字符串**，要自己 `JSON.parse`。

## 与后端的契约

见 `docs/api.md` §12.3。要点：

- 令牌 `type=client`，与员工令牌**永不混用**：拿它调 `/admin/*` 一律 401，反之亦然
- 错误体只有 `{code, message}`：没有 `details`、没有 `trace_id`（刻意的，见 `ClientHandler`）
- 头像下发绝对地址，由后端 `.env` 的 `APP_URL` 拼——App 没有「当前域名」可补全相对路径
- 登录失败码：`30101` 手机号或密码错误、`30102` 已封禁、`30103` 连错 5 次锁 15 分钟
