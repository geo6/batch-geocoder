/*global $*/

import 'ol/ol.css';

import Map from 'ol/map';
import Control from 'ol/control';
import Attribution from 'ol/control/attribution';
import ScaleLine from 'ol/control/scaleline';
import GeoJSON from 'ol/format/geojson';
import TileLayer from 'ol/layer/tile';
import VectorLayer from 'ol/layer/vector';
import OSMSource from 'ol/source/osm';
import VectorSource from 'ol/source/vector';
import Circle from 'ol/style/circle';
import Fill from 'ol/style/fill';
import Stroke from 'ol/style/stroke';
import Style from 'ol/style/style';
import View from 'ol/view';

let colors = ['#076a6d', '#e5936e', '#3a7ce8', '#18dba7', '#dbcb3b'];
let providers = [];

export default function initMap(geojson) {
    let source = new VectorSource({
        features: (new GeoJSON({
            featureProjection: 'EPSG:3857'
        })).readFeatures(geojson)
    });

    window.app.map = new Map({
        controls: Control.defaults({attribution: false}).extend([new Attribution({collapsible: false}), new ScaleLine()]),
        layers: [
            new TileLayer({
                source: new OSMSource({
                    attributions: [OSMSource.ATTRIBUTION, 'Tiles courtesy of <a href="https://geo6.be/" target="_blank">GEO-6</a>'],
                    url: 'https://tile.geo6.be/osmbe/{z}/{x}/{y}.png',
                    maxZoom: 18
                })
            }),
            new VectorLayer({
                source: source,
                style: function (feature) {
                    let properties = feature.getProperties();

                    if (typeof providers[properties.provider] === 'undefined') {
                        providers[properties.provider] = colors.shift();
                    }

                    let fill = new Fill({
                        color: providers[properties.provider]
                    });
                    let stroke = new Stroke({
                        color: '#FFF',
                        width: 2
                    });
                    return [
                        new Style({
                            image: new Circle({
                                fill: fill,
                                stroke: stroke,
                                radius: 5
                            }),
                            fill: fill,
                            stroke: stroke
                        })
                    ];
                }
            })
        ],
        target: 'map',
        view: new View({
            center: [0, 0],
            zoom: 2
        })
    });

    window.app.map.getView().fit(source.getExtent());

    window.app.map.on('singleclick', function (event) {
        let features = this.getFeaturesAtPixel(event.pixel);

        if (features !== null && features.length > 0) {
            let properties = features[0].getProperties();

            $('#address').text(properties.address).show();
        } else {
            $('#address').empty().hide();
        }
    });
}
