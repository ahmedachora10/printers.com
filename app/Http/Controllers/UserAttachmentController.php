<?php

namespace App\Http\Controllers;

use App\Http\Requests\User\StoreUserAttachmentRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\Response;

/**
 * تاسك 86: مرفقات ملفّ الموظف. الملفات على القرص الخاص، ولا رابط مباشر لأيّها —
 * كلّ تنزيلٍ يمرّ من هنا بعد سياسة، تماماً كإيصالات التحويل.
 */
class UserAttachmentController extends Controller
{
    /** قائمة المرفقات كـJSON — النافذة تقرأها وتُحدّثها بعد كل رفعٍ أو حذف. */
    public function index(User $user): JsonResponse
    {
        Gate::authorize('viewAttachments', $user);

        return response()->json([
            'attachments' => $this->attachments($user),
        ]);
    }

    public function store(StoreUserAttachmentRequest $request, User $user): RedirectResponse
    {
        Gate::authorize('manageAttachments', $user);

        DB::transaction(function () use ($request, $user): void {
            foreach ($request->file('files') as $file) {
                $user->addMedia($file)->toMediaCollection(User::ATTACHMENTS_COLLECTION);
            }
        });

        return back()->with('success', 'تم رفع المرفقات بنجاح');
    }

    /** بثّ الملف بعد التحقّق أنه مرفقُ **هذا** المستخدم لا مرفقٌ بمعرّفٍ صحيح فحسب. */
    public function download(User $user, Media $media, Request $request): Response
    {
        Gate::authorize('viewAttachments', $user);

        return $this->ownedMedia($user, $media)->toInlineResponse($request);
    }

    public function destroy(User $user, Media $media): RedirectResponse
    {
        Gate::authorize('manageAttachments', $user);

        $this->ownedMedia($user, $media)->delete();

        return back()->with('success', 'تم حذف المرفق');
    }

    /**
     * المعرّف وحده لا يكفي: وسائطٌ تخصّ مستخدماً آخر (أو فاتورةً) لا تُبثّ من
     * مسار مستخدمٍ يملك المستدعي صلاحيةَ الاطّلاع عليه.
     */
    private function ownedMedia(User $user, Media $media): Media
    {
        abort_unless(
            $media->model_type === $user->getMorphClass()
                && (int) $media->model_id === $user->id
                && $media->collection_name === User::ATTACHMENTS_COLLECTION,
            404,
        );

        return $media;
    }

    /** @return list<array<string, mixed>> */
    private function attachments(User $user): array
    {
        return $user->getMedia(User::ATTACHMENTS_COLLECTION)
            ->map(fn (Media $media) => [
                'id' => $media->id,
                'name' => $media->file_name,
                'size' => $media->size,
                'mimeType' => $media->mime_type,
                'uploadedAt' => $media->created_at?->toISOString(),
                'downloadUrl' => route('users.attachments.download', [$user->id, $media->id]),
            ])
            ->values()
            ->all();
    }
}
