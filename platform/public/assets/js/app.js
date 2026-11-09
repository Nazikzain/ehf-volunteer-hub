// Progressive enhancement only; every feature works without JavaScript.
document.addEventListener('DOMContentLoaded', function () {
  // Confirm dangerous actions (server still enforces the rules).
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (ev) { if (!window.confirm(f.getAttribute('data-confirm'))) ev.preventDefault(); });
  });
  // Show the day checkboxes only when "Specific days" is selected.
  var av = document.getElementById('availability_type'), days = document.getElementById('days-field');
  if (av && days) { var t = function () { days.hidden = av.value !== 'specific_days'; }; av.addEventListener('change', t); t(); }
});
