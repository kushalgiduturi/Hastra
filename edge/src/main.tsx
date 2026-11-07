import React from "react";
import ReactDOM from "react-dom/client";
import { BrowserRouter } from "react-router-dom";
import App from "./App";
import { applyTheme, readTheme } from "./lib/theme";
import "./styles/spatial.css";

// theme before the first render, so the first paint is already themed
applyTheme(readTheme());

ReactDOM.createRoot(document.getElementById("root")!).render(
  <React.StrictMode>
    <BrowserRouter>
      <App />
    </BrowserRouter>
  </React.StrictMode>,
);
