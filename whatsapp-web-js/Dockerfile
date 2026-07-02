# whatsapp-web.js bridge — Chromium-capable Node image.
FROM node:20-slim

# Chromium runtime libraries Puppeteer needs (headless rendering).
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates fonts-liberation wget gnupg \
    libasound2 libatk-bridge2.0-0 libatk1.0-0 libcairo2 libcups2 libdbus-1-3 \
    libexpat1 libfontconfig1 libgbm1 libglib2.0-0 libgtk-3-0 libnspr4 libnss3 \
    libpango-1.0-0 libpangocairo-1.0-0 libx11-6 libx11-xcb1 libxcb1 libxcomposite1 \
    libxcursor1 libxdamage1 libxext6 libxfixes3 libxi6 libxrandr2 libxrender1 \
    libxss1 libxtst6 lsb-release xdg-utils \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY package.json package-lock.json* ./
RUN npm install --omit=dev

COPY . .

# Sessions persist here (mount a volume).
ENV SESSION_PATH=/app/.wwebjs_auth
ENV PORT=3000
EXPOSE 3000

CMD ["node", "server.js"]
