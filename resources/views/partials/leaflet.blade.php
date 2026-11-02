{{-- Leaflet map library, loaded once per page however many maps include it. --}}
@pushOnce('styles', 'leaflet-css')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css" integrity="sha512-h9FcoyWjHcOcmEVkxOfTLnmZFWIH0iZhZT1H2TbOq55xssQGEJHEaIm+PgoUaZbRvQTNTluNOEfb1ZRy6D3BOw==" crossorigin="anonymous" referrerpolicy="no-referrer">
<style>
        .leaflet-popup-content { font: 13px/1.45 system-ui, -apple-system, "Segoe UI", sans-serif; margin: 10px 12px; }
        .leaflet-popup-content dl { display: grid; grid-template-columns: max-content 1fr; gap: 2px 12px; margin: 6px 0 0; }
        .leaflet-popup-content dt { color: #667085; }
        .leaflet-popup-content dd { margin: 0; }
        @media (prefers-color-scheme: dark) {
            .leaflet-tile-pane { filter: invert(1) hue-rotate(180deg) brightness(.85) contrast(.9); }
            .leaflet-container { background: #1a1e26; }
        }
        .plane-marker { color: var(--accent); font-size: 17px; text-shadow: 0 0 2px var(--surface), 0 0 2px var(--surface); }
        .plane-marker i { display: block; transition: transform .3s; }
</style>
@endPushOnce

@pushOnce('scripts', 'leaflet-js')
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js" integrity="sha512-puJW3E/qXDqYp9IfhAI54BJEaWIfloJ7JWs7OeD5i6ruC9JZL1gERT1wjtwXFlh7CjE7ZJ+/vcRZRkIYIb6p4g==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
@endPushOnce
