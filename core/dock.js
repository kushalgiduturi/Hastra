// core/dock.js — fluid magnification effect for .astra-dock (vanilla JS
// equivalent of a macOS-style dock: items grow as the mouse gets closer).
(function () {
  var BASE = 44;
  var MAX = 64;
  var ICON_BASE = 20;
  var ICON_MAX = 28;
  var DISTANCE = 140;

  function initDock(dock) {
    var items = Array.prototype.slice.call(dock.querySelectorAll('.astra-dock-item'));

    function apply(mouseX) {
      items.forEach(function (el) {
        var rect = el.getBoundingClientRect();
        var center = rect.left + rect.width / 2;
        var dist = Math.abs(mouseX - center);
        var scale = Math.max(0, 1 - dist / DISTANCE);
        var size = BASE + (MAX - BASE) * scale;
        var iconSize = ICON_BASE + (ICON_MAX - ICON_BASE) * scale;
        el.style.width = size + 'px';
        el.style.height = size + 'px';
        var svg = el.querySelector('svg');
        if (svg) {
          svg.style.width = iconSize + 'px';
          svg.style.height = iconSize + 'px';
        }
      });
    }

    function reset() {
      items.forEach(function (el) {
        el.style.width = '';
        el.style.height = '';
        var svg = el.querySelector('svg');
        if (svg) {
          svg.style.width = '';
          svg.style.height = '';
        }
      });
    }

    dock.addEventListener('mousemove', function (e) {
      apply(e.clientX);
    });
    dock.addEventListener('mouseleave', reset);
  }

  function boot() {
    var docks = document.querySelectorAll('.astra-dock');
    for (var i = 0; i < docks.length; i++) initDock(docks[i]);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
