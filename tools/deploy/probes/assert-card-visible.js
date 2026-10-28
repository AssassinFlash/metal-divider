// Probe: is the widget card visible (open)?
(function () {
  var c = document.querySelector('.mdvl-card');
  if (!c || c.hidden) { return 'MDV_CARD_HIDDEN'; }
  var r = c.getBoundingClientRect();
  return (r.width > 0 && r.height > 0) ? 'MDV_CARD_VISIBLE' : 'MDV_CARD_HIDDEN';
})();
