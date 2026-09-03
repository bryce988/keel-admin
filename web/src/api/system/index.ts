/**
 * 系统管理接口的出口
 *
 * 按模块分文件（user / dept / post / menu / role / dict / param），改一个模块
 * 不会把另外六个卷进 diff——原先它们挤在一个 456 行的 `api/system.ts` 里，
 * 而 `views/system/` 那边本来就是按模块分目录的，两边对不上。
 *
 * 页面继续从 `@/api/system` 引，不必关心接口落在哪个文件；
 * 只想要一个模块时也可以直接引 `@/api/system/user`。
 */
export * from './user'
export * from './dept'
export * from './post'
export * from './menu'
export * from './role'
export * from './dict'
export * from './param'
