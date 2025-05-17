import { defineConfig } from "vitepress";
import llmstxt from "vitepress-plugin-llms";

// https://vitepress.dev/reference/site-config
export default defineConfig({
  title: "Prism",
  head: [
    [
      "script",
      {
        src: "https://cdn.tailwindcss.com",
      },
    ],
    [
      "script",
      {
        defer: "",
        src: "https://analytics.echolabs.dev/script.js",
        "data-website-id": "38989bda-90b5-47af-81ab-57a823480b9e",
      },
    ],
    // OpenGraph / Facebook
    ["meta", { property: "og:type", content: "website" }],
    ["meta", { property: "og:url", content: "https://prismphp.com" }],
    ["meta", { property: "og:title", content: "Prism" }],
    [
      "meta",
      {
        property: "og:description",
        content:
          "Prism is a powerful Laravel package for integrating Large Language Models (LLMs) into your applications.",
      },
    ],
    [
      "meta",
      {
        property: "og:image",
        content: "https://prismphp.com/assets/og-image.png?v=3",
      },
    ],

    // Twitter
    ["meta", { name: "twitter:card", content: "summary_large_image" }],
    ["meta", { property: "twitter:domain", content: "prism.echolabs.dev" }],
    ["meta", { property: "twitter:url", content: "https://prismphp.com" }],
    ["meta", { name: "twitter:title", content: "Prism" }],
    [
      "meta",
      {
        name: "twitter:description",
        content:
          "Prism is a powerful Laravel package for integrating Large Language Models (LLMs) into your applications.",
      },
    ],
    [
      "meta",
      {
        name: "twitter:image",
        content: "https://prismphp.com/assets/og-image.png?v=3",
      },
    ],
  ],
  srcExclude: ["**/README.md", "documentation-style-guide.md"],
  description:
    "Prism is a powerful Laravel package for integrating Large Language Models (LLMs) into your applications.",
  themeConfig: {
    // https://vitepress.dev/reference/default-theme-config
    nav: [
      { text: "Home", link: "/" },
      { text: "Docs", link: "/getting-started/introduction" },
      { text: "Sponsor", link: "https://github.com/sponsors/sixlive" },
    ],

    sidebar: [
      {
        items: [
          {
            text: "Getting Started",
            items: [
              {
                text: "Introduction",
                link: "/getting-started/introduction",
              },
              {
                text: "Installation",
                link: "/getting-started/installation",
              },
              {
                text: "Configuration",
                link: "/getting-started/configuration",
              },
            ],
          },
          {
            text: "Core Concepts",
            items: [
              {
                text: "Text Generation",
                link: "/core-concepts/text-generation",
              },
              {
                text: "Streaming Output",
                link: "/core-concepts/streaming-output",
              },
              {
                text: "Tool & Function Calling",
                link: "/core-concepts/tools-function-calling",
              },
              {
                text: "Structured Output",
                link: "/core-concepts/structured-output",
              },
              {
                text: "Embeddings",
                link: "/core-concepts/embeddings",
              },
              {
                text: "Schemas",
                link: "/core-concepts/schemas",
              },
              {
                text: "Prism Server",
                link: "/core-concepts/prism-server",
              },
              {
                text: "Testing",
                link: "/core-concepts/testing",
              },
            ],
          },
          {
            text: "Input modalities",
            items: [
              {
                text: "Images",
                link: "/input-modalities/images",
              },
              {
                text: "Documents",
                link: "/input-modalities/documents",
              },
            ],
          },
          {
            text: "Providers",
            items: [
              {
                text: "Anthropic",
                link: "/providers/anthropic",
              },
              {
                text: "Bedrock",
                link: "https://github.com/prism-php/bedrock",
              },
              {
                text: "DeepSeek",
                link: "/providers/deepseek",
              },
              {
                text: "Groq",
                link: "/providers/groq",
              },
              {
                text: "Gemini",
                link: "/providers/gemini",
              },
              {
                text: "Mistral",
                link: "/providers/mistral",
              },
              {
                text: "Ollama",
                link: "/providers/ollama",
              },
              {
                text: "OpenAI",
                link: "/providers/openai",
              },
              {
                text: "Voyage AI",
                link: "/providers/voyageai",
              },
              {
                text: "XAI",
                link: "/providers/xai",
              },
            ],
          },
          {
            text: "Advanced",
            items: [
              {
                text: "Error Handling",
                link: "/advanced/error-handling",
              },
              {
                text: "Custom Providers",
                link: "/advanced/custom-providers",
              },
              {
                text: "Handling Rate Limits",
                link: "/advanced/rate-limits",
              },
              {
                text: "Provider Interoperability",
                link: "/advanced/provider-interoperability",
              },
            ],
          },
          {
            text: "Packages",
            items: [
              {
                text: "Relay",
                link: "https://github.com/prism-php/relay",
              },
              {
                text: "Bedrock",
                link: "https://github.com/prism-php/bedrock",
              },
            ],
          },
        ],
      },
    ],

    search: {
      provider: "local",
    },

    socialLinks: [
      { icon: "github", link: "https://github.com/echolabsdev/prism" },
    ],
    footer: {
      message: "Released under the MIT License.",
      copyright: "Copyright © 2024-present TJ Miller",
    },
  },
  vite: {
    plugins: [llmstxt()],
  },
});
