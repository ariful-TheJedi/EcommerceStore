Frontend Folder Structure
=========================

ui/
|-- app/                   Home, products, categories, search, cart and checkout
|   |-- components/        Shared components
|   |-- config/            Site settings and navigation
|   |-- lib/               Shared logic and cart state
|   |-- layout.tsx         Root layout
|   |-- globals.css        Global styles
|   |-- error.tsx          Error boundary
|   |-- not-found.tsx      Fallback page
|   |-- robots.ts
|   `-- sitemap.ts
|-- package.json
|-- next.config.ts
|-- postcss.config.mjs
`-- tsconfig.json

Commands
--------
npm install
npm run dev
npm run typecheck
npm run build
npm start

All application code lives inside app/. The @/ import alias points there.
Keep page-specific code beside its route, reused components in components/,
and shared logic in lib/. Create folders only when they have working code.
