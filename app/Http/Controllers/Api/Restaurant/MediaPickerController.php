<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Enums\MediaField;
use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use App\Support\Media\MediaPicker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * doc 71 — `GET /restaurant/media/picker?field=product_image`: the pictures this place may
 * put in that field. Its own current ones, the shared library, what was assigned to it or
 * to a group it belongs to — never more, and never a picture of another usage.
 */
class MediaPickerController extends Controller
{
    use FiltersLists;

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['field' => ['required', Rule::in(MediaField::values())]]);

        $place = $request->user()->restaurant()->firstOrFail();
        $params = $this->listParams($request);

        $result = MediaPicker::list($request->user(), MediaField::from($data['field']), $place, [
            'q' => $params->search(),
            'tag' => $params->string('tag'),
            'per_page' => $this->clampedPerPage($request, 24),
            'page' => (int) $request->input('page', 1),
        ]);

        return $this->paginated($result['page'], $result['items']);
    }
}
