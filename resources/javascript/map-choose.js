import 'ol/ol.css';

import {
    Feature,
    Map,
    View
} from 'ol';
import {
    defaults as ControlDefaults,
    Attribution,
    ScaleLine
} from 'ol/control';
import {
    Point
} from 'ol/geom';
import {
    Tile as TileLayer,
    Vector as VectorLayer
} from 'ol/layer';
import {
    fromLonLat,
    toLonLat
} from 'ol/proj';
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
    Style,
    Text
} from 'ol/style';

let colors = ['#076a6d', '#e5936e', '#3a7ce8', '#18dba7', '#dbcb3b'];
let addressesLayer = new VectorLayer({
    source: new VectorSource(),
    style: function(feature, resolution) {
        let properties = feature.getProperties().properties;

        let fill = new Fill({
            color: properties.color
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
                stroke: stroke,
                text: new Text({
                    offsetY: 15,
                    text: (window.app.map.getView().getZoomForResolution(resolution) < 15 ? '' : properties.streetnumber.toString())
                })
            })
        ];
    }
});
let locationLayer = new VectorLayer({
    source: new VectorSource()
});

function selectAddress(provider, address, recenter) {
    let li = $('#results > div[data-provider=' + provider + '] > ul > li[data-address=' + address + ']');
    let data = $.extend($(li).data(), $(li).closest('div').data());
    let coordinates = [
        data.longitude,
        data.latitude
    ];

    locationLayer.getSource().clear();

    $('#results > div > ul > li.text-primary').removeClass('text-primary');
    $(li).addClass('text-primary');

    $('#selection').text($(li).text());
    $('#btn-save').removeClass('disabled').
        attr('href', '?id=' + $('#btn-save').data('id') + '&provider=' + data.provider + '&address=' + data.address);

    if (recenter === true) {
        window.app.map.getView().animate({
            zoom: 18,
            center: fromLonLat(coordinates)
        });
    }
}

export default function initMapChoose() {
    $('#results > div').each(function(index) {
        $(this).data('color', colors[index]);
        $(this).find('h2').css('color', colors[index]);
    });

    $('#results > div > ul > li').each(function() {
        let data = $.extend($(this).data(), $(this).closest('div').data());
        let coordinates = [
            data.longitude,
            data.latitude
        ];
        let color = data.color;

        addressesLayer.getSource().addFeature(new Feature({
            geometry: new Point(fromLonLat(coordinates)),
            properties: {
                address: data.address,
                color: color,
                provider: data.provider,
                streetnumber: data.streetnumber
            }
        }));

        $(this).on('click', function () {
            selectAddress(data.provider, data.address, true);
        });
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
            addressesLayer,
            locationLayer
        ],
        target: 'map',
        view: new View({
            center: [0, 0],
            zoom: 2
        })
    });

    window.app.map.getView().fit(addressesLayer.getSource().getExtent(), {
        maxZoom: 18,
        padding: [5,5,5,5]
    });

    window.app.map.on('singleclick', function (event) {
        let features = this.getFeaturesAtPixel(event.pixel);

        if (features !== null && features.length > 0) {
            let properties = features[0].getProperties().properties;

            selectAddress(properties.provider, properties.address);
        } else {
            let coordinates = window.app.map.getCoordinateFromPixel(event.pixel);
            let lnglat = toLonLat(coordinates);

            $('#results > div > ul > li.text-primary').removeClass('text-primary');
            $('#selection').text('');
            $('#btn-save').addClass('disabled').
                attr('href', '#');

            if (window.app.map.getView().getZoom() < 17) {
                alert('Please zoom in to set the location by clicking in the map !');
            } else {
                locationLayer.getSource().clear();
                locationLayer.getSource().addFeature(new Feature({
                    geometry: new Point(coordinates),
                }));

                $('#selection').text(
                    Math.round(lnglat[0] * 1000000) / 1000000 + ', ' +
                    Math.round(lnglat[1] * 1000000) / 1000000
                );
                $('#btn-save').removeClass('disabled').
                    attr('href', '?id=' + $('#btn-save').data('id') + '&longitude=' + lnglat[0] + '&latitude=' + lnglat[1]);
            }
        }
    });
}
