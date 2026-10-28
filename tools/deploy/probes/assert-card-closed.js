// Probe: is the widget card closed?
(function () {
  var c = document.querySelector('.mdvl-card');
  return (!c || c.hidden === true) ? 'MDV_CARD_CLOSED' : 'MDV_CARD_STILL_OPEN';
})();
