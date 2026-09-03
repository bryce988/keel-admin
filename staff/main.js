/*
 * 入口
 *
 * 默认模板里有一段 `#ifndef VUE3` 的 Vue 2 分支（配 uni.promisify.adaptor）。
 * 本项目 manifest 里 vueVersion 固定是 3，那段永远不会被编译进去，
 * 留着只会让人以为还要考虑 Vue 2 的兼容——连同它引的 adaptor 一起删了。
 */
import { createSSRApp } from 'vue'
import App from './App'

export function createApp() {
	const app = createSSRApp(App)
	return {
		app
	}
}
