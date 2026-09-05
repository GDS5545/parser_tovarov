# Universal Woo Scraper worker (Stage 3 + Stage 16 packaging).
#
# Uses Microsoft's official Playwright image, which ships Chromium/Firefox/
# WebKit already matched to a specific playwright npm version — the image
# tag below MUST match the "playwright" version pinned in package.json
# exactly (that pin is intentional: an unpinned range risks the installed
# npm package drifting out of sync with the browser binaries baked into
# this image, which is a common source of "Executable doesn't exist" errors).
FROM mcr.microsoft.com/playwright:v1.63.0-jammy

WORKDIR /app

# Install dependencies first so they're cached separately from source changes.
COPY package.json package-lock.json* ./
RUN npm ci --omit=dev

COPY src ./src

ENV NODE_ENV=production
# WORKER_API_KEY has no default on purpose — server.js refuses every
# request rather than running open if it isn't set (see src/server.js).
ENV PORT=4790

EXPOSE 4790

CMD ["node", "src/server.js"]
