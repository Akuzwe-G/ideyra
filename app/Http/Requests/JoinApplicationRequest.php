<?php

namespace App\Http\Requests;

use App\Support\ApplicationSchema;
use Illuminate\Foundation\Http\FormRequest;

class JoinApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ApplicationSchema::rules($this->route('type'));
    }

    public function attributes(): array
    {
        $labels = [];
        foreach (ApplicationSchema::fields($this->route('type')) as $key => $field) {
            $labels[$key] = $field[0];
        }

        return $labels;
    }
}
