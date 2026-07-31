FROM node:22-bookworm-slim AS build
ARG APP_NAME
WORKDIR /app
COPY package.json ./
COPY apps/${APP_NAME}/package.json apps/${APP_NAME}/package.json
RUN npm install --workspace apps/${APP_NAME}
COPY apps/${APP_NAME} apps/${APP_NAME}
RUN npm run --workspace apps/${APP_NAME} build
FROM node:22-bookworm-slim
WORKDIR /app
COPY --from=build /app/node_modules node_modules
COPY --from=build /app/apps/discord-manager/dist dist
USER node
CMD ["node", "dist/index.js"]
