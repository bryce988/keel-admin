# 前端生产镜像：构建静态文件 → 交给 nginx 托管
# 构建上下文为仓库根目录：docker build -f docker/web/Dockerfile .

FROM node:20-alpine AS build
WORKDIR /app

# 先装依赖，利用层缓存：只改业务代码时不必重新装包
COPY web/package.json ./
RUN npm install --no-audit --no-fund

COPY web/ ./
RUN npm run build

FROM nginx:alpine
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html
EXPOSE 80
