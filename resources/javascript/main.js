import initMap from './map';
import initMapChoose from './map-choose';
import geocodeProcess from './geocodeProcess';

window.app = {};
window.app.fn = {
    geocodeProcess: geocodeProcess,
    initMap: initMap,
    initMapChoose: initMapChoose
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

        window.app.fn.geocodeProcess();
    }

    $('.btn-skip-validation').on('click', function () {
        $(this).closest('tr').find('select').prop('disabled', true);
    });
});
