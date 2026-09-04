<?php

namespace App\Http\Requests\Deployment;

use App\Support\ComposerBinary;
use App\Support\DeployAccess;
use App\Support\DeploySeeders;
use App\Support\DeployTasks;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RunTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return DeployAccess::granted($this);
    }

    /**
     * الردّ يُدفَق نصّاً، فترويسة Accept تبدأ بـ text/plain ولا يعدّها لارافل
     * طلبَ JSON. والأخطاء تعود JSON دائماً كي لا تُدفع صفحةٌ كاملة إلى
     * صندوق المخرجات.
     */
    protected function failedValidation(ValidatorContract $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'تعذّر تشغيل الأمر.',
            'errors' => $validator->errors()->toArray(),
        ], 422));
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'لا إذن لك بتشغيل الأوامر.',
        ], 403));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // المفتاح وحده يُقبل؛ الأمر نفسه مكتوبٌ في DeployTasks لا هنا.
            'task' => ['required', 'string', Rule::in(DeployTasks::keys())],
            'branch' => ['nullable', 'integer', 'exists:branches,id'],
            'seeders' => ['array'],
            'seeders.*' => ['string', Rule::in(collect(DeploySeeders::all())->pluck('name'))],
            'demoConfirmed' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'task.in' => 'أمرٌ غير معروف.',
            'seeders.*.in' => 'زارعٌ غير معروف.',
            'branch.exists' => 'فرعٌ غير موجود.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || $this->input('task') !== 'seed') {
                    return;
                }

                $seeders = $this->input('seeders', []);

                if ($seeders === []) {
                    $validator->errors()->add('seeders', 'اختر زارعاً واحداً على الأقل.');

                    return;
                }

                // القيود نفسها التي تحرس النشر: ما يتعذّر لا يمرّ، وما يخلق
                // بياناتٍ وهمية لا يمرّ بلا تأكيدٍ صريح — الشاشة وحدها لا
                // تكفي حارساً.
                if (($blocked = DeploySeeders::blocked($seeders, ComposerBinary::available())) !== []) {
                    $validator->errors()->add(
                        'seeders',
                        'زارعات متعذّرة هنا ('.implode('، ', $blocked).'): '.DeploySeeders::unavailableReason()
                    );

                    return;
                }

                if (! $this->boolean('demoConfirmed') && DeploySeeders::anyDemo($seeders)) {
                    $validator->errors()->add('seeders', 'اخترتَ زارعَ بياناتٍ تجريبية — يلزم تأكيدٌ صريح قبل تشغيله.');
                }
            },
        ];
    }
}
