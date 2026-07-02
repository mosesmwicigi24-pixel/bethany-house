# react-admin/Dockerfile

# ─── Builder: compile Vite bundle ────────────────────────────────────────────
FROM node:22-alpine AS builder

WORKDIR /app

COPY package.json package-lock.json* ./
RUN npm ci --frozen-lockfile

COPY . .

# Injected by CI via --build-arg.
# VITE_BASE_PATH must match the sub-path Nginx proxies to this container.
# Production: /admin/   Development: /
ARG VITE_API_URL
ARG VITE_BASE_PATH=/
ARG VITE_REVERB_APP_KEY
ARG VITE_REVERB_HOST
ARG VITE_REVERB_PORT
ARG VITE_REVERB_SCHEME

ENV VITE_API_URL=$VITE_API_URL
ENV VITE_BASE_PATH=$VITE_BASE_PATH
ENV VITE_REVERB_APP_KEY=$VITE_REVERB_APP_KEY
ENV VITE_REVERB_HOST=$VITE_REVERB_HOST
ENV VITE_REVERB_PORT=$VITE_REVERB_PORT
ENV VITE_REVERB_SCHEME=$VITE_REVERB_SCHEME

RUN npm run build

# ─── Production: serve dist via Nginx ────────────────────────────────────────
FROM nginx:1.27-alpine AS production

RUN rm -rf /usr/share/nginx/html/*

COPY --from=builder /app/dist /usr/share/nginx/html

# Handles /admin/* → /* rewriting and SPA fallback
COPY nginx.conf /etc/nginx/conf.d/default.conf

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]

# ─── Development: Vite dev server with HMR ───────────────────────────────────
FROM node:22-alpine AS development

WORKDIR /app

COPY package.json package-lock.json* ./
RUN npm ci --frozen-lockfile

COPY . .

EXPOSE 5173

# docker-compose.dev.yml bind-mounts ./react-admin:/app for hot reload.
# node_modules excluded via anonymous volume in the compose file.
CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0"]