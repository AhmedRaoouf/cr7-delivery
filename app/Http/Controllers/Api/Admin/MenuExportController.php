<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Support\AdminScope;
use App\Support\BusinessTime;
use App\Support\MenuExporter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** «تصدير Excel» on `ADM-13` — the place's categories, dishes and options as one workbook. */
class MenuExportController extends Controller
{
    public function __invoke(Request $request, Restaurant $restaurant): StreamedResponse
    {
        AdminScope::abortUnlessVisible(AdminScope::applyToRestaurants(Restaurant::query(), $request->user()), $restaurant);

        return MenuExporter::stream($restaurant, BusinessTime::now()->format('Ymd-Hi'));
    }
}
