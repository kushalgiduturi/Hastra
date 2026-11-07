import { fileURLToPath, URL } from "node:url";
import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";

export default defineConfig({
  // Tailwind v4 compiles src/styles/spatial.css (the spatial tokens and the
  // Watermelon UI colour names); "@/" is the shadcn-style import root the
  // vendored Watermelon components use.
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: { "@": fileURLToPath(new URL("./src", import.meta.url)) },
  },
  server: {
    proxy: {
      // `wrangler pages dev` serves /api during local dev; see README.
      "/api": "http://127.0.0.1:8788",
    },
  },
});
