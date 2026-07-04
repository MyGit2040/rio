FROM alkatraz79/open-wa-ready:latest

WORKDIR /app
COPY package*.json ./
RUN npm install --only=production
COPY . .

EXPOSE 3000
CMD ["node", "server.js"]
