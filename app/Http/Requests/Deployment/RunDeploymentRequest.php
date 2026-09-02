<?php

namespace App\Http\Requests\Deployment;

use App\Support\DeployAccess;
use App\Support\DeploySeeders;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RunDeploymentRequest extends FormRequest
{
    /**
     * الشاشة تعرض نموذج المفتاح لمن لا إذن له، أما هذا المسار فيُنفِّذ، فيردّ
     * 403 صريحاً: لا معنى لعرض نموذجٍ على طلبٍ يريد أن يبدأ نشراً.
     */
    public function authorize(): bool
    {
        return DeployAccess::granted($this);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'dryRun' => ['boolean'],
            'branch' => ['nullable', 'string', 'max:100', 'regex:/^[\w.\/-]+$/'],
            'assets' => ['nullable', 'url', 'max:2048'],

            'options' => ['array'],
            'options.pull' => ['boolean'],
            'options.composer' => ['boolean'],
            'options.backup' => ['boolean'],
            'options.migrate' => ['boolean'],
            'options.seed' => ['boolean'],
            'options.maintenance' => ['boolean'],
            'options.health' => ['boolean'],
            'options.rollback' => ['boolean'],

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
            'seeders.*.in' => 'زارعٌ غير معروف.',
            'branch.regex' => 'اسم فرعٍ غير صالح.',
            'assets.url' => 'رابط الأصول غير صالح.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                // أسماء الزارعات تُطابَق بقاعدة in أولاً؛ فإن سقط منها اسمٌ
                // فلا معنى لسؤال المجهول أتجريبيٌّ هو.
                if ($validator->errors()->isNotEmpty() || ! $this->boolean('options.seed', true)) {
                    return;
                }

                $seeders = $this->input('seeders', []);

                // المتعذّر لا يُنقذه تأكيد: بلا faker يقع المصنع في خطأٍ
                // قاتل بعد الهجرات، فيُردّ الطلب قبل أن يبدأ شيء.
                if (($blocked = DeploySeeders::blocked($seeders)) !== []) {
                    $validator->errors()->add(
                        'seeders',
                        'زارعات متعذّرة هنا ('.implode('، ', $blocked).'): '.DeploySeeders::unavailableReason()
                    );

                    return;
                }

                // التأكيد الثاني يُطلب من الخادم لا من الشاشة وحدها: زرُّ
                // «تأكيد» في المتصفّح يُتجاوَز بطلبٍ مصنوع، وزارعُ بياناتٍ
                // تجريبية على فرعٍ عامل لا يُنقض بعد وقوعه.
                if (! $this->boolean('demoConfirmed') && DeploySeeders::anyDemo($seeders)) {
                    $validator->errors()->add('seeders', 'اخترتَ زارعَ بياناتٍ تجريبية — يلزم تأكيدٌ صريح قبل تشغيله.');
                }
            },
        ];
    }
}
