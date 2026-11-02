{{-- The path this flight flew, from OpenSky's tracks endpoint. Loaded after the page so it never slows it down. Expects $flight. --}}
@include('partials.leaflet')

<section class="card route-map-card">
    <div class="route-map-header">
        <h2><i class="fa-solid fa-route" aria-hidden="true"></i> Route flown</h2>
        <span id="route-map-status" class="muted">Loading track from OpenSky…</span>
    </div>
    <div id="route-map" data-url="{{ route('flights.track', $flight) }}"></div>
</section>

@push('styles')
<style>
    .route-map-card { margin-bottom: 16px; padding: 12px; }
    .route-map-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin: 0 4px 10px; font-size: 13px; }
    .route-map-header h2 { margin: 0; }
    #route-map { height: 340px; border-radius: 6px; z-index: 0; }
    #route-map.empty { height: auto; }
</style>
@endpush

@push('scripts')
<script>
    $(function () {
        var $map = $('#route-map');

        function text(value) {
            return $('<span>').text(value).html();
        }

        // Great-circle distance in km between two waypoints.
        function distance(a, b) {
            var rad = Math.PI / 180;
            var dLat = (b.latitude - a.latitude) * rad;
            var dLon = (b.longitude - a.longitude) * rad;
            var h = Math.pow(Math.sin(dLat / 2), 2) + Math.cos(a.latitude * rad) * Math.cos(b.latitude * rad) * Math.pow(Math.sin(dLon / 2), 2);

            return 12742 * Math.asin(Math.sqrt(h));
        }

        function duration(seconds) {
            var hours = Math.floor(seconds / 3600);
            var minutes = Math.round((seconds % 3600) / 60);

            return (hours ? hours + 'h ' : '') + minutes + 'm';
        }

        function summary(track) {
            var km = 0;
            for (var i = 1; i < track.path.length; i++) {
                km += distance(track.path[i - 1], track.path[i]);
            }

            var parts = [Math.round(km).toLocaleString('en') + ' km tracked', duration(track.end_time - track.start_time)];
            if (track.max_altitude_m) {
                parts.push('max ' + (Math.round(track.max_altitude_m * 3.28084 / 100) * 100).toLocaleString('en') + ' ft');
            }

            return parts.join(' · ');
        }

        function draw(track) {
            var map = L.map('route-map', { scrollWheelZoom: false, zoomSnap: 0.25 });
            var points = track.path.map(function (p) { return [p.latitude, p.longitude]; });
            var last = track.path[track.path.length - 1];

            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 18,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors · track from <a href="https://opensky-network.org">OpenSky</a>'
            }).addTo(map);

            var line = L.polyline(points, { color: getComputedStyle(document.documentElement).getPropertyValue('--accent').trim() || '#1f5fbf', weight: 3 }).addTo(map);

            L.circleMarker(points[0], { radius: 5, color: '#fff', weight: 2, fillColor: '#667085', fillOpacity: 1 })
                .bindTooltip('First seen ' + new Date(track.path[0].time * 1000).toISOString().substring(11, 16) + ' UTC')
                .addTo(map);

            // fa-plane points east, headings are degrees clockwise from north.
            L.marker(points[points.length - 1], {
                icon: L.divIcon({
                    className: 'plane-marker',
                    html: '<i class="fa-solid fa-plane" style="transform: rotate(' + ((last.heading || 0) - 90) + 'deg)"></i>',
                    iconSize: [20, 20],
                    iconAnchor: [10, 10]
                })
            }).bindTooltip('Last seen ' + new Date(last.time * 1000).toISOString().substring(11, 16) + ' UTC').addTo(map);

            $.each(track.airports, function (i, airport) {
                L.circleMarker([airport.latitude, airport.longitude], { radius: 7, color: '#fff', weight: 2, fillColor: '#000', fillOpacity: 1 })
                    .bindTooltip(text(airport.label) + (airport.role === 'departure' ? ' · departure' : ' · arrival'))
                    .addTo(map);
            });

            var bounds = line.getBounds();
            $.each(track.airports, function (i, airport) { bounds.extend([airport.latitude, airport.longitude]); });
            map.fitBounds(bounds, { padding: [20, 20] });
        }

        $.getJSON($map.data('url')).done(function (track) {
            if (!track.path.length) {
                $map.addClass('empty').html($('<p class="muted" style="margin:4px">').text(track.message || 'No track available.'));
                $('#route-map-status').text('');

                return;
            }

            $('#route-map-status').text(summary(track));
            draw(track);
        }).fail(function () {
            $map.addClass('empty').html('<p class="muted" style="margin:4px">Could not load the track.</p>');
            $('#route-map-status').text('');
        });
    });
</script>
@endpush
