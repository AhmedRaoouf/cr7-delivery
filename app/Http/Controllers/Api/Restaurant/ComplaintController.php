<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Actions\Customer\CreateComplaintAction;
use App\Enums\ComplaintCategory;
use App\Enums\ComplaintStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreComplaintRequest;
use App\Http\Resources\Restaurant\ComplaintResource;
use App\Models\Complaint;
use App\Support\ApiResponse;
use App\Support\ComplaintNotifier;
use App\Support\Lists\ListSearch;
use App\Support\UploadedImages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §6.3 / doc 24 §4 — the two-sided complaints box: the restaurant may read and reply, nothing else. */
class ComplaintController extends Controller
{
    use FiltersLists;

    public function index(Request $request): JsonResponse
    {
        $restaurant = $request->user()->restaurant()->firstOrFail();
        $params = $this->listParams($request);
        $sort = $params->sort(['created_at', 'updated_at'], '-created_at');
        $tab = $params->enum('tab', ['about_me', 'mine'])[0] ?? 'about_me';

        $query = $tab === 'mine'
            ? Complaint::query()->where('user_id', $request->user()->id)->where('user_role', 'restaurant')
            : Complaint::query()->where('restaurant_id', $restaurant->id)->where('visible_to_restaurant', true);

        $this->whereInList($query, 'complaints.status', $params->enum('status', array_column(ComplaintStatus::cases(), 'value')));
        $this->whereInList($query, 'complaints.category', $params->enum('category', array_column(ComplaintCategory::cases(), 'value')));

        if ($tab === 'about_me' && ($unread = $params->bool('unread')) !== null) {
            $unread ? $query->whereNull('complaints.restaurant_first_read_at') : $query->whereNotNull('complaints.restaurant_first_read_at');
        }

        $this->whereBetweenDates($query, 'complaints.created_at', $params->dates());

        ListSearch::apply($query, $params->search(), [
            'refs' => ['ticket_number'],
            'names' => ['subject'],
        ]);

        $complaints = $sort->apply($query, [])->paginate($params->perPage());

        return $this->paginated($complaints, ComplaintResource::collection($complaints->getCollection()), sort: $sort);
    }

    public function show(Request $request, Complaint $complaint): JsonResponse
    {
        $this->ensureVisible($request, $complaint);

        return ApiResponse::success(new ComplaintResource($complaint->load(['user', 'order.items', 'messages'])));
    }

    public function addMessage(Request $request, Complaint $complaint): JsonResponse
    {
        $this->ensureVisible($request, $complaint);

        if (! $complaint->visible_to_restaurant) {
            throw new ApiException('not_mirrored', 'هذه الشكوى غير معروضة لك', 403);
        }

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'attachments' => ['nullable', 'array', 'max:3'],
            'attachments.*' => ['image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ]);

        $message = $complaint->messages()->create([
            'sender_id' => $request->user()->id,
            'sender_role' => 'restaurant',
            'visibility' => 'all',
            'message' => $validated['message'],
            'attachments' => UploadedImages::store($request, 'attachments', 'complaints') ?: null,
        ]);

        $complaint->update(['restaurant_replied_at' => now()]);

        app(ComplaintNotifier::class)->replied($complaint->fresh(['user', 'restaurant.owner']), $message);

        return ApiResponse::success($message, 'تم إرسال الرد', status: 201);
    }

    public function store(StoreComplaintRequest $request, CreateComplaintAction $action): JsonResponse
    {
        // `user_role` goes **in**, not on afterwards: the action seeds the thread's
        // first message with it, and a role patched after the fact would leave that
        // message signed «customer» on a ticket the restaurant opened.
        $complaint = $action->execute($request->user(), [
            ...$request->validated(),
            'order_id' => null,
            'user_role' => 'restaurant',
            'attachments' => UploadedImages::store($request, 'attachments', 'complaints'),
        ]);
        $complaint->update(['restaurant_id' => null, 'visible_to_restaurant' => false]);

        return ApiResponse::success($complaint->load('messages'), 'تم إرسال الشكوى بنجاح', status: 201);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $restaurant = $request->user()->restaurant()->firstOrFail();

        $count = Complaint::query()->where('restaurant_id', $restaurant->id)->where('visible_to_restaurant', true)->whereNull('restaurant_first_read_at')->count();

        return ApiResponse::success(['count' => $count]);
    }

    private function ensureVisible(Request $request, Complaint $complaint): void
    {
        $restaurant = $request->user()->restaurant;
        $isMine = $complaint->user_id === $request->user()->id && $complaint->user_role->value === 'restaurant';
        $isAboutMe = $restaurant && $complaint->restaurant_id === $restaurant->id && $complaint->visible_to_restaurant;

        abort_unless($isMine || $isAboutMe, 404);

        if ($isAboutMe && ! $complaint->restaurant_first_read_at) {
            $complaint->update(['restaurant_first_read_at' => now()]);
        }
    }
}
