# 前端生产镜像：构建静态文件 → 交给 nginx 托管
# 构建上下文为仓库根目录：docker build -f docker/web/Dockerfile .

FROM node:20-alpine AS build
WORKDIR /app

# 先装依赖，利用层缓存：只改业务代码时不必重新装包
#
# 必须连 package-lock.json 一起拷 + 用 `npm ci`：
# 只拷 package.json 跑 `npm install` 等于每次构建都重新解析版本范围，
# 线上装到的依赖树可能和本地锁文件里的不是同一套——
# 「本地好好的，线上构建出来的包行为不一样」就是这么来的。
# npm ci 还会在 lock 与 package.json 不一致时直接失败，而不是悄悄改写锁文件。
COPY web/package.json web/package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY web/ ./
RUN npm run build

FROM nginx:alpine
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html
EXPOSE 80
