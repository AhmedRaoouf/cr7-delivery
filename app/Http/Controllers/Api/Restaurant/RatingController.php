<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §6 — GET /restaurant/ratings. */
class RatingController extends Controller
{
    use FiltersLists;

    public function index(Request $request): JsonResponse
    {
        $restaurant = $request->user()->restaurant()->firstOrFail();
        $params = $this->listParams($request);
        $sort = $params->sort(['created_at', 'stars'], '-created_at');

        $query = $restaurant->ratings()->getQuery()
            ->where('is_hidden', false)
            ->with('user:id,name');

        $this->whereInList($query, 'ratings.stars', array_map('intval', $params->enum('stars', ['1', '2', '3', '4', '5'])));

        if (($commented = $params->bool('has_comment')) !== null) {
            $commented
                ? $query->whereNotNull('ratings.comment')->where('ratings.comment', '!=', '')
                : $query->where(fn ($q) => $q->whereNull('ratings.comment')->orWhere('ratings.comment', ''));
        }

        $this->whereBetweenDates($query, 'ratings.created_at', $params->dates());

        $ratings = $sort->apply($query, [])->paginate($params->perPage());

        return $this->paginated($ratings, sort: $sort);
    }
}
