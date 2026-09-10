<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A captain's document, with a URL instead of a disk path.
 *
 * The card on `ADM-18` used to print `$document->type` and a status chip and stop
 * there — a row saying the word «national_id» over a photograph nobody could open. The
 * bytes were reachable the whole time (`GET /admin/driver-documents/{id}/file`); the
 * screen simply had no address for them, because the raw model was serialised and the
 * only path on it was `storage/app/...`, which means nothing to a browser.
 *
 * So `file_url` is the route, relative, exactly as `CaptainApplicationController` emits
 * its three: the dashboard prefixes `/api/bff/` and the bearer stays on the server
 * (D-69). `download_url` is the same route with the flag that flips the disposition —
 * spelled out rather than left for the client to append, so the two dashboards and the
 * app's console cannot each invent a different query string.
 *
 * `file` itself is **not** emitted. It is the private-disk layout, it is of no use to
 * any client, and doc 12 §5 is about identity documents specifically.
 */
class DriverDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'driver_id' => $this->driver_id,
            'type' => $this->type,
            'status' => $this->status,
            'note' => $this->note,
            'expires_at' => $this->expires_at?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),

            'file_url' => "admin/driver-documents/{$this->id}/file",
            'download_url' => "admin/driver-documents/{$this->id}/file?download=1",
            // Whether it can be drawn as a picture at all. A PDF scan is a real document
            // and the card has to offer the download instead of an `<img>` that renders
            // as a broken icon.
            'is_image' => in_array(
                strtolower(pathinfo((string) $this->file, PATHINFO_EXTENSION)),
                ['jpg', 'jpeg', 'png', 'webp', 'heic', 'gif'],
                true,
            ),
        ];
    }
}
