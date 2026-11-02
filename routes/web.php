<?php

use App\Http\Controllers\AirportController;
use App\Http\Controllers\FlightController;
use App\Http\Controllers\GateController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

// Root - redirect to the Flights page
Route::redirect('/', '/flights');

// Flights
Route::get('/flights', [FlightController::class, 'index'])->name('flights.index');
Route::get('/flights/list', [FlightController::class, 'flightsList'])->name('flights.list');
Route::get('/flights/{flight}', [FlightController::class, 'show'])->name('flights.show');
Route::get('/flights/{flight}/track', [FlightController::class, 'track'])->name('flights.track');

// Gates
Route::get('/gates', [GateController::class, 'index'])->name('gates.index');
Route::get('/gates/list', [GateController::class, 'gatesList'])->name('gates.list');
Route::get('/gates/{gate}', [GateController::class, 'show'])->name('gates.show');
Route::post('/gates/{gate}/restrictions/create', [GateController::class, 'createRestriction'])->name('gates.restrictions.create');

// Airports
Route::get('/airports', [AirportController::class, 'index'])->name('airports.index');
Route::get('/airports/list', [AirportController::class, 'airportsList'])->name('airports.list');
Route::get('/airports/{airport}', [AirportController::class, 'show'])->whereNumber('airport')->name('airports.show');
Route::get('/airports/{airport:icao_code}/aircraft', [AirportController::class, 'aircraft'])->name('airports.aircraft');

// Reports
Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
Route::get('/reports/list', [ReportController::class, 'reportsList'])->name('reports.list');
Route::get('/reports/{report}', [ReportController::class, 'show'])->whereNumber('report')->name('reports.show');
