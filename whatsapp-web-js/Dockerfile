# whatsapp-web.js bridge — Chromium-capable Node image.
#
# Instead of hand-listing Chromium's shared libraries (brittle across Debian
# releases), install Debian's own `chromium` package — apt resolves every runtime
# dependency automatically — and point Puppeteer at it, skipping its bundled
# Chromium download.
FROM node:20-slim

RUN apt-get update && apt-get install -y --no-install-recommends \
    chromium fonts-liberation ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# Use the system Chromium; don't download Puppeteer's own copy.
ENV PUPPETEER_SKIP_DOWNLOAD=true
ENV PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium

WORKDIR /app

COPY package.json package-lock.json* ./
RUN npm install --omit=dev

COPY . .

# Sessions persist here (mount a volume).
ENV SESSION_PATH=/app/.wwebjs_auth
ENV PORT=3000
EXPOSE 3000

CMD ["node", "server.js"]
