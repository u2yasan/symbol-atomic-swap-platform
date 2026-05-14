FROM node:22-alpine@sha256:8ea2348b068a9544dae7317b4f3aafcdc032df1647bb7d768a05a5cad1a7683f AS base

WORKDIR /app

ENV NODE_ENV=development

COPY package.json package-lock.json ./
COPY stubs ./stubs

FROM base AS development
RUN npm ci
COPY tsconfig.json ./
COPY src ./src

EXPOSE 3000

CMD ["npm", "run", "dev"]

FROM development AS build
RUN npm run build
RUN npm prune --omit=dev

FROM node:22-alpine@sha256:8ea2348b068a9544dae7317b4f3aafcdc032df1647bb7d768a05a5cad1a7683f AS production

WORKDIR /app

ENV NODE_ENV=production

COPY --from=build --chown=node:node /app/package.json /app/package-lock.json ./
COPY --from=build --chown=node:node /app/stubs ./stubs
COPY --from=build --chown=node:node /app/node_modules ./node_modules
COPY --from=build --chown=node:node /app/dist ./dist

USER node

EXPOSE 3000

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 CMD node -e "const http = require('node:http'); const port = process.env.SYMBOL_ENGINE_PORT || 3000; const req = http.get({ host: '127.0.0.1', port, path: '/health', timeout: 3000 }, (res) => process.exit(res.statusCode === 200 ? 0 : 1)); req.on('error', () => process.exit(1)); req.on('timeout', () => req.destroy());"

CMD ["node", "dist/server.js"]
