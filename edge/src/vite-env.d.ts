/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_RECAPTCHA_SITE_KEY: string;
}
interface ImportMeta {
  readonly env: ImportMetaEnv;
}

interface Window {
  grecaptcha?: {
    render: (
      container: HTMLElement,
      params: { sitekey: string; theme?: "light" | "dark" },
    ) => number;
    getResponse: (widgetId: number) => string;
    reset: (widgetId: number) => void;
  };
  onRecaptchaApiLoad?: () => void;
}
