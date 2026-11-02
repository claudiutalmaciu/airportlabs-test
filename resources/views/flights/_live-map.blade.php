{{-- Live aircraft around the airport, from OpenSky state vectors. Expects $airport. --}}
@if ($airport->hasCoordinates())
    @include('partials.leaflet')

    <section class="card live-map-card">
        <div class="live-map-header">
            <h2><i class="fa-solid fa-satellite-dish" aria-hidden="true"></i> Live aircraft within {{ config('gates.live_map_radius_km') }} km</h2>
            <div class="live-map-status">
                <span id="live-map-status" class="muted">Loading…</span>
                <button type="button" id="live-map-refresh" class="icon-button" title="Refresh now" aria-label="Refresh live aircraft">
                    <i class="fa-solid fa-rotate-right" aria-hidden="true"></i>
                </button>
            </div>
        </div>
        <div id="live-map"
             data-url="{{ route('airports.aircraft', $airport->icao_code) }}"
             data-lat="{{ $airport->latitude }}"
             data-lon="{{ $airport->longitude }}"
             data-name="{{ $airport->label }}"></div>
    </section>

    @push('styles')
    <style>
        .live-map-card { margin-bottom: 20px; padding: 12px; }
        .live-map-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin: 0 4px 10px; }
        .live-map-header h2 { margin: 0; }
        .live-map-status { display: flex; align-items: center; gap: 10px; font-size: 13px; }
        .live-map-status .error { color: var(--bad); }
        #live-map { height: 380px; border-radius: 6px; z-index: 0; }
        .plane-marker.on-ground { color: var(--muted); font-size: 13px; }
        .plane-marker.known { color: var(--ok); }
    </style>
    @endpush

    @push('scripts')
    <script>
        $(function () {
            var $map = $('#live-map');
            var centre = [$map.data('lat'), $map.data('lon')];
            var map = L.map('live-map', { scrollWheelZoom: false, zoomSnap: 0.25 }).setView(centre, 9);
            var markers = {};
            var boxLayer = null;
            var refreshSeconds = 60;
            var lastLoaded = 0;
            var timer = null;

            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 18,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors · aircraft from <a href="https://opensky-network.org">OpenSky</a>'
            }).addTo(map);

            L.circleMarker(centre, { radius: 7, color: '#fff', weight: 2, fillColor: '#000', fillOpacity: 1 })
                .bindTooltip($map.data('name'))
                .addTo(map);

            function text(value) {
                return $('<span>').text(value).html();
            }

            // fa-plane points east, OpenSky headings are degrees clockwise from north.
            function icon(aircraft) {
                var classes = 'plane-marker' + (aircraft.on_ground ? ' on-ground' : '') + (aircraft.flight_url ? ' known' : '');

                return L.divIcon({
                    className: classes,
                    html: '<i class="fa-solid fa-plane" style="transform: rotate(' + ((aircraft.heading || 0) - 90) + 'deg)"></i>',
                    iconSize: [20, 20],
                    iconAnchor: [10, 10]
                });
            }

            function popup(aircraft) {
                var name = text(aircraft.callsign || aircraft.icao24);
                var title = aircraft.flight_url ? '<a href="' + aircraft.flight_url + '"><b>' + name + '</b></a>' : '<b>' + name + '</b>';
                var rows = [
                    ['Airline', aircraft.airline],
                    ['Altitude', aircraft.on_ground ? 'On the ground' : (aircraft.altitude_m === null ? null : Math.round(aircraft.altitude_m * 3.28084 / 100) * 100 + ' ft')],
                    ['Speed', aircraft.velocity_ms === null ? null : Math.round(aircraft.velocity_ms * 1.94384) + ' kt'],
                    ['Heading', aircraft.heading === null ? null : Math.round(aircraft.heading) + '°'],
                    ['Climb', aircraft.on_ground || !aircraft.vertical_rate_ms ? null : Math.round(aircraft.vertical_rate_ms * 196.85) + ' ft/min'],
                    ['Registered in', aircraft.origin_country],
                    ['ICAO24', aircraft.icao24]
                ];

                return title + '<dl>' + rows.filter(function (row) { return row[1] !== null && row[1] !== undefined; })
                    .map(function (row) { return '<dt>' + row[0] + '</dt><dd>' + text(row[1]) + '</dd>'; }).join('') + '</dl>'
                    + (aircraft.flight_url ? '' : '<div style="color:#667085;margin-top:4px">Not in this airport\'s flight list</div>');
            }

            // Update markers in place so open popups survive a refresh.
            function render(data) {
                var seen = {};

                if (!boxLayer && data.box) {
                    boxLayer = L.rectangle([[data.box.min_lat, data.box.min_lon], [data.box.max_lat, data.box.max_lon]], {
                        color: '#667085', weight: 1, dashArray: '4 4', fill: false, interactive: false
                    }).addTo(map);
                    map.fitBounds(boxLayer.getBounds(), { padding: [10, 10] });
                }

                $.each(data.aircraft, function (i, aircraft) {
                    seen[aircraft.icao24] = true;
                    var marker = markers[aircraft.icao24];

                    if (marker) {
                        marker.setLatLng([aircraft.latitude, aircraft.longitude]).setIcon(icon(aircraft)).setPopupContent(popup(aircraft));
                    } else {
                        markers[aircraft.icao24] = L.marker([aircraft.latitude, aircraft.longitude], { icon: icon(aircraft) })
                            .bindPopup(popup(aircraft))
                            .bindTooltip(text(aircraft.callsign || aircraft.icao24), { direction: 'top', offset: [0, -8] })
                            .addTo(map);
                    }
                });

                $.each(markers, function (icao24, marker) {
                    if (!seen[icao24]) {
                        map.removeLayer(marker);
                        delete markers[icao24];
                    }
                });
            }

            function status(data) {
                var parts = [];
                var airborne = data.aircraft.filter(function (a) { return !a.on_ground; }).length;

                parts.push(data.aircraft.length + ' aircraft (' + airborne + ' airborne)');
                if (data.fetched_at) {
                    parts.push('updated ' + new Date(data.fetched_at * 1000).toISOString().substring(11, 19) + ' UTC');
                }
                if (data.credits_remaining !== null && data.credits_remaining !== undefined) {
                    parts.push(data.credits_remaining + ' OpenSky credits left today');
                }

                var $status = $('#live-map-status').text(parts.join(' · '));
                if (data.error) {
                    $status.append(' · ', $('<span class="error">').text(data.error + (data.stale ? ' Showing the last positions.' : '')));
                }
            }

            function load() {
                clearTimeout(timer);

                $.getJSON($map.data('url')).done(function (data) {
                    lastLoaded = Date.now();
                    refreshSeconds = data.refresh_seconds || refreshSeconds;
                    render(data);
                    status(data);
                }).fail(function () {
                    $('#live-map-status').html('<span class="error">Could not load live aircraft.</span>');
                }).always(function () {
                    schedule();
                });
            }

            // Every refresh spends OpenSky credits, so stop polling while the tab is hidden.
            function schedule() {
                clearTimeout(timer);
                if (!document.hidden) {
                    timer = setTimeout(load, refreshSeconds * 1000);
                }
            }

            document.addEventListener('visibilitychange', function () {
                if (document.hidden) {
                    clearTimeout(timer);
                } else if (Date.now() - lastLoaded >= refreshSeconds * 1000) {
                    load();
                } else {
                    schedule();
                }
            });

            $('#live-map-refresh').on('click', load);

            load();
        });
    </script>
    @endpush
@endif
