<meta name="reverb-config" content="{{ json_encode([
    'key' => config('broadcasting.connections.reverb.key'),
    'host' => config('broadcasting.connections.reverb.options.host'),
    'port' => (int) config('broadcasting.connections.reverb.options.port'),
    'scheme' => config('broadcasting.connections.reverb.options.scheme'),
    'authEndpoint' => '/broadcasting/auth',
]) }}">
