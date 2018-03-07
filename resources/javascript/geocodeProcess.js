export default function geocodeProcess() {
  fetch("./geocode/process", {
    cache: "no-cache",
    credentials: "same-origin",
    mode: "same-origin"
  }).then(function(response) {
    return response.json();
  }).then(function(json) {
    if (json !== null) {
      window.app.geocode.process.count += json.count;
      window.app.geocode.process.countSingle += json.countSingle;
      window.app.geocode.process.countMultiple += json.countMultiple;
      window.app.geocode.process.countNoResult += json.countNoResult;

      $("#progress-count").text(window.app.geocode.process.count);
      $("#progress-single-count").text(window.app.geocode.process.countSingle);
      $("#progress-multiple-count").text(window.app.geocode.process.countMultiple);
      $("#progress-noresult-count").text(window.app.geocode.process.countNoResult);

      let pctCount = Math.round(window.app.geocode.process.count / window.app.geocode.count * 100);
      $("#progress > .progress-bar").
        css("width", pctCount + "%").
        attr("aria-valuenow", pctCount).
        text(pctCount + "%");

      let pctSingle = Math.round(window.app.geocode.process.countSingle / window.app.geocode.count * 100);
      $("#progress-single > .progress-bar").
        css("width", pctSingle + "%").
        attr("aria-valuenow", pctSingle).
        text(pctSingle + "%");

      let pctMultiple = Math.round(window.app.geocode.process.countMultiple / window.app.geocode.count * 100);
      $("#progress-multiple > .progress-bar").
        css("width", pctMultiple + "%").
        attr("aria-valuenow", pctMultiple).
        text(pctMultiple + "%");

      let pctNoResult = Math.round(window.app.geocode.process.countNoResult / window.app.geocode.count * 100);
      $("#progress-noresult > .progress-bar").
        css("width", pctNoResult + "%").
        attr("aria-valuenow", pctNoResult).
        text(pctNoResult + "%");

      window.app.fn.geocodeProcess();
    } else {
      $(".fa-cog.fa-spin").remove();
      $("#btn-geocode-next").removeClass("disabled");

      let countGeocoded = window.app.geocode.countAlreadyGeocoded + window.app.geocode.process.countSingle;
      let countNotGeocoded = window.app.geocode.process.countMultiple + window.app.geocode.process.countNoResult;

      let textGeocoded = $("#count-geocoded").text();
      $("#count-geocoded").text(textGeocoded.replace(/^\d+/, countGeocoded));

      let textNotGeocoded = $("#count-notgeocoded").text();
      $("#count-notgeocoded").text(textNotGeocoded.replace(/^\d+/, countNotGeocoded));

      let pctCountGeocoded = Math.round(countGeocoded / window.app.geocode.total * 100);
      $("#progress-geocoded").
        css("width", pctCountGeocoded + "%").
        attr("aria-valuenow", pctCountGeocoded).
        text(pctCountGeocoded + "%");

      let pctCountNotGeocoded = Math.round(countNotGeocoded / window.app.geocode.total * 100);
      $("#progress-notgeocoded").
        css("width", pctCountNotGeocoded + "%").
        attr("aria-valuenow", pctCountNotGeocoded).
        text(pctCountNotGeocoded + "%");

      $("#btn-geocode-reset").removeClass("disabled");
      $(".progress-bar.progress-bar-striped.progress-bar-animated").removeClass("progress-bar-striped progress-bar-animated");
    }
  });
};
