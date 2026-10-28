// Probe: is the launcher button present?
// Evaluated in page context via opencli browser eval; returns a marker the
// shell greps for. No DOM writes.
(function () {
  var el = document.querySelector('.mdvl-launcher');
  return el ? 'MDV_LAUNCHER_OK' : 'MDV_LAUNCHER_MISSING';
})();
