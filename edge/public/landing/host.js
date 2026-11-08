// Theme for the landing host page (public/landing/index.html). The same key
// and event as the PHP app's core/theme.js and the edge app's
// src/lib/theme.ts, so the hero and the sign-in screens agree. The framed
// document calls window.parent.toggleTheme(); landing-host.js watches
// data-theme on this root and repaints the scene inside the frame.
(function () {
  "use strict";
  var KEY = "astra_theme";
  var root = document.documentElement;

  function read() {
    try {
      var saved = localStorage.getItem(KEY);
      if (saved === "dark" || saved === "light") return saved;
    } catch (e) {
      /* storage blocked: fall through to the system preference */
    }
    return window.matchMedia && window.matchMedia("(prefers-color-scheme: light)").matches ? "light" : "dark";
  }

  function apply(theme) {
    root.setAttribute("data-theme", theme);
    root.style.colorScheme = theme;
  }

  apply(read());

  window.toggleTheme = function () {
    var next = root.getAttribute("data-theme") === "light" ? "dark" : "light";
    apply(next);
    try {
      localStorage.setItem(KEY, next);
    } catch (e) {
      /* the choice still applies for this visit */
    }
    window.dispatchEvent(new CustomEvent("hastra:themechange", { detail: next }));
  };
})();
