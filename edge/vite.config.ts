import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

export default defineConfig({
  plugins: [react()],
  server: {
    proxy: {
      // `wrangler pages dev` serves /api during local dev; see README.
      "/api": "http://127.0.0.1:8788",
    },
  },
});
