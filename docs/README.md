# Prism documentation

The Markdown in this directory ships with `particle-academy/prism`. The VitePress configuration in `.vitepress/config.mts` defines its navigation. Follow the [documentation style guide](./documentation-style-guide.md) when editing pages.

## Local preview

Run these commands from `docs/` with Node.js and npm installed:

```shell
npm ci
npm run docs:dev
```

## Build

```shell
npm run docs:build
npm run docs:preview
```

The build writes `.vitepress/dist/`. The preview command serves that output locally.

## Adding a provider guide

1. Create `providers/<provider>.md` using `providers/providers.md.template`; remove unused sections.
2. Verify API names, options, errors and examples against the provider implementation.
3. Add the provider to [Introduction](./getting-started/introduction.md) and the sidebar in `.vitepress/config.mts`.
4. Keep provider lists in alphabetical order and update capability tables in `components/` when support changes.
5. Check PHP examples and build the documentation.
