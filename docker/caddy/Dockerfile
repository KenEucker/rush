# syntax=docker/dockerfile:1.7

FROM node:24.16.0-alpine3.22 AS client-build

WORKDIR /workspace/client

COPY client/package.json client/package-lock.json ./
COPY client/src-pwa/package.json client/src-pwa/package-lock.json ./src-pwa/

RUN npm ci --ignore-scripts \
    && npm --prefix src-pwa ci --ignore-scripts

COPY client ./
COPY docs/contracts /workspace/docs/contracts

RUN npm run postinstall \
    && npm run build:pwa

FROM caddy:2.8.4-alpine AS runtime

COPY docker/caddy/Caddyfile /etc/caddy/Caddyfile
COPY --from=client-build /workspace/client/dist/pwa /srv/client
COPY server/public /var/www/html/public
