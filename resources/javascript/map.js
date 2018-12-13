import 'ol/ol.css';

import {
    Map,
    View
} from 'ol';
import {
    defaults as ControlDefaults,
    Attribution,
    ScaleLine
} from 'ol/control';
import {
    GeoJSON
} from 'ol/format';
import {
    Tile as TileLayer,
    Vector as VectorLayer
} from 'ol/layer';
import {
    OSM as OSMSource,
    Vector as VectorSource
} from 'ol/source';
import {
    ATTRIBUTION as OSMSourceAttribution
} from 'ol/source/OSM';
import {
    Circle,
    Fill,
    Stroke,
    Style
} from 'ol/style';

let colors = ['#076a6d', '#e5936e', '#3a7ce8', '#18dba7', '#dbcb3b', '#000'];
let providers = [];

export default function initMap(geojson) {
    let source = new VectorSource({
        features: (new GeoJSON({
            featureProjection: 'EPSG:3857'
        })).readFeatures(geojson)
    });

    window.app.map = new Map({
        controls: ControlDefaults({attribution: false}).extend([new Attribution({collapsible: false}), new ScaleLine()]),
        layers: [
            new TileLayer({
                source: new OSMSource({
                    attributions: [OSMSourceAttribution, 'Tiles courtesy of <a href="https://geo6.be/" target="_blank">GEO-6</a>'],
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
                        $('#legend').append('<strong style="color: ' + providers[properties.provider] + '">' +  properties.provider + '</strong>, ');
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

    window.app.map.getView().fit(source.getExtent(), {
        maxZoom: 18
    });

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
