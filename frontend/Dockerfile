# frontend 镜像（docs/05 §2.2）：node 构建 dist → nginx 托管（/api 反代配置由 compose 挂载）
FROM node:20-alpine AS build

WORKDIR /app
COPY package.json package-lock.json* ./
# 国内服务器使用 npmmirror 镜像，规避 registry.npmjs.org 网络问题
RUN npm config set registry https://registry.npmmirror.com && \
    npm install --no-fund --no-audit
COPY . .
RUN npm run build

FROM nginx:1.27-alpine

COPY --from=build /app/dist /usr/share/nginx/html

EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]
