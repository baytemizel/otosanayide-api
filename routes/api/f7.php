<?php

use App\Http\Controllers\Api\Closed\ClosedFeatureController as Closed;
use Illuminate\Support\Facades\Route;

foreach (Closed::SPECS as $key => $spec) {
    [$method, $path] = explode(' ', $key, 2);
    $route = Route::match([strtolower($method)], $path, Closed::class);
    if (isset($spec['origin'])) {
        $route->middleware('origin:'.$spec['origin']);
    }
}
foreach (array_keys(Closed::OPTIONS) as $path) {
    Route::options($path, [Closed::class, 'options'])->middleware('origin:mobile');
}
