import { defineConfig } from "vitest/config";

export default defineConfig({
  test: {
    environment: "node",
    include: ["tests/pilot/**/*.test.ts"],
    // tests/pilot/spatial-ui.test.ts reads the stylesheets as text (?raw);
    // Vitest stubs CSS to an empty string unless it is included here
    css: { include: [/\.css(\?|$)/] },
  },
});
