<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit(); }
// core/gooey_search.php
// Renders the vanilla-JS/CSS "Gooey Search" component (assets/css/
// gooey-search.css, assets/js/gooey-search.js) — a small search field,
// sized to match this app's other filter inputs, that widens with a spring
// transition on click/focus. Ported from a React/Framer-Motion spec to
// plain markup so it works in Astra's procedural PHP views with no build
// step; simplified from the original spec's SVG blur-filter/floating-bubble
// treatment, which made the control taller and rounder than everything
// next to it and read as a separate stray element rather than part of the
// filter bar it sits in.
//
// Usage from a page that already has a plain <input> driving a live filter:
//   call render_gooey_search('f_search', 'Search project / client…', 'filterReqs()')
// in place of:
//   <input type="text" id="f_search" placeholder="Search project / client…" oninput="filterReqs()">
//
// The rendered <input> keeps the same id and oninput attribute, so it's a
// drop-in replacement — any existing JS that reads document.getElementById(id)
// or relies on the oninput handler firing on a real `input` event keeps
// working unchanged; nothing needs to synthesize or proxy events between a
// decorative wrapper and a hidden original.

// $id/$placeholder/$on_input/$value are all rendered into HTML attributes —
// callers pass literal strings (ids, static placeholder copy, a fixed JS
// function-call string), never raw request input, but everything is escaped
// regardless since that's cheap and this only ever needs to be safe once.
function render_gooey_search(string $id, string $placeholder, string $on_input, string $value = '') {
    $id_attr          = htmlspecialchars($id, ENT_QUOTES);
    $placeholder_attr = htmlspecialchars($placeholder, ENT_QUOTES);
    $on_input_attr    = htmlspecialchars($on_input, ENT_QUOTES);
    $value_attr       = htmlspecialchars($value, ENT_QUOTES);
    ?>
    <div class="gooey-search-root">
      <div class="gooey-button-row">
        <div class="gooey-trigger">
          <button type="button" class="gooey-icon-btn" aria-label="<?= $placeholder_attr ?>" tabindex="-1">
            <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
          </button>
          <input type="search" class="gooey-input" id="<?= $id_attr ?>" placeholder="<?= $placeholder_attr ?>"
                 aria-label="<?= $placeholder_attr ?>" value="<?= $value_attr ?>" autocomplete="off" oninput="<?= $on_input_attr ?>">
        </div>
      </div>
    </div>
    <?php
}
