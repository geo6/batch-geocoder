require('./fontawesome');

window.app = {};
window.app.fn = {
  geocodeProcess: require('./geocodeProcess')
};
window.app.geocode = {
  count: 0,
  countAlreadyGeocode: 0,
  launch: false,
  process: {
    count: 0,
    countSingle: 0,
    countMultiple: 0,
    countNoResult: 0
  },
  total: 0
};

$(document).ready(function () {
  if (window.app.geocode.launch === true) {
    $('#btn-geocode-launch, #btn-geocode-reset').addClass('disabled');

    console.log(window.app.geocode.count + ' record(s) to geocode!');

    window.app.fn.geocodeProcess();
  }
});
