# Eagleto Baileys Gateway — container image
#
# Four stages so the runtime image carries only what it needs to run:
#   deps      — every dependency, including dev, for the build
#   build     — prisma generate + tsc
#   prod-deps — a clean production-only dependency tree
#   runtime   — dist + production deps + the generated Prisma client
#
# The generated Prisma client is produced in `build` (which has the prisma CLI,
# a devDependency) and copied into `runtime` on top of the production tree. That
# avoids shipping the CLI while still shipping a working client.

# ---------------------------------------------------------------------------
# Stage 1 — dependencies (including dev)
# ---------------------------------------------------------------------------
FROM node:22-alpine AS deps

WORKDIR /app

# Only the manifests, so this layer is reused whenever source changes but
# dependencies do not.
COPY package.json package-lock.json ./

# npm ci, never npm install: it installs exactly the locked tree and fails if
# the lockfile and manifest disagree, which is what makes a build reproducible.
RUN npm ci

# ---------------------------------------------------------------------------
# Stage 2 — build
# ---------------------------------------------------------------------------
FROM node:22-alpine AS build

WORKDIR /app

COPY --from=deps /app/node_modules ./node_modules
COPY package.json package-lock.json tsconfig.json ./
COPY prisma ./prisma
COPY src ./src

# The Prisma client is generated from the schema, not shipped in npm. Without
# this the runtime would fail on its first query with "did you run prisma
# generate?".
RUN npx prisma generate

RUN npm run build

# ---------------------------------------------------------------------------
# Stage 3 — production dependencies only
# ---------------------------------------------------------------------------
FROM node:22-alpine AS prod-deps

WORKDIR /app

COPY package.json package-lock.json ./

RUN npm ci --omit=dev

# ---------------------------------------------------------------------------
# Stage 4 — runtime
# ---------------------------------------------------------------------------
FROM node:22-alpine AS runtime

# tini becomes PID 1 and forwards SIGTERM to the Node process. Without an init,
# Node runs as PID 1, where the default signal handlers do not apply the way
# they do for a normal process — graceful shutdown (finishing the current
# webhook pass, closing sockets, disconnecting Prisma) depends on the signal
# actually arriving.
RUN apk add --no-cache tini

ENV NODE_ENV=production
ENV PORT=3090

WORKDIR /app

# Production dependency tree first...
COPY --from=prod-deps /app/node_modules ./node_modules

# ...then the generated Prisma client over the top. Both paths are needed:
# @prisma/client is the package, .prisma/client is the schema-specific engine
# and types that `prisma generate` writes.
COPY --from=build /app/node_modules/.prisma ./node_modules/.prisma
COPY --from=build /app/node_modules/@prisma/client ./node_modules/@prisma/client

COPY --from=build /app/dist ./dist

# The schema ships as the reference for anything run inside the container.
# Migrations are NOT applied from this image: it has production dependencies
# only and therefore no Prisma CLI. Use the `migrate` service in
# docker-compose.yml, which reuses the `build` stage above.
COPY prisma ./prisma
COPY package.json ./

# Media lives on a volume. It is created and owned here so the non-root user can
# write to it even when the volume is empty on first boot.
# Keep this in step with MEDIA_STORAGE_PATH in .env.
RUN mkdir -p /var/lib/eagleto-gateway/media \
    && chown -R node:node /var/lib/eagleto-gateway /app

# Never root. A compromised process should not be able to write outside its own
# directories, and nothing this service does needs privilege.
USER node

EXPOSE 3090

# /health/live is unauthenticated by design (see AUTH_EXEMPT_PATHS in
# src/api/middleware/api-auth.ts), so the probe needs no credentials. Node 22
# has a global fetch, so no extra package is required for this.
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
  CMD node -e "fetch('http://127.0.0.1:'+(process.env.PORT||3090)+'/health/live').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"

ENTRYPOINT ["/sbin/tini", "--"]

CMD ["node", "dist/server.js"]
