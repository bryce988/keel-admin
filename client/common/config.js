/**
 * 后端地址与渠道标识
 *
 * BASE_URL 固定指向线上预览环境，不跟着本地环境切换。
 * 这样真机、模拟器、同事的机器上都是同一个后端，不会出现「我这能登你那不能」——
 * 而写 localhost 在真机上必然失败：那是手机自己的回环地址，
 * 手机上没有跑我们的服务。要连本地后端时临时改成开发机的局域网 IP
 * （如 http://192.168.1.8:8787），改完记得别提交。
 *
 * 三个渠道头是后端的硬性要求（ChannelMiddleware）：缺一个就是 400。
 * 灰度、强制更新、风控、埋点都要用它们，所以在入口就拦。
 * 打包 iOS 时 CHANNEL 要改成 app-ios——后端只认登记过的四个渠道。
 */
export const BASE_URL = 'http://43.143.249.52:8080'

export const CHANNEL = 'app-android'

export const APP_VERSION = '1.0.0'
