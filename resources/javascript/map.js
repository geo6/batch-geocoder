/*global $*/

import 'ol/ol.css';

import Map from 'ol/map';
import Control from 'ol/control';
import Attribution from 'ol/control/attribution';
import ScaleLine from 'ol/control/scaleline';
import Feature from 'ol/feature';
import Point from 'ol/geom/point';
import TileLayer from 'ol/layer/tile';
import VectorLayer from 'ol/layer/vector';
import Proj from 'ol/proj';
import OSMSource from 'ol/source/osm';
import VectorSource from 'ol/source/vector';
import Circle from 'ol/style/circle';
import Fill from 'ol/style/fill';
import Stroke from 'ol/style/stroke';
import Style from 'ol/style/style';
import View from 'ol/view';

let colors = ['#076a6d', '#e5936e', '#3a7ce8', '#18dba7', '#dbcb3b'];

export default function initMap() {
    $('#results > div').each(function(index) {
        $(this).data('color', colors[index]);
        $(this).find('h2 > svg').css('color', colors[index]);
    });

    let source = new VectorSource();

    $('#results > div > ul > li > button').each(function() {
        let coordinates = [
            $(this).data('longitude'),
            $(this).data('latitude')
        ];
        let color = $(this).closest('div').data('color');

        source.addFeature(new Feature({
            geometry: new Point(Proj.fromLonLat(coordinates)),
            properties: {
                color: color
            }
        }));

        $(this).on('click', function() {
            window.app.map.getView().animate({
                zoom: 17,
                center: Proj.fromLonLat(coordinates)
            });
        });
    });

    window.app.map = new Map({
        controls: Control.defaults({attribution: false}).extend([new Attribution({collapsible: false}), new ScaleLine()]),
        layers: [
            new TileLayer({
                source: new OSMSource({
                    attributions: [OSMSource.ATTRIBUTION, 'Tiles courtesy of <a href="https://geo6.be/" target="_blank">GEO-6</a>'],
                    url: 'https://tile.geo6.be/osmbe/{z}/{x}/{y}.png'
                })
            }),
            new VectorLayer({
                source: source,
                style: function(feature) {
                    let fill = new Fill({
                        color: feature.getProperties().properties.color
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
}
