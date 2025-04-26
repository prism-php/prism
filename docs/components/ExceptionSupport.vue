<template>
  <div>
    <table>
      <thead>
        <tr>
          <th scope="col">Provider</th>
          <th v-for="exception in exceptions" :key="exception" scope="col">
            {{ exception }}
          </th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="provider in providers" :key="provider.name">
          <th scope="row">{{ provider.name }}</th>
          <td v-for="exception in exceptions" :key="exception">
            <div class="flex justify-center">
              <svg
                v-if="provider[camelCase(exception)] === 'supported'"
                class="w-6 h-6"
                viewBox="0 0 24 24"
                fill="none"
                xmlns="http://www.w3.org/2000/svg"
              >
                <path
                  d="M20 6L9 17L4 12"
                  stroke="green"
                  stroke-width="2"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
              </svg>
              <svg
                v-else
                class="w-6 h-6"
                viewBox="0 0 24 24"
                fill="none"
                xmlns="http://www.w3.org/2000/svg"
              >
                <path
                  d="M18 6L6 18M6 6l12 12"
                  stroke="red"
                  stroke-width="2"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
              </svg>
            </div>
          </td>
        </tr>
      </tbody>
    </table>

    <div class="flex gap-4 mt-4">
      <div class="flex items-center gap-2">
        <svg
          class="w-5 h-5"
          viewBox="0 0 24 24"
          fill="none"
          xmlns="http://www.w3.org/2000/svg"
        >
          <path
            d="M20 6L9 17L4 12"
            stroke="green"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
        </svg>
        <span>Supported</span>
      </div>
      <div class="flex items-center gap-2">
        <svg
          class="w-5 h-5"
          viewBox="0 0 24 24"
          fill="none"
          xmlns="http://www.w3.org/2000/svg"
        >
          <path
            d="M18 6L6 18M6 6l12 12"
            stroke="red"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
        </svg>
        <span>Unsupported</span>
      </div>
    </div>
  </div>
</template>

<script>
const Supported = "supported";
const Unsupported = "unsupported";

export default {
  name: "ExceptionMatrix",
  data() {
    return {
      exceptions: ["Rate Limited", "Overloaded", "Too Large"],
      providers: [
        {
          name: "Amazon Bedrock",
          rateLimited: Unsupported,
          overloaded: Unsupported,
          tooLarge: Unsupported,
        },
        {
          name: "Anthropic",
          rateLimited: Supported,
          overloaded: Supported,
          tooLarge: Supported,
        },
        {
          name: "Azure OpenAI",
          rateLimited: Unsupported,
          overloaded: Unsupported,
          tooLarge: Unsupported,
        },
        {
          name: "DeepSeek",
          rateLimited: Unsupported,
          overloaded: Unsupported,
          tooLarge: Unsupported,
        },
        {
          name: "Gemini",
          rateLimited: Supported,
          overloaded: Unsupported,
          tooLarge: Unsupported,
        },
        {
          name: "Groq",
          rateLimited: Supported,
          overloaded: Unsupported,
          tooLarge: Unsupported,
        },
        {
          name: "Mistral",
          rateLimited: Supported,
          overloaded: Unsupported,
          tooLarge: Unsupported,
        },
        {
          name: "Ollama",
          rateLimited: Unsupported,
          overloaded: Unsupported,
          tooLarge: Unsupported,
        },
        {
          name: "OpenAI",
          rateLimited: Supported,
          overloaded: Unsupported,
          tooLarge: Unsupported,
        },
        {
          name: "Voyage AI",
          rateLimited: Supported,
          overloaded: Unsupported,
          tooLarge: Unsupported,
        },
        {
          name: "xAI",
          rateLimited: Supported,
          overloaded: Unsupported,
          tooLarge: Unsupported,
        },        
      ],
    };
  },
  methods: {
    camelCase: (str) => {
      return str.replace(/(?:^\w|[A-Z]|\b\w)/g, function (word, index) {
        return index == 0 ? word.toLowerCase() : word.toUpperCase();
      }).replace(/\s+/g, '')
    }
  }
};
</script>
